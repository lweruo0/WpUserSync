<?php
declare(strict_types=1);

namespace WpUserSync\classes;

final class Config
{
    /**
     * @return array{
     *     config_file: string,
     *     config_file_exists: bool,
     *     parameters: array<string, array<string, mixed>>
     * }
     */
    public static function resolve(string $pluginDir): array
    {
        $definitions = self::getParameterDefinitions();
        $configFile = self::getConfigFilePath($pluginDir);
        $parameters = array();

        foreach ($definitions as $key => $definition) {
            $variable = $definition['variable'];
            $isSet = array_key_exists($variable, $GLOBALS);
            $value = self::castValue(
                $isSet ? $GLOBALS[$variable] : $definition['default'],
                $definition['type']
            );

            $parameters[$key] = array(
                'key' => $key,
                'variable' => $variable,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'type' => $definition['type'],
                'default' => $definition['default'],
                'value' => $value,
                'is_set' => $isSet,
                'uses_default' => !$isSet,
                'required' => $definition['required'],
            );
        }

        return array(
            'config_file' => $configFile,
            'config_file_exists' => is_file($configFile),
            'parameters' => $parameters,
        );
    }

    public static function getConfigFilePath(string $pluginDir): string
    {
        $admidioRoot = dirname(dirname($pluginDir));

        return $admidioRoot . '/adm_my_files/config.php';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function getParameterDefinitions(): array
    {
        return array(
            'enabled' => array(
                'variable' => 'plg_wpusersync_enabled',
                'default' => true,
                'type' => 'bool',
                'required' => false,
                'label' => 'Plugin aktiviert',
                'description' => 'Schaltet alle API-Endpoints ein oder aus.',
            ),
            'require_https' => array(
                'variable' => 'plg_wpusersync_require_https',
                'default' => true,
                'type' => 'bool',
                'required' => false,
                'label' => 'HTTPS erforderlich',
                'description' => 'Lehnt API-Requests ohne HTTPS ab.',
            ),
            'assign_default_roles' => array(
                'variable' => 'plg_wpusersync_assign_default_roles',
                'default' => true,
                'type' => 'bool',
                'required' => false,
                'label' => 'Standard-Rollen zuweisen',
                'description' => 'Weist neuen Benutzern die Admidio-Standard-Rollen zu.',
            ),
            'api_token_hash' => array(
                'variable' => 'plg_wpusersync_api_token_hash',
                'default' => '',
                'type' => 'string',
                'required' => true,
                'label' => 'API-Token-Hash',
                'description' => 'SHA-256-Hash des geheimen API-Tokens (hex, 64 Zeichen).',
            ),
            'nonce_max_age' => array(
                'variable' => 'plg_wpusersync_nonce_max_age',
                'default' => 300,
                'type' => 'int',
                'required' => false,
                'label' => 'Nonce-Gültigkeit',
                'description' => 'Maximales Alter der X-Api-Nonce in Sekunden (Replay-Schutz).',
            ),
        );
    }

    private static function castValue(mixed $value, string $type): bool|int|string
    {
        return match ($type) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            default => (string) $value,
        };
    }
}
