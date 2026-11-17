<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use Admidio\Users\Entity\User;
use GdImage;

/**
 * Profilfoto eines Mitglieds lesen, setzen und löschen.
 *
 * Admidio verkleinert Profilfotos beim Hochladen über die Oberfläche fest auf höchstens 130 × 170 px
 * (modules/profile/profile_photo_edit.php). Diese Klasse speichert das Foto stattdessen in
 * WIDTH × HEIGHT (600 × 770, Passbildformat 35 × 45) – an derselben Stelle wie Admidio selbst, je nach
 * Einstellung „profile_photo_storage“:
 *   0 = Datenbank, Spalte usr_photo der Tabelle adm_users
 *   1 = Datei adm_my_files/user_profile_photos/{usr_id}.jpg
 * Admidio zeigt das Foto unverändert an (profile_photo_show.php); die Größe auf der Seite legt das HTML fest.
 *
 * Das Bild wird mittig auf das Seitenverhältnis WIDTH:HEIGHT zugeschnitten und auf genau diese Größe
 * gebracht (kleinere Bilder werden vergrößert), immer als JPEG gespeichert.
 *
 * Läuft im Namen des API-Benutzers ($plg_wpusersync_api_user_uuid); Schreiben und Löschen brauchen dessen
 * Recht, das Profil des Mitglieds zu bearbeiten (Rolle mit „Alle Benutzer bearbeiten“), sonst 403.
 *
 * Endpunkte:
 *   GET    /core/users/{uuid}/photo   – ob ein Foto vorhanden ist, Größe, Prüfsumme
 *   POST   /core/users/{uuid}/photo   – Foto setzen, Body {"photo_base64": "…", "sha256": "…" (optional)}
 *   DELETE /core/users/{uuid}/photo   – Foto löschen
 */
final class ProfilePhotoService
{
    /** Breite des gespeicherten Fotos in Pixel */
    public const WIDTH = 600;

    /** Höhe des gespeicherten Fotos in Pixel */
    public const HEIGHT = 770;

    /** JPEG-Qualität */
    private const QUALITY = 90;

    /** Größte Pixelzahl des hochgeladenen Bildes (Speicherbedarf GD ca. 5 Byte je Pixel) */
    private const MAX_PIXELS = 25000000;

    /**
     * @param array<string, mixed> $payload JSON-Body der Anfrage
     */
    public function __construct(
        private readonly Database $db,
        private readonly ProfileFields $profileFields,
        private readonly array $payload = array(),
    ) {
    }

    /**
     * GET /core/users/{uuid}/photo
     *
     * @return array<string, mixed>
     */
    public function read(string $uuid): array
    {
        $user = $this->loadUser($uuid);
        return array('status' => 'success') + $this->info($user);
    }

    /**
     * POST /core/users/{uuid}/photo
     *
     * @return array<string, mixed>
     */
    public function write(string $uuid): array
    {
        $user = $this->loadUser($uuid);
        $this->assertCanEdit($user);

        $base64 = $this->payload['photo_base64'] ?? null;
        if (!is_string($base64) || $base64 === '') {
            throw new ApiException('photo_base64 is required.', 'validation_failed', 422);
        }
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            throw new ApiException('photo_base64 is not valid base64.', 'validation_failed', 422);
        }
        $sha256 = $this->payload['sha256'] ?? null;
        if (is_string($sha256) && $sha256 !== '' && !hash_equals(strtolower($sha256), hash('sha256', $binary))) {
            throw new ApiException('sha256 does not match the photo.', 'validation_failed', 422);
        }

        $jpeg = $this->normalize($binary);
        $this->store($user, $jpeg);

        return array('status' => 'success', 'action' => 'updated') + $this->info($user);
    }

    /**
     * DELETE /core/users/{uuid}/photo
     *
     * @return array<string, mixed>
     */
    public function delete(string $uuid): array
    {
        $user = $this->loadUser($uuid);
        $this->assertCanEdit($user);

        $existed = $this->currentPhoto($user) !== '';
        if ($this->storesInFolder()) {
            $file = $this->filePath($user);
            if (is_file($file) && !unlink($file)) {
                throw new ApiException('Photo file could not be deleted.', 'server_error', 500);
            }
            $this->touchUser($user);
        } else {
            $user->setValue('usr_photo', '');
            $user->saveChangesWithoutRights();
            $user->save();
        }

        return array('status' => 'success', 'action' => $existed ? 'deleted' : 'not_found') + $this->info($user);
    }

    /**
     * Bild prüfen, mittig auf WIDTH:HEIGHT zuschneiden, auf WIDTH × HEIGHT bringen, als JPEG liefern.
     */
    private function normalize(string $binary): string
    {
        $size = @getimagesizefromstring($binary);
        if ($size === false || !in_array($size[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG), true)) {
            throw new ApiException('Photo must be a JPEG or PNG image.', 'validation_failed', 422);
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new ApiException('Photo resolution is too large.', 'validation_failed', 422, array('max_pixels' => self::MAX_PIXELS));
        }
        $source = @imagecreatefromstring($binary);
        if (!$source instanceof GdImage) {
            throw new ApiException('Photo could not be read.', 'validation_failed', 422);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = self::WIDTH / self::HEIGHT;
        if ($width / $height > $ratio) {
            // zu breit: links und rechts abschneiden
            $cropWidth = (int) round($height * $ratio);
            $cropHeight = $height;
        } else {
            // zu hoch: oben und unten abschneiden
            $cropWidth = $width;
            $cropHeight = (int) round($width / $ratio);
        }
        $x = intdiv($width - $cropWidth, 2);
        $y = intdiv($height - $cropHeight, 2);

        $target = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        // transparente PNG-Bereiche weiß statt schwarz
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, $x, $y, self::WIDTH, self::HEIGHT, $cropWidth, $cropHeight);

        ob_start();
        imagejpeg($target, null, self::QUALITY);
        return (string) ob_get_clean();
    }

    /**
     * Foto dort speichern, wo Admidio es liest.
     */
    private function store(User $user, string $jpeg): void
    {
        if ($this->storesInFolder()) {
            $file = $this->filePath($user);
            $dir = dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new ApiException('Photo folder could not be created.', 'server_error', 500);
            }
            // erst vollständig schreiben, dann ersetzen
            $tmp = $file . '.tmp';
            if (file_put_contents($tmp, $jpeg) === false || !rename($tmp, $file)) {
                @unlink($tmp);
                throw new ApiException('Photo file could not be written.', 'server_error', 500);
            }
            $this->touchUser($user);
            return;
        }

        $user->setValue('usr_photo', $jpeg);
        $user->saveChangesWithoutRights();
        $user->save();
    }

    /**
     * Änderungszeit des Benutzers setzen: Admidio liefert das Foto mit Last-Modified = usr_timestamp_change,
     * sonst zeigt der Browser nach dem Austausch der Datei das alte Foto aus dem Cache.
     */
    private function touchUser(User $user): void
    {
        global $gCurrentUserId;
        $sql = 'UPDATE ' . TBL_USERS . ' SET usr_timestamp_change = ?, usr_usr_id_change = ? WHERE usr_id = ?';
        $this->db->queryPrepared($sql, array(date('Y-m-d H:i:s'), (int) ($gCurrentUserId ?? 0) ?: null, (int) $user->getValue('usr_id')));
    }

    /**
     * @return array<string, mixed> exists, storage, width, height, bytes, sha256
     */
    private function info(User $user): array
    {
        $photo = $this->currentPhoto($user);
        $size = $photo !== '' ? @getimagesizefromstring($photo) : false;
        return array(
            'user_uuid' => (string) $user->getValue('usr_uuid'),
            'exists' => $photo !== '',
            'storage' => $this->storesInFolder() ? 'folder' : 'database',
            'width' => $size !== false ? $size[0] : null,
            'height' => $size !== false ? $size[1] : null,
            'bytes' => strlen($photo),
            'sha256' => $photo !== '' ? hash('sha256', $photo) : null,
        );
    }

    /**
     * Gespeichertes Foto als Binärdaten ('' = keins).
     */
    private function currentPhoto(User $user): string
    {
        if ($this->storesInFolder()) {
            $file = $this->filePath($user);
            return is_file($file) ? (string) file_get_contents($file) : '';
        }
        // frisch lesen, damit nach dem Speichern der neue Stand zurückkommt
        $sql = 'SELECT usr_photo FROM ' . TBL_USERS . ' WHERE usr_id = ?';
        $value = $this->db->queryPrepared($sql, array((int) $user->getValue('usr_id')))->fetchColumn();
        return is_string($value) ? $value : (is_resource($value) ? (string) stream_get_contents($value) : '');
    }

    private function storesInFolder(): bool
    {
        global $gSettingsManager;
        return (int) $gSettingsManager->get('profile_photo_storage') === 1;
    }

    private function filePath(User $user): string
    {
        return ADMIDIO_PATH . FOLDER_DATA . '/user_profile_photos/' . (int) $user->getValue('usr_id') . '.jpg';
    }

    private function loadUser(string $uuid): User
    {
        $user = new User($this->db, $this->profileFields);
        if ($uuid === '' || !$user->readDataByUuid($uuid) || (int) $user->getValue('usr_id') <= 0) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }
        return $user;
    }

    /**
     * Der API-Benutzer muss das Profil des Mitglieds bearbeiten dürfen.
     */
    private function assertCanEdit(User $user): void
    {
        $apiUser = ApiUser::require();
        if (!$apiUser->hasRightEditProfile($user)) {
            throw new ApiException('API user may not edit this profile.', 'forbidden', 403);
        }
    }
}
