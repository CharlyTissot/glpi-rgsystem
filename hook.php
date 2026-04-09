<?php

function plugin_rgsupervision_install() {
    global $DB;

    // En GLPI 10+ :
    // - $DB->query()       => INTERDIT (direct queries not allowed)
    // - $DB->doQuery()     => AUTORISE pour DDL (CREATE/DROP TABLE)
    // - $DB->insert()      => AUTORISE pour INSERT
    // - $DB->request()     => AUTORISE pour SELECT

    if (!$DB->tableExists('glpi_plugin_rgsupervision_configs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_rgsupervision_configs` (
                `id`    int unsigned NOT NULL AUTO_INCREMENT,
                `name`  varchar(255) NOT NULL DEFAULT '',
                `value` longtext          DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $defaults = [
            'rg_api_token'          => '',
            'rg_api_url'            => 'https://api.rg-supervision.com',
            'rg_root_node_id'       => '',
            'rg_request_timeout'    => '30',
            'filter_critical'       => '1',
            'filter_medium'         => '1',
            'filter_low'            => '0',
            'filter_agent_types'    => 's,w,e',
            'ticket_source'         => '10',
            'ticket_assign_group'   => '9',
            'ticket_default_entity' => '0',
            'cleanup_resolved_days' => '30',
            'default_category_path' => '',
            'cron_frequency'        => '10',
            'contract_rules'        => json_encode([]),
        ];

        foreach ($defaults as $name => $value) {
            $DB->insert('glpi_plugin_rgsupervision_configs', [
                'name'  => $name,
                'value' => $value,
            ]);
        }
    }

    if (!$DB->tableExists('glpi_plugin_rgsupervision_syncs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_rgsupervision_syncs` (
                `id`                int unsigned NOT NULL AUTO_INCREMENT,
                `rg_display_id`     varchar(255) NOT NULL DEFAULT '',
                `glpi_ticket_id`    int unsigned NOT NULL DEFAULT 0,
                `glpi_status`       int unsigned NOT NULL DEFAULT 1,
                `raise_count`       int unsigned NOT NULL DEFAULT 0,
                `node_name`         varchar(255)          DEFAULT NULL,
                `title`             text                  DEFAULT NULL,
                `info_cloture_sent` tinyint(1)   NOT NULL DEFAULT 0,
                `date_creation`     datetime              DEFAULT NULL,
                `date_mod`          datetime              DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `rg_display_id` (`rg_display_id`),
                KEY `glpi_ticket_id` (`glpi_ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (!$DB->tableExists('glpi_plugin_rgsupervision_logs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_rgsupervision_logs` (
                `id`          int unsigned NOT NULL AUTO_INCREMENT,
                `date_run`    datetime   NOT NULL,
                `duration`    float               DEFAULT NULL,
                `collectes`   int unsigned NOT NULL DEFAULT 0,
                `nouveaux`    int unsigned NOT NULL DEFAULT 0,
                `relances`    int unsigned NOT NULL DEFAULT 0,
                `resolus`     int unsigned NOT NULL DEFAULT 0,
                `errors`      text                DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `date_run` (`date_run`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    return true;
}

function plugin_rgsupervision_uninstall() {
    global $DB;

    foreach (['configs', 'syncs', 'logs'] as $suffix) {
        $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_rgsupervision_{$suffix}`");
    }

    return true;
}
