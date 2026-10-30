<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Users\Entity\User;

/**
 * Technischer API-Benutzer ($plg_wpusersync_api_user_uuid).
 *
 * Die API hat keine Admidio-Session; ohne diese Klasse liefe jede Anfrage als Gast. api.php setzt
 * nach erfolgreicher Signaturprüfung den konfigurierten Benutzer als angemeldeten Benutzer
 * ($gCurrentUser, $gCurrentUserId, $gCurrentUserUUID). Damit trägt Admidio ihn bei allen Änderungen
 * aus WordPress im Änderungsprotokoll ein, und Rechteprüfungen (z. B. im Plugin adm-history) gelten
 * für ihn.
 */
final class ApiUser
{
    /**
     * Lädt den Benutzer per UUID und setzt ihn als angemeldeten Benutzer.
     * @throws ApiException wenn keine UUID konfiguriert ist oder der Benutzer nicht existiert bzw. ungültig ist
     */
    public static function activate(string $uuid): User
    {
        global $gDb, $gProfileFields;

        $uuid = trim($uuid);
        if ($uuid === '') {
            throw new ApiException('API user is not configured ($plg_wpusersync_api_user_uuid).', 'api_user_not_configured', 500);
        }

        $user = new User($gDb, $gProfileFields);
        if (!$user->readDataByUuid($uuid) || (int) $user->getValue('usr_valid') !== 1) {
            throw new ApiException('Configured API user not found or not valid.', 'api_user_invalid', 500);
        }

        $GLOBALS['gCurrentUser'] = $user;
        $GLOBALS['gCurrentUserId'] = (int) $user->getValue('usr_id');
        $GLOBALS['gCurrentUserUUID'] = (string) $user->getValue('usr_uuid');

        return $user;
    }

    /**
     * Der aktuell angemeldete Benutzer, wenn ein echter Benutzer (kein Gast) gesetzt ist.
     * @throws ApiException wenn kein API-Benutzer aktiv ist
     */
    public static function require(): User
    {
        global $gCurrentUser;

        if (!$gCurrentUser instanceof User || (int) $gCurrentUser->getValue('usr_id') <= 0) {
            throw new ApiException('API user is not configured ($plg_wpusersync_api_user_uuid).', 'api_user_not_configured', 500);
        }

        return $gCurrentUser;
    }
}
