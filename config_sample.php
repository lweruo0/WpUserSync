<?php
declare(strict_types=1);

/**
 * Rename this file to config.php and adjust the values for production use.
 */

$plg_wpusersync_enabled = true;
$plg_wpusersync_require_https = true;
$plg_wpusersync_update_existing_by_email = true;
$plg_wpusersync_assign_default_roles = true;
$plg_wpusersync_external_id_field = 'WP_USER_ID';
$plg_wpusersync_default_role = '';
$plg_wpusersync_role_map_json = '{"subscriber":"Interessenten","member":"Mitglieder"}';
$plg_wpusersync_allowed_ips = '';
$plg_wpusersync_api_token_hash = '';
