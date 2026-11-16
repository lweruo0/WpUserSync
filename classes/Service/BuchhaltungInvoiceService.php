<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use AdmBuchhaltung\Api\InvoiceApi;
use AdmBuchhaltung\Api\InvoiceApiException;

/**
 * Rechnungen der WordPress-Seite an das Plugin Buchhaltung übergeben (dort KONZEPT_WORDPRESS_RECHNUNGEN.md).
 *
 * Die fachlichen Regeln (Recht „Belege einreichen“, Prüfung der Felder, Ablage, keine Änderung eingegangener
 * Rechnungen) stehen ausschließlich in AdmBuchhaltung\Api\InvoiceApi. Diese Klasse lädt das Plugin, reicht die
 * Anfrage weiter und setzt dessen Fehler in ApiException um.
 *
 * Läuft im Namen des API-Benutzers ($plg_wpusersync_api_user_uuid) – auch beim Lesen.
 *
 * Endpunkte:
 *   POST /buchhaltung/rechnungen            – Rechnung (PDF) einliefern: 201 neu, 200 schon vorhanden, 409 andere Datei
 *   GET  /buchhaltung/rechnungen/{nummer}   – welche Dokumente zu einer Nummer vorliegen (mit Prüfsumme)
 */
final class BuchhaltungInvoiceService
{
    /** Ordnernamen des Buchhaltungsplugins, die ohne Konfiguration gesucht werden */
    private const PLUGIN_FOLDERS = array('buchhaltung', 'Buchhaltung');

    /**
     * @param array<string, mixed> $payload   JSON-Body der Anfrage
     * @param string               $pluginDir Ordner des Plugins Buchhaltung ('' = automatisch suchen)
     */
    public function __construct(
        private readonly array $payload,
        string $pluginDir = '',
    ) {
        $pluginDir = rtrim($pluginDir !== '' ? $pluginDir : self::findPluginDir(), '/\\');
        if ($pluginDir === '' || !is_file($pluginDir . '/classes/Api/InvoiceApi.php') || !is_file($pluginDir . '/autoload.php')) {
            throw new ApiException('Plugin Buchhaltung (with invoice API) is not installed.', 'buchhaltung_missing', 501, array('path' => $pluginDir));
        }
        require_once $pluginDir . '/autoload.php';
    }

    /**
     * POST /buchhaltung/rechnungen
     *
     * Body: {"nummer": "2026-P-0294", "art": "erlaubnis", "dokument": "rechnung", "datum": "2026-05-03",
     *        "name": "Max Muster", "betrag": "45.00", "pdf_base64": "…", "sha256": "…"}
     *
     * @return array{0: array<string, mixed>, 1: int} Antwort und HTTP-Status (201 neu, 200 schon vorhanden)
     */
    public function receive(): array
    {
        ApiUser::require();
        $result = $this->call(fn (): array => InvoiceApi::receive($this->payload));
        return array(array('status' => 'success') + $result, $result['action'] === 'created' ? 201 : 200);
    }

    /**
     * GET /buchhaltung/rechnungen/{nummer}
     *
     * @return array<string, mixed>
     */
    public function status(string $number): array
    {
        ApiUser::require();
        return array('status' => 'success') + $this->call(static fn (): array => InvoiceApi::status($number));
    }

    /**
     * Ordner des Buchhaltungsplugins neben WpUserSync suchen.
     */
    public static function findPluginDir(): string
    {
        $pluginsDir = dirname(__DIR__, 3);
        foreach (self::PLUGIN_FOLDERS as $folder) {
            if (is_file($pluginsDir . '/' . $folder . '/classes/Api/InvoiceApi.php')) {
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
        } catch (InvoiceApiException $e) {
            throw new ApiException($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getDetails());
        }
    }
}
