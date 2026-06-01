<?php

declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\Service\ProfileFields;
use Admidio\Users\Entity\User;
use PDO;

final class UserProvisioningService
{
    private Database $db;
    private ProfileFields $profileFields;
    private RoleMapper $roleMapper;
    private array $config;

    public function __construct(Database $db, ProfileFields $profileFields, array $config)
    {
        $this->db = $db;
        $this->profileFields = $profileFields;
        $this->roleMapper = new RoleMapper($db);
        $this->config = $config;
    }

    public function upsert(array $payload): array
    {
        $userId = $this->findUserId($payload);
        $user = new User($this->db, $this->profileFields, $userId ?? 0);
        $isNew = $userId === null;

        $user->setValue('FIRST_NAME', $payload['first_name']);
        $user->setValue('LAST_NAME', $payload['last_name']);
        $user->setValue('EMAIL', $payload['email']);

        if ($payload['username'] !== '') {
            $user->setValue('usr_login_name', $payload['username']);
        }

        $externalField = trim((string) ($this->config['wp_user_sync_external_id_field'] ?? ''));
        if ($externalField !== '' && $payload['external_id'] !== '') {
            $user->setValue($externalField, $payload['external_id']);
        }

        foreach ($payload['profile'] as $fieldName => $value) {
            if (!is_string($fieldName) || trim($fieldName) === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $user->setValue(trim($fieldName), $value === null ? '' : (string) $value);
            }
        }

        $user->save();

        if ($isNew && !empty($this->config['wp_user_sync_assign_default_roles'])) {
            $user->assignDefaultRoles();
        }

        $roleIds = $this->roleMapper->resolveRoleIds(
            $payload['roles'],
            (string) ($this->config['wp_user_sync_role_map_json'] ?? ''),
            (string) ($this->config['wp_user_sync_default_role'] ?? '')
        );

        foreach ($roleIds as $roleId) {
            $user->setRoleMembership($roleId);
        }

        return array(
            'status' => $isNew ? 'created' : 'updated',
            'user_id' => (int) $user->getValue('usr_id'),
            'external_id' => $payload['external_id'],
            'email' => $payload['email'],
            'roles_applied' => $roleIds,
        );
    }

    private function findUserId(array $payload): ?int
    {
        $externalField = trim((string) ($this->config['wp_user_sync_external_id_field'] ?? ''));
        if ($externalField !== '' && $payload['external_id'] !== '') {
            $userId = $this->findUserIdByExternalField($externalField, $payload['external_id']);
            if ($userId !== null) {
                return $userId;
            }
        }

        if (!empty($this->config['wp_user_sync_update_existing_by_email'])) {
            $user = new User($this->db, $this->profileFields);
            if ($user->readDataByColumns(array('usr_email' => $payload['email']))) {
                return (int) $user->getValue('usr_id');
            }
        }

        return null;
    }

    private function findUserIdByExternalField(string $fieldNameIntern, string $externalId): ?int
    {
        $fieldId = (int) $this->profileFields->getProperty($fieldNameIntern, 'usf_id');
        if ($fieldId <= 0) {
            return null;
        }

        $sql = 'SELECT usd_usr_id FROM ' . TBL_USER_DATA . ' WHERE usd_usf_id = ? AND usd_value = ?';
        $statement = $this->db->queryPrepared($sql, array($fieldId, $externalId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['usd_usr_id'] : null;
    }
}
