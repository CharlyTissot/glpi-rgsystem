<?php
include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);
Html::header('RG Supervision Sync', $_SERVER['PHP_SELF']);

$cfg   = PluginRgsupervisionConfig::all();
$rules = json_decode($cfg['contract_rules'] ?? '[]', true) ?: [];

if (isset($_GET['msg'])) {
    $msgs = ['saved'=>'Configuration enregistrée.','synced'=>'Synchronisation lancée.'];
    if (isset($msgs[$_GET['msg']])) echo '<div class="alert alert-success" style="margin:10px">'.$msgs[$_GET['msg']].'</div>';
}

echo '<div style="padding:15px">';
echo '<h2>🔄 RG Supervision Sync — Configuration</h2>';

// Bouton sync manuelle
echo '<form method="POST" action="config.form.php" style="margin-bottom:15px">';
echo '<input type="hidden" name="_glpi_csrf_token" value="'.Session::getNewCSRFToken().'">';
echo '<button type="submit" name="run_sync" class="btn btn-warning">▶ Lancer une synchronisation maintenant</button>';
echo '</form>';

// Formulaire config
echo '<form method="POST" action="config.form.php">';
echo '<input type="hidden" name="_glpi_csrf_token" value="'.Session::getNewCSRFToken().'">';
echo '<table class="tab_cadre_fixe">';

// ── API RG ──
echo '<tr class="headerRow"><th colspan="2">🔌 API RG Supervision</th></tr>';
$rows = [
    ['Token API',         'rg_api_token',       'password', 60],
    ['URL API',           'rg_api_url',          'text',     60],
    ['ID Nœud racine',    'rg_root_node_id',     'text',     20],
    ['Timeout HTTP (s)',  'rg_request_timeout',  'number',    8],
    ['Types agents',      'filter_agent_types',  'text',     20],
];
$alt = 1;
foreach ($rows as $r) {
    $cls = $alt++%2 ? 'tab_bg_1' : 'tab_bg_2';
    $v   = htmlspecialchars($cfg[$r[1]] ?? '');
    $w   = $r[2]==='number' ? ' style="width:80px"' : '';
    echo "<tr class='$cls'><td style='width:35%'><b>{$r[0]}</b></td><td><input type='{$r[2]}' name='{$r[1]}' value='$v' size='{$r[3]}'$w></td></tr>";
}

echo '<tr class="tab_bg_1"><td><b>Criticités</b></td><td>';
foreach (['filter_critical'=>'Critique','filter_medium'=>'Moyenne','filter_low'=>'Basse'] as $k=>$l) {
    $chk = ($cfg[$k]??'0') ? ' checked' : '';
    echo "<label><input type='checkbox' name='$k' value='1'$chk> $l</label> &nbsp;";
}
echo '</td></tr>';

// ── GLPI ──
echo '<tr class="headerRow"><th colspan="2">🎫 Paramètres GLPI</th></tr>';
$rows2 = [
    ['ID Source demande',    'ticket_source',         'number', 8],
    ['ID Groupe assigné',    'ticket_assign_group',   'number', 8],
    ['ID Entité par défaut', 'ticket_default_entity', 'number', 8],
    ['Nettoyage résolus (j)','cleanup_resolved_days', 'number', 8],
];
$alt = 1;
foreach ($rows2 as $r) {
    $cls = $alt++%2 ? 'tab_bg_1' : 'tab_bg_2';
    $v   = (int)($cfg[$r[1]] ?? 0);
    echo "<tr class='$cls'><td><b>{$r[0]}</b></td><td><input type='number' name='{$r[1]}' value='$v' style='width:80px'></td></tr>";
}

// ── Synchronisation ──
echo '<tr class="headerRow"><th colspan="2">⏱️ Synchronisation</th></tr>';
$cronFreq = (int)($cfg['cron_frequency'] ?? 10);
$cronStatus = '';
$cronTask = new CronTask();
$cronFound = $cronTask->getFromDBbyName('PluginRgsupervisionSync', 'cronSyncRGAlerts');
if ($cronFound && $cronTask->getID()) {
    $freq = (int)($cronTask->fields['frequency'] ?? 0);
    $freqMin = $freq > 0 ? round($freq / 60) : 0;
    $lastrun = $cronTask->fields['lastrun'] ?? null;
    $last    = $lastrun ? Html::convDateTime($lastrun) : 'Jamais';
    $cronStatus = "<span style='color:green'>✓ Tâche active — fréquence : {$freqMin} min — dernière exécution : {$last}</span>";
} else {
    $cronStatus = "<span style='color:orange'>⚠ Tâche cron non enregistrée — enregistrer la configuration pour la créer</span>";
}
echo "<tr class='tab_bg_1'><td><b>Fréquence (minutes)</b></td><td>";
echo "<input type='number' name='cron_frequency' value='{$cronFreq}' min='1' max='1440' style='width:80px'>";
echo " <small>minutes entre chaque synchronisation automatique</small>";
echo "</td></tr>";
echo "<tr class='tab_bg_2'><td><b>État de la tâche cron</b></td><td>{$cronStatus}</td></tr>";

// ── Contrats ──
echo '<tr class="headerRow"><th colspan="2">📋 Contrats → Catégories</th></tr>';
$dcpath = htmlspecialchars($cfg['default_category_path'] ?? 'Prestations > Hors Contrat');
echo "<tr class='tab_bg_1'><td><b>Catégorie par défaut</b></td><td><input type='text' name='default_category_path' value='$dcpath' size='60'></td></tr>";

echo '<tr class="tab_bg_2"><td colspan="2">';
echo '<b>Règles contrat → catégorie</b><br>';
echo '<table id="rt" style="width:100%;border-collapse:collapse">';
echo '<thead><tr><th style="padding:4px">#</th><th>Préfixe contrat</th><th>Mot-clé titre</th><th>Chemin catégorie GLPI</th><th></th></tr></thead>';
echo '<tbody id="rb">';
foreach ($rules as $i => $rule) {
    $p = htmlspecialchars($rule['prefix']??'');
    $k = htmlspecialchars($rule['keyword']??'');
    $c = htmlspecialchars($rule['category']??'');
    echo "<tr><td style='padding:3px'>".($i+1)."</td>";
    echo "<td><input type='text' class='rp' value='$p' style='width:100%'></td>";
    echo "<td><input type='text' class='rk' value='$k' style='width:100%'></td>";
    echo "<td><input type='text' class='rc' value='$c' style='width:100%'></td>";
    echo "<td><button type='button' onclick='this.closest(\"tr\").remove();rn()'>✕</button></td></tr>";
}
echo '</tbody></table>';
echo '<button type="button" onclick="ra()" style="margin-top:6px">+ Règle</button>';
echo '<input type="hidden" name="contract_rules_json" id="rj">';
echo '</td></tr>';

echo '<tr><td colspan="2" class="center" style="padding:12px">';
echo '<input type="submit" name="save" value="💾 Enregistrer" class="btn btn-primary" onclick="return rs()">';
echo '</td></tr></table></form>';

// ── Derniers runs ──
global $DB;
if ($DB->tableExists('glpi_plugin_rgsupervision_logs')) {
    echo '<h3 style="margin-top:20px">Dernières synchronisations</h3>';
    echo '<table class="tab_cadre_fixe">';
    echo '<tr class="headerRow"><th>Date</th><th>Durée</th><th>Collectés</th><th>Nouveaux</th><th>Relances</th><th>Résolus</th><th>Erreurs</th></tr>';
    $alt = 1;
    foreach ($DB->request(['FROM'=>'glpi_plugin_rgsupervision_logs','ORDER'=>['date_run DESC'],'LIMIT'=>10]) as $row) {
        $cls = $alt++%2?'tab_bg_1':'tab_bg_2';

        if ($row['errors']) {
            $errLines = array_filter(explode("\n", $row['errors']));
            $errCount = count($errLines);
            $runId    = 'rg-err-' . $row['id'];
            $errHtml  = "<span style=\"color:red;cursor:pointer;font-weight:bold\" onclick=\"rgToggle('" . $runId . "')\">";
            $errHtml .= "&#9888; " . $errCount . " erreur" . ($errCount > 1 ? "s" : "") . " &#9660;</span>";
            $errHtml .= "<div id=\"" . $runId . "\" style=\"display:none;margin-top:6px;padding:8px;background:#fff3f3;border:1px solid #f5c6cb;border-radius:4px;font-size:12px;white-space:pre-wrap;max-width:600px\">";
            foreach ($errLines as $line) {
                $errHtml .= "<div style=\"padding:2px 0;border-bottom:1px solid #f5c6cb\">&#9888; " . htmlspecialchars(trim($line)) . "</div>";
            }
            $errHtml .= "</div>";
            $err = $errHtml;
        } else {
            $err = "<span style=\"color:green\">&#10003;</span>";
        }

        echo "<tr class='$cls'><td>".Html::convDateTime($row['date_run'])."</td>";
        echo "<td>".round((float)$row['duration'],1)."s</td>";
        echo "<td>{$row['collectes']}</td><td><b>{$row['nouveaux']}</b></td>";
        echo "<td>{$row['relances']}</td><td>{$row['resolus']}</td><td>$err</td></tr>";
    }
    echo '</table>';
}

echo '</div>';
echo '<script>
function rgToggle(id){var el=document.getElementById(id);el.style.display=el.style.display==="none"?"block":"none";}
function ra(){var b=document.getElementById("rb"),n=b.rows.length+1,r=document.createElement("tr");
r.innerHTML="<td style=\'padding:3px\'>"+n+"</td><td><input type=\'text\' class=\'rp\' style=\'width:100%\'></td><td><input type=\'text\' class=\'rk\' style=\'width:100%\'></td><td><input type=\'text\' class=\'rc\' style=\'width:100%\'></td><td><button type=\'button\' onclick=\'this.closest(\"tr\").remove();rn()\'>✕</button></td>";
b.appendChild(r);}
function rn(){document.querySelectorAll("#rb tr").forEach(function(r,i){r.cells[0].textContent=i+1;});}
function rs(){var d=[],rows=document.querySelectorAll("#rb tr");
rows.forEach(function(r){d.push({prefix:r.querySelector(".rp").value.trim(),keyword:r.querySelector(".rk").value.trim(),category:r.querySelector(".rc").value.trim()});});
document.getElementById("rj").value=JSON.stringify(d);return true;}
</script>';

Html::footer();
