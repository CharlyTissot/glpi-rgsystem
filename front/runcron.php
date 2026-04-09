<?php
/**
 * Point d'entrée cron dédié au plugin RG Supervision Sync
 * Peut être appelé directement par le cron système sans déclencher
 * les autres tâches GLPI.
 *
 * Usage crontab :
 * GLPI_VAR_DIR=/home/lizu4466/support.proximiweb.fr/files
 * */10 * * * * /usr/local/bin/php /home/lizu4466/support.proximiweb.fr/plugins/rgsupervision/front/runcron.php
 */

// Sécurité : refuser les appels HTTP directs
if (PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_HOST'])) {
    http_response_code(403);
    exit('Direct access not allowed.');
}

// Charger GLPI
$glpiRoot = dirname(__FILE__, 4);
define('GLPI_ROOT', $glpiRoot);
include($glpiRoot . '/inc/includes.php');

// Lancer la synchronisation
$sync  = new PluginRgsupervisionSync();
$stats = $sync->run();

// Sortie console
echo date('Y-m-d H:i:s') . " [RGSync] Terminé — "
    . "Collectés: {$stats['collectes']} | "
    . "Nouveaux: {$stats['nouveaux']} | "
    . "Relances: {$stats['relances']} | "
    . "Résolus: {$stats['resolus']}"
    . PHP_EOL;
