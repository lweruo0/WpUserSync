<?php
declare(strict_types=1);

namespace WpUserSync\classes;

final class WpUserSyncPlugin
{
    private string $pluginDir;
    private array $config;

    public function __construct(string $pluginDir)
    {
        $this->pluginDir = $pluginDir;
        $this->config = Config::load($pluginDir);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function render(): void
    {
        $endpoint = basename($this->pluginDir) . '/api/users.php';
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="admidio-plugin-content">';
        echo '<h3>WordPress Benutzer-Synchronisation</h3>';
        echo '<p>Dieses Plugin stellt einen JSON-Endpoint bereit, um Benutzer aus WordPress in Admidio anzulegen oder zu aktualisieren.</p>';
        echo '<p><strong>Endpoint:</strong> <code>' . htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') . '</code></p>';
        echo '<p><strong>Status:</strong> ' . ($this->config['enabled'] ? 'aktiv' : 'deaktiviert') . '</p>';
        echo '</div>';
    }
}
