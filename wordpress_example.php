<?php
declare(strict_types=1);

/**
 * Beispiel fuer WordPress: HMAC-signierter Request an WpUserSync API.
 *
 * Nutzung:
 * - Datei in ein WP-Plugin oder mu-plugin uebernehmen.
 * - Konstanten/Variablen unten anpassen.
 * - Funktion wpusersync_send_signed_request(...) verwenden.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Baut die Signatur-Header entsprechend der Server-Validierung.
 * Canonical String:
 * METHOD\nPATH\nCLIENT_ID\nTIMESTAMP\nNONCE\nBODY_SHA256
 */
function wpusersync_build_signature_headers(string $method, string $url, string $clientId, string $sharedSecret, string $rawJson): array
{
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $bodyHash = hash('sha256', $rawJson);

    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '') {
        $path = '/';
    }

    $canonical = implode("\n", array(
        strtoupper($method),
        $path,
        $clientId,
        $timestamp,
        $nonce,
        $bodyHash,
    ));

    $signature = hash_hmac('sha256', $canonical, $sharedSecret);

    return array(
        'X-Api-Client-Id' => $clientId,
        'X-Api-Timestamp' => $timestamp,
        'X-Api-Nonce' => $nonce,
        'X-Api-Body-Sha256' => $bodyHash,
        'X-Api-Signature' => $signature,
    );
}

/**
 * Sendet einen signierten POST-Request.
 */
function wpusersync_send_signed_request(string $url, array $payload, string $clientId, string $sharedSecret, int $timeout = 20): array
{
    $rawJson = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rawJson === false) {
        return array(
            'ok' => false,
            'status' => 0,
            'body' => 'JSON encoding failed.',
            'error' => 'json_encode_failed',
        );
    }

    $headers = array_merge(
        array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ),
        wpusersync_build_signature_headers('POST', $url, $clientId, $sharedSecret, $rawJson)
    );

    $response = wp_remote_post($url, array(
        'timeout' => $timeout,
        'headers' => $headers,
        'body' => $rawJson,
        'data_format' => 'body',
    ));

    if (is_wp_error($response)) {
        return array(
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' => $response->get_error_message(),
        );
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);

    return array(
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'error' => null,
    );
}

/**
 * Sendet einen signierten Request mit beliebiger Methode (GET ohne Body, POST/DELETE mit JSON).
 * Gibt zusaetzlich die dekodierte JSON-Antwort unter 'data' zurueck.
 */
function wpusersync_request(string $method, string $url, ?array $payload, string $clientId, string $sharedSecret, int $timeout = 20): array
{
    $method = strtoupper($method);
    $rawJson = '';
    if ($payload !== null && $method !== 'GET') {
        $rawJson = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rawJson === false) {
            return array('ok' => false, 'status' => 0, 'body' => '', 'data' => null, 'error' => 'json_encode_failed');
        }
    }

    $headers = array_merge(
        array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
        wpusersync_build_signature_headers($method, $url, $clientId, $sharedSecret, $rawJson)
    );

    $args = array('method' => $method, 'timeout' => $timeout, 'headers' => $headers);
    if ($rawJson !== '') {
        $args['body'] = $rawJson;
        $args['data_format'] = 'body';
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'data' => null, 'error' => $response->get_error_message());
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);

    return array(
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'data' => json_decode($body, true),
        'error' => null,
    );
}

/**
 * Stand und Wechsel-Optionen (Mitgliedsarten und Zusatzbeitraege) fuer aktuelles Jahr und Folgejahr.
 * $baseUrl z. B. 'https://example.org/adm_plugins/wpusersync/api/v1'
 */
function wpusersync_get_membership_options(string $baseUrl, string $userUuid, string $clientId, string $sharedSecret): array
{
    $url = rtrim($baseUrl, '/') . '/core/users/' . rawurlencode($userUuid) . '/mitgliedsart';
    return wpusersync_request('GET', $url, null, $clientId, $sharedSecret);
}

/**
 * Wechselt die Mitgliedsart ab 1. Januar von $year.
 * $art: Kuerzel oder Name ('' = Kuendigung). $fees: gewuenschte Zusatzbeitraege; null = bisherige behalten.
 */
function wpusersync_change_membership(string $baseUrl, string $userUuid, int $year, string $art, ?array $fees, string $clientId, string $sharedSecret): array
{
    $url = rtrim($baseUrl, '/') . '/core/users/' . rawurlencode($userUuid) . '/mitgliedsart';
    $payload = array('year' => $year, 'art' => $art);
    if ($fees !== null) {
        $payload['fee'] = array_values($fees);
    }
    return wpusersync_request('POST', $url, $payload, $clientId, $sharedSecret);
}

// Beispiel Mitgliedsart-Wechsel:
// $baseUrl = 'https://example.org/adm_plugins/wpusersync/api/v1';
// $options = wpusersync_get_membership_options($baseUrl, $uuid, $clientId, $sharedSecret);
// // $options['data']['years']['2027']['options'] -> [{art, name, current, mandatory_fees, optional_fees}, ...]
// $result = wpusersync_change_membership($baseUrl, $uuid, 2027, 'A', array('Bootsbeitrag'), $clientId, $sharedSecret);
// if (!$result['ok']) {
//     error_log('Wechsel fehlgeschlagen: ' . ($result['data']['message'] ?? $result['body']));
// }

// Beispielaufruf (aktueller Endpoint):
// $apiUrl = 'https://example.org/adm_plugins/wpusersync/api/v1/core/users/new';
// $clientId = 'wordpress-prod';
// $sharedSecret = 'mein-langes-zufaelliges-shared-secret';
// $payload = array(
//     'firstName' => 'Max',
//     'lastName' => 'Mustermann',
//     'birthday' => '1980-01-01',
// );
//
// $result = wpusersync_send_signed_request($apiUrl, $payload, $clientId, $sharedSecret);
// if (!$result['ok']) {
//     error_log('WpUserSync request failed: ' . $result['status'] . ' ' . $result['body']);
// }
