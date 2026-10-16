<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use RuntimeException;

final class FieldAllowlist
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public static function getSettingsPath(string $pluginDir): string
    {
        return dirname(dirname($pluginDir))
            . DIRECTORY_SEPARATOR . 'adm_my_files'
            . DIRECTORY_SEPARATOR . 'wpusersync-profile-fields.json';
    }

    public function load(): array
    {
        if (!is_file($this->filePath)) {
            return array('read' => array(), 'write' => array());
        }

        $contents = file_get_contents($this->filePath);
        if ($contents === false) {
            throw new RuntimeException('Could not read the WpUserSync field allowlist.');
        }

        $settings = json_decode($contents, true);
        if (!is_array($settings)) {
            throw new RuntimeException('The WpUserSync field allowlist file contains invalid JSON.');
        }

        $readFields = $settings['read'] ?? array();
        $writeFields = $settings['write'] ?? array();
        if (!is_array($readFields) || !is_array($writeFields)) {
            throw new RuntimeException('The WpUserSync field allowlist has an invalid format.');
        }

        return array(
            'read' => self::normalize($readFields),
            'write' => self::normalize($writeFields),
        );
    }

    public function save(array $readFields, array $writeFields): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('The Admidio adm_my_files directory must be writable by PHP to save the field allowlist.');
        }

        $contents = json_encode(
            array(
                'read' => self::normalize($readFields),
                'write' => self::normalize($writeFields),
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($contents === false) {
            throw new RuntimeException('Could not encode the WpUserSync field allowlist.');
        }

        $temporaryPath = tempnam($directory, '.wpusersync-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Could not create a temporary field allowlist file.');
        }

        try {
            if (file_put_contents($temporaryPath, $contents . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Could not write the WpUserSync field allowlist.');
            }
            @chmod($temporaryPath, 0600);
            if (!rename($temporaryPath, $this->filePath)) {
                throw new RuntimeException('Could not replace the WpUserSync field allowlist file.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private static function normalize(array $fields): array
    {
        $normalized = array();
        foreach ($fields as $field) {
            if (is_string($field) && $field !== '') {
                $normalized[] = $field;
            }
        }

        return array_values(array_unique($normalized));
    }
}