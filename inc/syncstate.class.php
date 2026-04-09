<?php
class PluginRgsupervisionSyncstate extends CommonDBTM {

    public static $rightname = 'config';

    // Noms préfixés "rg" pour éviter tout conflit avec CommonDBTM
    public static function rgGetByRgId($rgId) {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_rgsupervision_syncs')) return null;
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_rgsupervision_syncs',
            'WHERE' => ['rg_display_id' => $rgId],
            'LIMIT' => 1,
        ]);
        return $iter->current() ?: null;
    }

    public static function rgCreate($rgId, $ticketId, $extra = []) {
        global $DB;
        $DB->insert('glpi_plugin_rgsupervision_syncs', array_merge([
            'rg_display_id'     => $rgId,
            'glpi_ticket_id'    => (int)$ticketId,
            'glpi_status'       => 1,
            'raise_count'       => 0,
            'info_cloture_sent' => 0,
            'date_creation'     => date('Y-m-d H:i:s'),
            'date_mod'          => date('Y-m-d H:i:s'),
        ], $extra));
    }

    public static function rgUpdate($rgId, $fields) {
        global $DB;
        $fields['date_mod'] = date('Y-m-d H:i:s');
        $DB->update('glpi_plugin_rgsupervision_syncs', $fields, ['rg_display_id' => $rgId]);
    }

    public static function rgRemove($rgId) {
        global $DB;
        $DB->delete('glpi_plugin_rgsupervision_syncs', ['rg_display_id' => $rgId]);
    }

    public static function rgCleanup($maxDays) {
        global $DB;
        if ($maxDays === null || $maxDays === '') return 0;
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . (int)$maxDays . ' days'));
        $iter = $DB->request([
            'SELECT' => ['rg_display_id'],
            'FROM'   => 'glpi_plugin_rgsupervision_syncs',
            'WHERE'  => ['glpi_status' => [5, 6], ['date_creation' => ['<', $cutoff]]],
        ]);
        $n = 0;
        foreach ($iter as $row) { self::rgRemove($row['rg_display_id']); $n++; }
        return $n;
    }
}
