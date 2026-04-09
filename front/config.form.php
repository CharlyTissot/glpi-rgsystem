<?php
include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);

if (isset($_POST['save'])) {
    $fields = ['rg_api_token','rg_api_url','rg_root_node_id','rg_request_timeout',
               'filter_agent_types','ticket_source','ticket_assign_group',
               'ticket_default_entity','cleanup_resolved_days','default_category_path'];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) PluginRgsupervisionConfig::set($f, $_POST[$f]);
    }
    PluginRgsupervisionConfig::set('filter_critical', isset($_POST['filter_critical']) ? '1' : '0');
    PluginRgsupervisionConfig::set('filter_medium',   isset($_POST['filter_medium'])   ? '1' : '0');
    PluginRgsupervisionConfig::set('filter_low',      isset($_POST['filter_low'])      ? '1' : '0');
    if (!empty($_POST['contract_rules_json'])) {
        $rules = json_decode(stripslashes($_POST['contract_rules_json']), true);
        if (is_array($rules)) PluginRgsupervisionConfig::set('contract_rules', json_encode($rules));
    }

    // Sauvegarder la fréquence et mettre à jour le crontab automatiquement
    if (isset($_POST['cron_frequency'])) {
        $freqMinutes = max(1, (int)$_POST['cron_frequency']);
        PluginRgsupervisionConfig::set('cron_frequency', (string)$freqMinutes);

        // Construire l'expression cron selon la fréquence
        if ($freqMinutes === 1) {
            $cronExpr = '* * * * *';
        } elseif ($freqMinutes < 60) {
            $cronExpr = "*/{$freqMinutes} * * * *";
        } elseif ($freqMinutes === 60) {
            $cronExpr = '0 * * * *';
        } else {
            $hours = (int)floor($freqMinutes / 60);
            $cronExpr = "0 */{$hours} * * *";
        }

        $scriptPath  = GLPI_ROOT . '/plugins/rgsupervision/front/runcron.php';
        $newCronLine = $cronExpr . ' /usr/local/bin/php ' . $scriptPath . ' >> /tmp/rgsync.log 2>&1';
        $marker      = 'rgsupervision/front/runcron.php'; // identifiant unique de notre ligne

        // Lire le crontab actuel
        $currentCrontab = shell_exec('crontab -l 2>/dev/null') ?? '';

        // Supprimer l'ancienne ligne du plugin si elle existe
        $lines = explode("
", $currentCrontab);
        $lines = array_filter($lines, function($line) use ($marker) {
            return strpos($line, $marker) === false;
        });

        // Ajouter la nouvelle ligne
        $lines[] = $newCronLine;

        // Réécrire le crontab
        $newCrontab = implode("
", $lines);
        // S'assurer qu'il y a un saut de ligne final
        $newCrontab = rtrim($newCrontab) . "
";

        // Écrire via un fichier temporaire
        $tmpFile = tempnam(sys_get_temp_dir(), 'rg_cron_');
        file_put_contents($tmpFile, $newCrontab);
        $result = shell_exec('crontab ' . escapeshellarg($tmpFile) . ' 2>&1');
        unlink($tmpFile);

        if ($result) {
            // Erreur lors de l'écriture du crontab — on stocke juste la ligne pour affichage
            PluginRgsupervisionConfig::set('cron_line_status', 'error: ' . $result);
        } else {
            PluginRgsupervisionConfig::set('cron_line_status', 'ok');
        }
    }

    Html::redirect(Plugin::getWebDir('rgsupervision').'/front/config.php?msg=saved');
}

if (isset($_POST['run_sync'])) {
    $sync = new PluginRgsupervisionSync();
    $sync->run();
    Html::redirect(Plugin::getWebDir('rgsupervision').'/front/config.php?msg=synced');
}

Html::redirect(Plugin::getWebDir('rgsupervision').'/front/config.php');
