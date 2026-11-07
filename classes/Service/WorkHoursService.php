<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use InvalidArgumentException;
use Plugins\MembershipFee\classes\Work\WorkApiException;
use Plugins\MembershipFee\classes\Work\WorkHoursApi;

/**
 * Arbeitsdienst-Stunden über das Plugin Mitgliedsbeitrag (Tabelle adm_plugin_membershipfee_workhours).
 *
 * Die fachlichen Regeln (Rechte, Jahressperre nach der Abrechnung, Mitgliedschaft im Leistungsjahr,
 * Prüfung der Eingaben) stehen ausschließlich in Plugins\MembershipFee\classes\Work\WorkHoursApi.
 * Diese Klasse lädt das Plugin, reicht die Anfrage weiter und setzt dessen Fehler in ApiException um.
 *
 * Schreibzugriffe laufen im Namen des API-Benutzers ($plg_wpusersync_api_user_uuid). Er braucht die
 * Zugriffsrolle des Beitragsplugins oder muss den Menüpunkt „Arbeitsdienst erfassen“ sehen dürfen.
 *
 * Endpunkte:
 *   GET    /core/users/{uuid}/arbeitsdienst          – alle Jahre (mit Einträgen) und aktuelles Jahr
 *   GET    /core/users/{uuid}/arbeitsdienst/{year}   – ein Leistungsjahr
 *   POST   /core/users/{uuid}/arbeitsdienst          – Eintrag anlegen oder (mit "id") ändern
 *   POST   /core/users/{uuid}/arbeitsdienst/{year}   – wie oben, Datum muss im Jahr liegen
 *   DELETE /core/users/{uuid}/arbeitsdienst/{id}     – Eintrag löschen
 *   POST   /core/users/{uuid}/arbeitsdienst/soll     – Sollstunden (Profilfeld ADTARGET) setzen
 */
final class WorkHoursService
{
    /** Ordnernamen des Beitragsplugins, die ohne Konfiguration gesucht werden */
    private const PLUGIN_FOLDERS = array('MembershipFee', 'Mitgliedsbeitrag', 'mitgliedsbeitrag', 'membershipfee');

    /**
     * @param array<string, mixed> $payload   JSON-Body der Anfrage
     * @param string               $pluginDir Ordner des Plugins Mitgliedsbeitrag ('' = automatisch suchen)
     */
    public function __construct(
        private readonly array $payload,
        string $pluginDir = '',
    ) {
        $pluginDir = rtrim($pluginDir !== '' ? $pluginDir : self::findPluginDir(), '/\\');
        if ($pluginDir === '' || !is_file($pluginDir . '/classes/Work/WorkHoursApi.php')) {
            throw new ApiException('Plugin Mitgliedsbeitrag (with work service API) is not installed.', 'membershipfee_missing', 501, array('path' => $pluginDir));
        }

        spl_autoload_register(static function (string $class) use ($pluginDir): void {
            $prefix = 'Plugins\\MembershipFee\\';
            if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
                return;
            }
            $file = $pluginDir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    /**
     * GET /core/users/{uuid}/arbeitsdienst[/{year}]
     */
    public function read(string $uuid, ?int $year = null): array
    {
        return array('status' => 'success') + $this->call(static fn (): array => WorkHoursApi::read($uuid, $year));
    }

    /**
     * POST /core/users/{uuid}/arbeitsdienst[/{year}]
     *
     * Body: {"data": {"date": "2026-04-18", "hours": 2.5, "activity": "Gewässerputzete", "comment": "…"},
     *        "check_duplicates": true}
     *   data.id           – vorhandenen Eintrag ändern (nur übergebene Felder)
     *   data.name         – wird wie "activity" behandelt (alte Schnittstelle)
     *   check_duplicates  – gleicher Eintrag (Datum, Einsatz, Stunden) wird nicht doppelt angelegt,
     *                       Antwort dann action "exists" (auch "check_dublicates" wird akzeptiert)
     */
    public function write(string $uuid, ?int $year = null): array
    {
        $data = $this->payload['data'] ?? null;
        if (!is_array($data) || $data === array()) {
            throw new ApiException('Field "data" is required and must be an object.', 'validation_failed', 422);
        }
        $checkDuplicates = filter_var(
            $this->payload['check_duplicates'] ?? $this->payload['check_dublicates'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        return array('status' => 'success') + $this->call(static fn (): array => WorkHoursApi::write($uuid, $data, $checkDuplicates, $year));
    }

    /**
     * POST /core/users/{uuid}/arbeitsdienst/soll
     *
     * Body: {"target_hours": 10}  – null oder "" leert das Feld
     */
    public function writeTarget(string $uuid): array
    {
        if (!array_key_exists('target_hours', $this->payload)) {
            throw new ApiException('Field "target_hours" is required (number or null).', 'validation_failed', 422);
        }
        $hours = $this->payload['target_hours'];

        return array('status' => 'success') + $this->call(static fn (): array => WorkHoursApi::writeTarget($uuid, $hours));
    }

    /**
     * DELETE /core/users/{uuid}/arbeitsdienst/{id}
     */
    public function delete(string $uuid, int $id): array
    {
        if ($id <= 0) {
            throw new ApiException('ID is required.', 'validation_failed', 422);
        }
        return array('status' => 'success') + $this->call(static fn (): array => WorkHoursApi::delete($uuid, $id));
    }

    /**
     * Ordner des Beitragsplugins neben WpUserSync suchen.
     */
    public static function findPluginDir(): string
    {
        $pluginsDir = dirname(__DIR__, 3);
        foreach (self::PLUGIN_FOLDERS as $folder) {
            if (is_file($pluginsDir . '/' . $folder . '/classes/Work/WorkHoursApi.php')) {
                return $pluginsDir . '/' . $folder;
            }
        }
        return '';
    }

    /**
     * @param callable(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function call(callable $callback): array
    {
        try {
            return $callback();
        } catch (WorkApiException $e) {
            throw new ApiException($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getDetails());
        } catch (InvalidArgumentException $e) {
            throw new ApiException($e->getMessage(), 'validation_failed', 422);
        }
    }
}
