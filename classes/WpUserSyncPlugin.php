<?php
declare(strict_types=1);

namespace WpUserSync\classes;

final class WpUserSyncPlugin
{
    private string $pluginDir;
    private array $config;
    private array $configState;

    public function __construct(string $pluginDir)
    {
        $this->pluginDir = $pluginDir;
        $this->configState = Config::resolve($pluginDir);
        $this->config = $this->configState['values'];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function render(): void
    {
        $pluginName = basename($this->pluginDir);
        $writeEndpoint = $pluginName . '/api/write_user.php';
        $readEndpoint = $pluginName . '/api/read_user.php';
        $configFile = $this->configState['config_file'];
        $hasWarnings = !$this->configState['config_file_exists'] || $this->hasConfigurationWarnings();

        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="admidio-plugin-content">';
        echo '<h3>WordPress Benutzer-Synchronisation</h3>';
        echo '<p>Dieses Plugin stellt JSON-Endpoints bereit, um Benutzer aus WordPress in Admidio anzulegen, zu aktualisieren und auszulesen.</p>';
        echo '<p><strong>Schreiben:</strong> <code>' . htmlspecialchars($writeEndpoint, ENT_QUOTES, 'UTF-8') . '</code></p>';
        echo '<p><strong>Lesen:</strong> <code>' . htmlspecialchars($readEndpoint, ENT_QUOTES, 'UTF-8') . '</code></p>';
        echo '<p><strong>Konfigurationsdatei:</strong> <code>' . htmlspecialchars($configFile, ENT_QUOTES, 'UTF-8') . '</code></p>';

        if (!$this->configState['config_file_exists']) {
            echo '<p style="color:#b45309;"><strong>Hinweis:</strong> Die Konfigurationsdatei wurde nicht gefunden. Es werden ausschließlich Standardwerte verwendet.</p>';
        }

        if ($hasWarnings) {
            echo '<p style="color:#b45309;"><strong>Hinweis:</strong> Die API ist noch nicht vollständig konfiguriert. Details siehe unten.</p>';
        }

        echo '<h4>Konfigurationsparameter</h4>';
        echo '<p>Die folgenden Variablen müssen in <code>adm_my_files/config.php</code> gesetzt werden:</p>';
        echo '<table class="table table-condensed table-striped">';
        echo '<thead><tr><th>Variable</th><th>Beschreibung</th><th>Aktueller Wert</th><th>Status</th></tr></thead>';
        echo '<tbody>';

        foreach ($this->configState['parameters'] as $parameter) {
            echo '<tr>';
            echo '<td><code>$' . htmlspecialchars((string) $parameter['variable'], ENT_QUOTES, 'UTF-8') . '</code></td>';
            echo '<td>' . htmlspecialchars((string) $parameter['description'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><code>' . htmlspecialchars($this->formatParameterValue($parameter), ENT_QUOTES, 'UTF-8') . '</code></td>';
            echo '<td>' . $this->renderParameterStatus($parameter) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<h4>Beispiel für adm_my_files/config.php</h4>';
        echo '<pre><code>' . htmlspecialchars($this->buildConfigExample(), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        echo '<p>Token-Hash erzeugen: <code>echo hash(\'sha256\', \'mein-geheimes-token\');</code></p>';
        echo '<p>API-Header: <code>X-Api-Token</code>, <code>X-Api-Nonce</code> (Format: <code>unixzeit.hmac_sha256</code>)</p>';
        echo '</div>';
    }

    private function hasConfigurationWarnings(): bool
    {
        foreach ($this->configState['parameters'] as $parameter) {
            if ($parameter['uses_default'] && ($parameter['required'] || $parameter['key'] === 'api_token_hash')) {
                return true;
            }

            if ($parameter['key'] === 'api_token_hash' && (string) $parameter['value'] === '') {
                return true;
            }
        }

        return false;
    }

    private function formatParameterValue(array $parameter): string
    {
        $value = $parameter['value'];

        if ($parameter['type'] === 'bool') {
            return $value ? 'true' : 'false';
        }

        if ($parameter['key'] === 'api_token_hash') {
            if ((string) $value === '') {
                return '(leer)';
            }

            return substr((string) $value, 0, 8) . '...';
        }

        return (string) $value;
    }

    private function renderParameterStatus(array $parameter): string
    {
        if ($parameter['key'] === 'api_token_hash' && (string) $parameter['value'] === '') {
            return '<span style="color:#b91c1c;">Pflichtwert fehlt</span>';
        }

        if ($parameter['uses_default']) {
            $defaultValue = $parameter['type'] === 'bool'
                ? ($parameter['default'] ? 'true' : 'false')
                : (string) $parameter['default'];

            return '<span style="color:#b45309;">Standardwert: ' . htmlspecialchars($defaultValue, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return '<span style="color:#15803d;">gesetzt</span>';
    }

    private function buildConfigExample(): string
    {
        $lines = array(
            '// WpUserSync – in adm_my_files/config.php einfügen',
            '$plg_wpusersync_enabled = true;',
            '$plg_wpusersync_require_https = true;',
            '$plg_wpusersync_assign_default_roles = true;',
            '$plg_wpusersync_api_token_hash = \'' . hash('sha256', 'mein-geheimes-token') . '\';',
            '$plg_wpusersync_nonce_max_age = 300;',
        );

        return implode(PHP_EOL, $lines);
    }
}
