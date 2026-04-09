<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Direct access not allowed.' . PHP_EOL);
}

$glpiRoot   = realpath(dirname(__FILE__) . '/../../..');
$pluginRoot = realpath(dirname(__FILE__) . '/..');

chdir($glpiRoot);

// Initialisation complete via le kernel GLPI 10+
require_once($glpiRoot . '/vendor/autoload.php');

use Glpi\Kernel\Kernel;

$kernel = new Kernel('production', false);
$kernel->boot();

global $DB;

// Charger les classes du plugin
foreach (['config', 'syncstate', 'rgapiclient', 'sync'] as $cls) {
    $file = $pluginRoot . '/inc/' . $cls . '.class.php';
    if (file_exists($file)) {
        include_once($file);
    } else {
        echo date('Y-m-d H:i:s') . ' [RGSync] ERREUR : ' . $file . PHP_EOL;
        exit(1);
    }
}

$sync  = new PluginRgsupervisionSync();
$stats = $sync->run();

echo date('Y-m-d H:i:s') . ' [RGSync] OK — '
    . 'Collectes: ' . $stats['collectes'] . ' | '
    . 'Nouveaux: '  . $stats['nouveaux']  . ' | '
    . 'Relances: '  . $stats['relances']  . ' | '
    . 'Resolus: '   . $stats['resolus']
    . PHP_EOL;
