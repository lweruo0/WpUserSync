<?php
declare(strict_types=1);

namespace WpUserSync\classes;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WpUserSync\classes\Service\FieldAllowlist;

final class WpUserSyncPlugin
{
    private string $pluginDir;

    public function __construct(string $pluginDir)
    {
        $this->pluginDir = $pluginDir;
    }

    public function render(): string
    {
        global $gProfileFields;
        $settings = new FieldAllowlist(FieldAllowlist::getSettingsPath($this->pluginDir));
        $availableFields = $this->getAvailableFieldNames($gProfileFields);
        $allowlist = array('read' => array(), 'write' => array());
        $notice = '';
        $noticeType = 'info';
        try {
            $allowlist = $settings->load();
        } catch (RuntimeException $exception) {
            $notice = $exception->getMessage();
            $noticeType = 'danger';
        }

        $csrfToken = '';
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['wpusersync_field_allowlist_csrf'])) {
                $_SESSION['wpusersync_field_allowlist_csrf'] = bin2hex(random_bytes(32));
            }
            $csrfToken = (string) $_SESSION['wpusersync_field_allowlist_csrf'];
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $submittedToken = (string) ($_POST['wpusersync_field_allowlist_csrf'] ?? '');
            if ($csrfToken === '' || !hash_equals($csrfToken, $submittedToken)) {
                $notice = 'Sicherheitsprüfung fehlgeschlagen. Bitte lade die Seite neu und versuche es erneut.';
                $noticeType = 'danger';
            } else {
                try {
                    $posted = $_POST['wpusersync_field_allowlist'] ?? array();
                    if (!is_array($posted)) {
                        throw new InvalidArgumentException('Ungültige Allowlist-Eingabe.');
                    }
                    $readFields = $this->validateSubmittedFields($posted['read'] ?? array(), $availableFields);
                    $writeFields = $this->validateSubmittedFields($posted['write'] ?? array(), $availableFields);
                    $settings->save($readFields, $writeFields);
                    $allowlist = array('read' => $readFields, 'write' => $writeFields);
                    $notice = 'Feld-Allowlist wurde gespeichert.';
                    $noticeType = 'success';
                } catch (InvalidArgumentException | RuntimeException $exception) {
                    $notice = $exception->getMessage();
                    $noticeType = 'danger';
                }
            }
        }

        $html = '';
        //header('Content-Type: text/html; charset=utf-8');
        $html.='<div class="admidio-plugin-content">';
        $html.= '<h3>WordPress Benutzer-Synchronisation</h3>';
        $html.= '<p>Dieses Plugin stellt JSON-Endpoints bereit, um Benutzer aus WordPress in Admidio anzulegen, zu aktualisieren und auszulesen.</p>';
        $html.= '<p><strong>API-Basis:</strong> <code>/adm_plugins/wpusersync/api/v1/</code></p>';

        $html.= '<h4>Konfigurationsparameter</h4>';
        $html.= '<p>Die folgenden globalen Variablen werden aus <code>adm_my_files/config.php</code> gelesen:</p>';
        $html.= '<table class="table table-condensed table-striped">';
        $html.= '<thead><tr><th>Variable</th><th>Wert</th></tr></thead>';
        $html.= '<tbody>';

        global $plg_wpusersync_enabled, $plg_wpusersync_require_https, $plg_wpusersync_assign_default_roles, $plg_wpusersync_api_secret, $plg_wpusersync_nonce_max_age, $plg_wpusersync_api_user_uuid, $plg_wpusersync_admhistory_dir;

        $parameters = array(
            'plg_wpusersync_enabled' => $plg_wpusersync_enabled,
            'plg_wpusersync_require_https' => $plg_wpusersync_require_https,
            'plg_wpusersync_assign_default_roles' => $plg_wpusersync_assign_default_roles,
            'plg_wpusersync_api_secret' => empty($plg_wpusersync_api_secret) ? 'nicht gesetzt' : 'gesetzt',
            'plg_wpusersync_nonce_max_age' => $plg_wpusersync_nonce_max_age,
            'plg_wpusersync_api_user_uuid' => empty($plg_wpusersync_api_user_uuid) ? 'nicht gesetzt (schreibende Anfragen gesperrt)' : $plg_wpusersync_api_user_uuid,
            'plg_wpusersync_admhistory_dir' => $plg_wpusersync_admhistory_dir ?? 'Standard: adm_plugins/adm-history'
                . (is_file(dirname(__DIR__, 2) . '/adm-history/autoload.php') ? ' (gefunden)' : ' (nicht gefunden)'),
        );

        foreach ($parameters as $key => $value) {
            $html.= '<tr>';
            $html.= '<td><code>$' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '</code></td>';
            $html.= '<td>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>';
            $html.= '</tr>';
        }

        $html.= '</tbody></table></br>';

        if ($notice !== '') {
            $html .= '<div class="alert alert-' . htmlspecialchars($noticeType, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $html .= '<h4>Profilfeld-Allowlist</h4>';
        $html .= '<p>Nur ausgewählte Felder dürfen über die API gelesen beziehungsweise geschrieben werden. Nicht ausgewählte Felder sind gesperrt. Die Auswahl wird in <code>adm_my_files/wpusersync-profile-fields.json</code> gespeichert.</p>';
        if ($csrfToken !== '') {
            $html .= '<form method="post">';
            $html .= '<input type="hidden" name="wpusersync_field_allowlist_csrf" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<div class="row">';
            $html .= '<div class="col-md-6"><h5>Lesbare Felder</h5>' . $this->renderFieldCheckboxes($availableFields, $allowlist['read'], 'read') . '</div>';
            $html .= '<div class="col-md-6"><h5>Schreibbare Felder</h5>' . $this->renderFieldCheckboxes($availableFields, $allowlist['write'], 'write') . '</div>';
            $html .= '</div><button type="submit" class="btn btn-primary">Allowlist speichern</button></form>';
        } else {
            $html .= '<p class="alert alert-danger">Es ist keine aktive Sitzung verfügbar; die Allowlist kann deshalb nicht sicher gespeichert werden.</p>';
        }

        $html.= '<h4>Beispiel für adm_my_files/config.php</h4>';
        $html.= '<pre><code>' . htmlspecialchars($this->buildConfigExample(), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        $html.= '<p>API-Secret: lang und zufaellig waehlen (mindestens 32 Zeichen).</p>';
        $html.= '<p>API-Header: <code>X-Api-Client-Id</code>, <code>X-Api-Timestamp</code>, <code>X-Api-Nonce</code>, <code>X-Api-Body-Sha256</code>, <code>X-Api-Signature</code></p>';
        $html.= '</div>';
        return $html;

    }

    private function getAvailableFieldNames($profileFields): array
    {
        $fieldNames = array();
        foreach ($profileFields->getProfileFields() as $field) {
            $fieldName = $field->getValue('usf_name_intern');
            if (is_string($fieldName) && $fieldName !== '') {
                $fieldNames[] = $fieldName;
            }
        }

        sort($fieldNames, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values(array_unique($fieldNames));
    }

    private function validateSubmittedFields($submittedFields, array $availableFields): array
    {
        if (!is_array($submittedFields)) {
            throw new InvalidArgumentException('Ungültige Feld-Auswahl.');
        }

        $selectedFields = array();
        foreach ($submittedFields as $fieldName) {
            if (!is_string($fieldName) || !in_array($fieldName, $availableFields, true)) {
                throw new InvalidArgumentException('Die Auswahl enthält ein unbekanntes Profilfeld.');
            }
            $selectedFields[] = $fieldName;
        }

        return array_values(array_unique($selectedFields));
    }

    private function renderFieldCheckboxes(array $availableFields, array $selectedFields, string $access): string
    {
        if (count($availableFields) === 0) {
            return '<p>Keine Profilfelder gefunden.</p>';
        }

        $html = '<div class="wpusersync-field-list">';
        foreach ($availableFields as $fieldName) {
            $id = 'wpusersync-' . $access . '-' . hash('sha256', $fieldName);
            $checked = in_array($fieldName, $selectedFields, true) ? ' checked' : '';
            $html .= '<div class="form-check">';
            $html .= '<input class="form-check-input" type="checkbox" id="' . $id . '" name="wpusersync_field_allowlist[' . $access . '][]" value="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '"' . $checked . '>';
            $html .= '<label class="form-check-label" for="' . $id . '"><code>' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '</code></label>';
            $html .= '</div>';
        }
        $html .= '</div>';

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
            '// Admidio-Benutzer, in dessen Namen alle Änderungen aus WordPress laufen (Pflicht für POST/DELETE);',
            '// für den Mitgliedsart-Wechsel braucht er das Recht, Mitglieder der betroffenen Rollen zuzuordnen',
            '$plg_wpusersync_api_user_uuid = \'00000000-0000-0000-0000-000000000000\';',
            '// optional, Standard: adm_plugins/adm-history',
            '// $plg_wpusersync_admhistory_dir = __DIR__ . \'/../adm_plugins/adm-history\';',
        );

        return implode(PHP_EOL, $lines);
    }
}
