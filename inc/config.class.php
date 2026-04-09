<?php
class PluginRgsupervisionConfig extends CommonDBTM {

    public static $rightname = 'config';

    public static function getTypeName($nb = 0) {
        return 'RG Supervision Sync';
    }

    public static function get($name, $default = '') {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_rgsupervision_configs')) return $default;
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_rgsupervision_configs',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ]);
        $row = $iter->current();
        return $row ? (string)$row['value'] : $default;
    }

    public static function set($name, $value) {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_rgsupervision_configs')) return;
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_rgsupervision_configs',
            'WHERE' => ['name' => $name],
            'LIMIT' => 1,
        ]);
        if ($iter->current()) {
            $DB->update('glpi_plugin_rgsupervision_configs', ['value' => $value], ['name' => $name]);
        } else {
            $DB->insert('glpi_plugin_rgsupervision_configs', ['name' => $name, 'value' => $value]);
        }
    }

    public static function all() {
        global $DB;
        $cfg = [];
        if (!$DB->tableExists('glpi_plugin_rgsupervision_configs')) return $cfg;
        foreach ($DB->request(['FROM' => 'glpi_plugin_rgsupervision_configs']) as $row) {
            $cfg[$row['name']] = $row['value'];
        }
        return $cfg;
    }
}
