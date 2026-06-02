<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

final class RequestValidator
{
    public static function decodeJsonRequest(bool $requireHttps): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new ApiException('Only POST is allowed.', 'method_not_allowed', 405);
        }

        if ($requireHttps && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')) {
            throw new ApiException('HTTPS is required.', 'https_required', 400);
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== 0) {
            throw new ApiException('Content-Type application/json expected.', 'unsupported_media_type', 415);
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            throw new ApiException('Request body is empty.', 'empty_body', 400);
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException('Invalid JSON payload.', 'invalid_json', 400, array('json_error' => $e->getMessage()));
        }

        if (!is_array($decoded)) {
            throw new ApiException('JSON object expected.', 'invalid_payload', 400);
        }




        $profileData = is_array($decoded['profile'] ?? null) ? $decoded['profile'] : array();
        $errors = array();
        foreach (array('EMAIL', 'FIRST_NAME', 'LAST_NAME', 'BIRTHDAY') as $requiredField) {
            if (!isset($profileData[$requiredField]) || trim((string) $profileData[$requiredField]) === '') {
                $errors[$requiredField] = 'required';
            }
        }

        if (!empty($profileData['EMAIL']) && filter_var((string) $profileData['EMAIL'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['EMAIL'] = 'invalid';
        }

        $gender = is_array($profileData['GENDER'] ?? null) ? $profileData['GENDER'] : 'male';
        global $gProfileFields;
        $arrOptions = $gProfileFields->getProperty('GENDER', 'ufo_usf_options', 'text');
        if (!is_array($arrOptions)) {
            $errors['GENDER'] = 'invalid';
        }

        //$errors['dddd'] = print_r($arrOptions, true);

        if ($errors !== array()) {
            throw new ApiException('Validation failed.', 'validation_failed', 422, $errors);
        }

        $decoded['active'] = !array_key_exists('active', $decoded) || (bool) $decoded['active'];
        $decoded['roles'] = is_array($decoded['roles'] ?? null) ? array_values(array_unique(array_map('strval', $decoded['roles']))) : array();
        $decoded['profile'] = $profileData;

        return $decoded;
    }
}
