<?php
declare(strict_types=1);

namespace WpUserSync\classes;

final class Config
{
    public static function load(string $pluginDir): array
    {
        $defaults = array(
            'enabled' => true,
            'require_https' => true,
            'update_existing_by_email' => true,
            'assign_default_roles' => true,
            'external_id_field' => 'WP_USER_ID',
            'default_role' => '',
            'allowed_ips' => '',
            'api_token_hash' => ''
        );

        $configFile = $pluginDir . '/config.php';
        if (!is_file($configFile)) {
            return $defaults;
        }

        require $configFile;

        return array(
            'enabled' => isset($plg_wpusersync_enabled) ? (bool) $plg_wpusersync_enabled : $defaults['enabled'],
            'require_https' => isset($plg_wpusersync_require_https) ? (bool) $plg_wpusersync_require_https : $defaults['require_https'],
            'update_existing_by_email' => isset($plg_wpusersync_update_existing_by_email) ? (bool) $plg_wpusersync_update_existing_by_email : $defaults['update_existing_by_email'],
            'assign_default_roles' => isset($plg_wpusersync_assign_default_roles) ? (bool) $plg_wpusersync_assign_default_roles : $defaults['assign_default_roles'],
            'external_id_field' => isset($plg_wpusersync_external_id_field) ? (string) $plg_wpusersync_external_id_field : $defaults['external_id_field'],
            'default_role' => isset($plg_wpusersync_default_role) ? (string) $plg_wpusersync_default_role : $defaults['default_role'],
            'allowed_ips' => isset($plg_wpusersync_allowed_ips) ? (string) $plg_wpusersync_allowed_ips : $defaults['allowed_ips'],
            'api_token_hash' => isset($plg_wpusersync_api_token_hash) ? (string) $plg_wpusersync_api_token_hash : $defaults['api_token_hash']
        );
    }
}
