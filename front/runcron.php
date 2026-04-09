<?php
/**
 * Point d'entree cron dedie au plugin RG Supervision Sync
 *
 * Usage crontab (exemple toutes les 10 minutes) :
 * 10min: /usr/local/bin/php /chemin/glpi/plugins/rgsupervision/front/runcron.php >> /tmp/rgsync.log 2>&1
 */

// Securite : refuser les appels HTTP directs
if (PHP_SAPI !== 'cli' && !defined('GLPI_ROOT')) {
    http_response_code(403);
    exit('Direct access not allowed.' . PHP_EOL);
}

// Determiner la racine GLPI depuis l'emplacement de ce fichier
// Ce fichier est dans : glpi/plugins/rgsupervision/front/runcron.php
// Donc la racine GLPI est 4 niveaux au-dessus
$glpiRoot = realpath(dirname(__FILE__) . '/../../../..');

if (!$glpiRoot || !file_exists($glpiRoot . '/inc/includes.php')) {
    echo date('Y-m-d H:i:s') . ' [RGSync] ERREUR : impossible de trouver la racine GLPI depuis ' . __FILE__ . PHP_EOL;
    exit(1);
}

// Charger GLPI
define('GLPI_ROOT', $glpiRoot);
chdir($glpiRoot);
include($glpiRoot . '/inc/includes.php');

// Verifier que la classe de synchro est disponible
if (!class_exists('PluginRgsupervisionSync')) {
    echo date('Y-m-d H:i:s') . ' [RGSync] ERREUR : classe PluginRgsupervisionSync introuvable' . PHP_EOL;
    exit(1);
}

// Lancer la synchronisation
$sync  = new PluginRgsupervisionSync();
$stats = $sync->run();

// Sortie console
echo date('Y-m-d H:i:s') . ' [RGSync] OK — '
    . 'Collectes: ' . $stats['collectes'] . ' | '
    . 'Nouveaux: '  . $stats['nouveaux']  . ' | '
    . 'Relances: '  . $stats['relances']  . ' | '
    . 'Resolus: '   . $stats['resolus']
    . PHP_EOL;
