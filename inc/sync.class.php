<?php
class PluginRgsupervisionSync extends CommonDBTM {

    public static $rightname = 'config';

    private static $prio = [
        'critical' => ['urgency' => 5, 'impact' => 5],
        'medium'   => ['urgency' => 3, 'impact' => 3],
        'low'      => ['urgency' => 2, 'impact' => 2],
    ];

    private static $critLabel = [
        'critical' => 'CRITIQUE', 'medium' => 'MOYENNE', 'low' => 'BASSE',
    ];

    private $cfg = [];
    private $stats = [];
    private $entityCache   = null;
    private $contractCache = null;
    private $categoryCache = null;

    // ── Tâche cron GLPI ───────────────────────────────────────────────────
    public static function cronCronSyncRGAlerts(CronTask $task) {
        $sync  = new self();
        $stats = $sync->run();
        $task->addVolume($stats['nouveaux'] + $stats['relances'] + $stats['resolus']);
        return 1;
    }

    // ── Run principal ──────────────────────────────────────────────────────
    public function run() {
        $this->cfg   = PluginRgsupervisionConfig::all();
        $this->stats = ['collectes'=>0,'nouveaux'=>0,'relances'=>0,'resolus'=>0,
                        'infos'=>0,'reouvertures'=>0,'clotures'=>0,
                        'ignores_bc'=>0,'nettoyes'=>0,'sans_chgt'=>0,'ignores_clos'=>0];
        $errors  = [];
        $start   = microtime(true);

        $token   = $this->cfg['rg_api_token']   ?? '';
        $url     = $this->cfg['rg_api_url']      ?? 'https://api.rg-supervision.com';
        $rootId  = $this->cfg['rg_root_node_id'] ?? '';
        $timeout = (int)($this->cfg['rg_request_timeout'] ?? 30);

        if (!$token || !$rootId) {
            $this->saveLog(microtime(true) - $start, 'Token ou nœud racine non configuré');
            return $this->stats;
        }

        $filters = [
            'state'       => 'all',
            'criticality' => [
                'critical' => !empty($this->cfg['filter_critical']),
                'medium'   => !empty($this->cfg['filter_medium']),
                'low'      => !empty($this->cfg['filter_low']),
            ],
            'agent_types' => explode(',', $this->cfg['filter_agent_types'] ?? 's,w,e'),
        ];

        $rg      = new PluginRgsupervisionRgapiclient($token, $url, $timeout);
        $tickets = $rg->collectAll($rootId, $filters);
        $this->stats['collectes'] = count($tickets);

        foreach ($tickets as $t) {
            try { $this->processTicket($t); }
            catch (Exception $e) { $errors[] = $e->getMessage(); }
        }

        $maxDays = ($this->cfg['cleanup_resolved_days'] ?? '') !== '' ? (int)$this->cfg['cleanup_resolved_days'] : null;
        $this->stats['nettoyes'] = PluginRgsupervisionSyncstate::rgCleanup($maxDays);

        $this->saveLog(microtime(true) - $start, $errors ? implode("\n", $errors) : null);
        return $this->stats;
    }

    // ── Traitement d'un ticket ─────────────────────────────────────────────
    private function processTicket($t) {
        $did     = $t['displayId'] ?? null;
        if (!$did) return;

        $title = $t['title'] ?? 'Sans titre';
        if (stripos(ltrim($title), 'BC') === 0) { $this->stats['ignores_bc']++; return; }

        $isClosedRg   = !empty($t['isClosed']) || !empty($t['isRecovered']);
        $currentRaise = (int)($t['raiseCount'] ?? 0);
        $crit         = strtolower($t['criticality'] ?? 'medium');
        $nodeName     = $t['node_name_sync'] ?? '';
        $prio         = self::$prio[$crit] ?? self::$prio['medium'];

        $state = PluginRgsupervisionSyncstate::rgGetByRgId($did);

        if ($state !== null) {
            $gtid      = (int)$state['glpi_ticket_id'];
            $lastRaise = (int)$state['raise_count'];
            $glStatus  = (int)$state['glpi_status'];

            $realStatus = $this->ticketStatus($gtid);
            if ($realStatus !== null && $realStatus !== $glStatus) {
                if (in_array($glStatus, [5,6]) && !in_array($realStatus, [5,6])) {
                    $this->stats['reouvertures']++;
                    PluginRgsupervisionSyncstate::rgUpdate($did, ['glpi_status'=>$realStatus,'info_cloture_sent'=>0]);
                } elseif ($realStatus === 6 && !in_array($glStatus, [5,6])) {
                    $this->stats['clotures']++;
                    PluginRgsupervisionSyncstate::rgUpdate($did, ['glpi_status'=>6]);
                } else {
                    PluginRgsupervisionSyncstate::rgUpdate($did, ['glpi_status'=>$realStatus]);
                }
                $glStatus = $realStatus;
            }

            if ($isClosedRg && !in_array($glStatus, [5,6])) {
                if ($glStatus === 1) {
                    if ($this->resolveTicket($gtid)) {
                        PluginRgsupervisionSyncstate::rgUpdate($did, ['glpi_status'=>5]);
                        $this->stats['resolus']++;
                    }
                } else {
                    if (!$state['info_cloture_sent']) {
                        if ($this->createTask($gtid, $this->contentClosed($t))) {
                            PluginRgsupervisionSyncstate::rgUpdate($did, ['info_cloture_sent'=>1]);
                            $this->stats['infos']++;
                        }
                    } else { $this->stats['sans_chgt']++; }
                }
            } elseif (!$isClosedRg && $currentRaise > $lastRaise && !in_array($glStatus, [5,6])) {
                if ($this->createTask($gtid, $this->contentRelance($t, $currentRaise))) {
                    PluginRgsupervisionSyncstate::rgUpdate($did, ['raise_count'=>$currentRaise]);
                    $this->stats['relances']++;
                }
            } else { $this->stats['sans_chgt']++; }

        } elseif (!$isClosedRg) {
            $entityId   = $this->resolveEntity($nodeName);
            $categoryId = $this->resolveCategory($entityId, $title);

            $newId = $this->createTicket(
                "RG - [{$did}] - {$title}",
                $this->contentTicket($t),
                $entityId, $prio['urgency'], $prio['impact'], $categoryId
            );
            if ($newId) {
                PluginRgsupervisionSyncstate::rgCreate($did, $newId, [
                    'raise_count' => $currentRaise,
                    'node_name'   => $nodeName,
                    'title'       => $title,
                ]);
                $this->stats['nouveaux']++;
            }
        } else { $this->stats['ignores_clos']++; }
    }

    // ── Actions GLPI ──────────────────────────────────────────────────────
    private function ticketStatus($id) {
        $t = new Ticket();
        return $t->getFromDB((int)$id) ? (int)$t->fields['status'] : null;
    }

    private function createTicket($title, $content, $entityId, $urgency, $impact, $categoryId = null) {
        $input = [
            'name'              => $title,
            'content'           => $content,
            'entities_id'       => (int)$entityId,
            'urgency'           => (int)$urgency,
            'impact'            => (int)$impact,
            'type'              => Ticket::INCIDENT_TYPE,
            'requesttypes_id'   => (int)($this->cfg['ticket_source'] ?? 10),
            '_groups_id_assign' => [(int)($this->cfg['ticket_assign_group'] ?? 9)],
            'status'            => Ticket::INCOMING,
        ];
        if ($categoryId !== null) $input['itilcategories_id'] = (int)$categoryId;
        $t  = new Ticket();
        $id = $t->add($input);

        if ($id && $id > 0) {
            // GLPI force le statut "En cours" quand un groupe est assigné.
            // On force le retour à "Nouveau" (INCOMING) après la création.
            $t->update(['id' => (int)$id, 'status' => Ticket::INCOMING]);
            return (int)$id;
        }
        return null;
    }

    private function createTask($ticketId, $content) {
        $task = new TicketTask();
        $id   = $task->add([
            'tickets_id' => (int)$ticketId,
            'content'    => $content,
            'state'      => Planning::TODO,
            'actiontime' => 0,
            'is_private' => 1,
        ]);
        return $id && $id > 0;
    }

    private function resolveTicket($ticketId) {
        $s = new ITILSolution();
        $s->add(['itemtype'=>'Ticket','items_id'=>(int)$ticketId,
            'content'=>'<p>*-------<br>Résolu par la supervision<br>-------*</p>',
            'status'=>CommonITILValidation::ACCEPTED]);
        $t = new Ticket();
        return (bool)$t->update(['id'=>(int)$ticketId,'status'=>Ticket::SOLVED]);
    }

    // ── Résolution entité par TAG ─────────────────────────────────────────
    private function resolveEntity($nodeName) {
        global $DB;
        $default = (int)($this->cfg['ticket_default_entity'] ?? 0);
        if (!$nodeName) return $default;
        if ($this->entityCache === null) {
            $this->entityCache = [];
            foreach ($DB->request(['FROM'=>'glpi_entities','WHERE'=>['is_deleted'=>0]]) as $row) {
                $this->entityCache[] = $row;
            }
        }
        $norm = strtoupper(trim($nodeName));
        foreach ($this->entityCache as $e) {
            if (strtoupper(trim((string)($e['tag']??''))) === $norm) return (int)$e['id'];
        }
        foreach ($this->entityCache as $e) {
            if (strtoupper(trim((string)($e['name']??''))) === $norm) return (int)$e['id'];
        }
        foreach ($this->entityCache as $e) {
            if (strpos(strtoupper((string)($e['name']??'')), $norm) !== false) return (int)$e['id'];
        }
        return $default;
    }

    // ── Résolution catégorie par contrats ─────────────────────────────────
    private function resolveCategory($entityId, $title) {
        global $DB;

        if ($this->contractCache === null) {
            $this->contractCache = [];
            $stateLabels = [];
            foreach ($DB->request(['FROM'=>'glpi_states']) as $s) {
                $stateLabels[(int)$s['id']] = strtolower((string)($s['name']??''));
            }
            $today = date('Y-m-d');
            foreach ($DB->request(['FROM'=>'glpi_contracts','WHERE'=>['is_deleted'=>0]]) as $c) {
                $label = $stateLabels[(int)($c['states_id']??0)] ?? '';
                if ($label && strpos($label, 'actif') === false) continue;
                $begin = $c['begin_date'] ?? null;
                $end   = $c['end_date']   ?? null;
                if ($begin && substr($begin,0,10) > $today) continue;
                if ($end   && substr($end,0,10)   < $today) continue;
                $this->contractCache[] = $c;
            }
        }

        if ($this->categoryCache === null) {
            $this->categoryCache = [];
            foreach ($DB->request(['FROM'=>'glpi_itilcategories']) as $cat) {
                $this->categoryCache[] = $cat;
            }
        }

        $entityContracts = [];
        foreach ($this->contractCache as $c) {
            if ((int)$c['entities_id'] === (int)$entityId) {
                $entityContracts[] = strtolower((string)($c['name']??''));
            }
        }

        $titleLow = strtolower($title);
        $rules    = json_decode($this->cfg['contract_rules'] ?? '[]', true) ?: [];

        foreach ($rules as $rule) {
            $prefix  = strtolower($rule['prefix']  ?? '');
            $keyword = strtolower($rule['keyword'] ?? '');
            if ($keyword && strpos($titleLow, $keyword) === false) continue;
            foreach ($entityContracts as $cname) {
                if (strpos($cname, $prefix) === 0) return $this->catByPath($rule['category'] ?? '');
            }
        }

        return $this->catByPath($this->cfg['default_category_path'] ?? 'Prestations > Hors Contrat');
    }

    private function catByPath($path) {
        if (!$path) return null;
        $norm = $this->normPath($path);
        foreach ($this->categoryCache as $cat) {
            if ($this->normPath((string)($cat['completename']??'')) === $norm) return (int)$cat['id'];
        }
        return null;
    }

    private function normPath($s) {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s*>\s*/', ' > ', $s);
        return strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','ù'=>'u','û'=>'u','ô'=>'o','î'=>'i','ç'=>'c','É'=>'e','È'=>'e','Ê'=>'e','À'=>'a','Ç'=>'c']);
    }

    // ── Contenus ──────────────────────────────────────────────────────────
    private function contentTicket($t) {
        $crit = strtolower($t['criticality']??'medium');
        $lbl  = self::$critLabel[$crit] ?? strtoupper($crit);
        $atm  = ['s'=>'Serveur','w'=>'Workstation','e'=>'Equipement'];
        $at   = $atm[strtolower($t['agentType']??'')] ?? ($t['agentType']??'N/A');
        $yn   = function($v){ return $v ? 'Oui' : 'Non'; };
        $lines = [
            'ID RG         : '.($t['displayId']??'N/A'),
            'Noeud client  : '.($t['node_name_sync']??'N/A'),
            'Agent         : '.($t['agentName']??'N/A').' (ID : '.($t['agentId']??'N/A').')',
            'Type agent    : '.$at,
            'Criticite     : '.$lbl,'',
            'Acquitte      : '.$yn($t['isAcquitted']??false),
            'En sourdine   : '.$yn($t['isMuted']??false),
            'Resolu (RG)   : '.$yn($t['isRecovered']??false),
            'Relances      : '.($t['raiseCount']??0),'',
            'Alerte        : '.($t['title']??'Sans titre'),
            'Date          : '.date('d/m/Y \a H:i:s'),'',
        ];
        return implode('<br>', $lines).'<b>Demandeur =</b> supervision';
    }

    private function contentRelance($t, $n) {
        $lbl = self::$critLabel[strtolower($t['criticality']??'medium')] ?? 'N/A';
        return implode("\n", [
            "RELANCE RG - Compteur : {$n}",
            'Detectee le '.date('d/m/Y \a H:i:s'), '',
            'ID RG     : '.($t['displayId']??'N/A'),
            'Noeud     : '.($t['node_name_sync']??'N/A'),
            'Agent     : '.($t['agentName']??'N/A'),
            'Criticite : '.$lbl, '',
            'Demandeur = supervision',
        ]);
    }

    private function contentClosed($t) {
        $lbl = self::$critLabel[strtolower($t['criticality']??'medium')] ?? 'N/A';
        return implode("\n", [
            'ALERTE RG RESOLUE - Action manuelle requise',
            'Detectee le '.date('d/m/Y \a H:i:s'), '',
            "L'alerte RG est maintenant resolue/fermee.",
            "Le ticket etant en cours de traitement, il n'a pas ete resolu automatiquement.",
            'Merci de verifier et de cloturer ce ticket si le probleme est resolu.', '',
            'ID RG     : '.($t['displayId']??'N/A'),
            'Noeud     : '.($t['node_name_sync']??'N/A'),
            'Agent     : '.($t['agentName']??'N/A'),
            'Criticite : '.$lbl, '',
            'Demandeur = supervision',
        ]);
    }

    // ── Log ───────────────────────────────────────────────────────────────
    private function saveLog($duration, $errors = null) {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_rgsupervision_logs')) return;
        $s = $this->stats;
        $DB->insert('glpi_plugin_rgsupervision_logs', [
            'date_run'  => date('Y-m-d H:i:s'),
            'duration'  => round((float)$duration, 2),
            'collectes' => $s['collectes'],
            'nouveaux'  => $s['nouveaux'],
            'relances'  => $s['relances'],
            'resolus'   => $s['resolus'],
            'errors'    => $errors,
        ]);
        $cutoff = date('Y-m-d H:i:s', strtotime('-90 days'));
        $DB->delete('glpi_plugin_rgsupervision_logs', [['date_run'=>['<',$cutoff]]]);
    }
}
