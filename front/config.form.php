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

    // Sauvegarder la fréquence (la ligne crontab est générée et affichée dans l'interface)
    if (isset($_POST['cron_frequency'])) {
        $freqMinutes = max(1, (int)$_POST['cron_frequency']);
        PluginRgsupervisionConfig::set('cron_frequency', (string)$freqMinutes);
    }

    Html::redirect(Plugin::getWebDir('rgsupervision').'/front/config.php?msg=saved');
}

if (isset($_POST['run_sync'])) {
    $sync = new PluginRgsupervisionSync();
    $sync->run();
    Html::redirect(Plugin::getWebDir('rgsupervision').'/front/config.php?msg=synced');
}

Html::redirect(Plugin::getWebDir('rgsupervision').'/front/config.php');
