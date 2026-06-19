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
