<?php

define('PLUGIN_RGSUPERVISION_VERSION', '1.0.0');
define('PLUGIN_RGSUPERVISION_MIN_GLPI', '10.0.0');
define('PLUGIN_RGSUPERVISION_MAX_GLPI', '11.99.99');

function plugin_version_rgsupervision() {
    return [
        'name'         => 'RG Supervision Sync',
        'version'      => PLUGIN_RGSUPERVISION_VERSION,
        'author'       => 'CharlyTissot',
        'license'      => 'GPL v2',
        'homepage'     => 'https://proximiweb.fr',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_RGSUPERVISION_MIN_GLPI, 'max' => PLUGIN_RGSUPERVISION_MAX_GLPI],
            'php'  => ['min' => '7.4'],
        ],
    ];
}

function plugin_rgsupervision_check_prerequisites() {
    if (!function_exists('curl_init')) { echo "cURL PHP requis."; return false; }
    return true;
}

function plugin_rgsupervision_check_config($verbose = false) { return true; }

function plugin_init_rgsupervision() {
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['rgsupervision'] = true;
    $PLUGIN_HOOKS['cron']['rgsupervision']           = true;
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['rgsupervision'] = 'front/config.php';
    }
}
