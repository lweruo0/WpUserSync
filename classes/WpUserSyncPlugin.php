<?php
declare(strict_types=1);

namespace WpUserSync\classes;

final class WpUserSyncPlugin
{
    private string $pluginDir;

    public function __construct(string $pluginDir)
    {
        $this->pluginDir = $pluginDir;
    }

    public function render(): string
    {
        $pluginName = basename($this->pluginDir);
        $writeEndpoint = $pluginName . '/api/write_user.php';
        $readEndpoint = $pluginName . '/api/read_user.php';

        $html = '';
        //header('Content-Type: text/html; charset=utf-8');
        $html.='<div class="admidio-plugin-content">';
        $html.= '<h3>WordPress Benutzer-Synchronisation</h3>';
        $html.= '<p>Dieses Plugin stellt JSON-Endpoints bereit, um Benutzer aus WordPress in Admidio anzulegen, zu aktualisieren und auszulesen.</p>';
        $html.= '<p><strong>Schreiben:</strong> <code>' . htmlspecialchars($writeEndpoint, ENT_QUOTES, 'UTF-8') . '</code></p>';
        $html.= '<p><strong>Lesen:</strong> <code>' . htmlspecialchars($readEndpoint, ENT_QUOTES, 'UTF-8') . '</code></p></br>';

        $html.= '<h4>Konfigurationsparameter</h4>';
        $html.= '<p>Die folgenden globalen Variablen werden aus <code>adm_my_files/config.php</code> gelesen:</p>';
        $html.= '<table class="table table-condensed table-striped">';
        $html.= '<thead><tr><th>Variable</th><th>Wert</th></tr></thead>';
        $html.= '<tbody>';

        global $plg_wpusersync_enabled, $plg_wpusersync_require_https, $plg_wpusersync_assign_default_roles, $plg_wpusersync_api_secret, $plg_wpusersync_nonce_max_age;

        $parameters = array(
            'plg_wpusersync_enabled' => $plg_wpusersync_enabled,
            'plg_wpusersync_require_https' => $plg_wpusersync_require_https,
            'plg_wpusersync_assign_default_roles' => $plg_wpusersync_assign_default_roles,
            'plg_wpusersync_api_secret' => $plg_wpusersync_api_secret,
            'plg_wpusersync_nonce_max_age' => $plg_wpusersync_nonce_max_age,
        );

        foreach ($parameters as $key => $value) {
            $html.= '<tr>';
            $html.= '<td><code>$' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '</code></td>';
            $html.= '<td>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>';
            $html.= '</tr>';
        }

        $html.= '</tbody></table></br>';

        $html.= '<h4>Beispiel für adm_my_files/config.php</h4>';
        $html.= '<pre><code>' . htmlspecialchars($this->buildConfigExample(), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        $html.= '<p>API-Secret: lang und zufaellig waehlen (mindestens 32 Zeichen).</p>';
        $html.= '<p>API-Header: <code>X-Api-Client-Id</code>, <code>X-Api-Timestamp</code>, <code>X-Api-Nonce</code>, <code>X-Api-Body-Sha256</code>, <code>X-Api-Signature</code></p>';
        $html.= '</div>';
        return $html;

    }

    private function buildConfigExample(): string
    {
        $lines = array(
            '// WpUserSync – in adm_my_files/config.php einfügen',
            '$plg_wpusersync_enabled = true;',
            '$plg_wpusersync_require_https = true;',
            '$plg_wpusersync_assign_default_roles = true;',
            '$plg_wpusersync_api_secret = \'mein-langes-zufaelliges-shared-secret\';',
            '$plg_wpusersync_nonce_max_age = 300;',
        );

        return implode(PHP_EOL, $lines);
    }
}
