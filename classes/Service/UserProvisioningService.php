<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Infrastructure\Exception;
use Admidio\Categories\Entity\Category;
use Admidio\Roles\Entity\Role;
use Admidio\Roles\Entity\RolesRights;
use Admidio\Users\Entity\UserImport;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Users\Lists\UserList;
use Admidio\Users\Entity\User;
use Admidio\ProfileFields\ValueObjects\ProfileFields;

use PDO;

final class UserProvisioningService
{
    private $db;
    private $profileFields;
    private RoleMapper $roleMapper;
    private array $config;

    public function __construct($db, $profileFields, array $config)
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

        $externalField = trim((string) ($this->config['external_id_field'] ?? ''));
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

        if ($isNew && !empty($this->config['assign_default_roles'])) {
            $user->assignDefaultRoles();
        }

        $roleIds = $this->roleMapper->resolveRoleIds(
            $payload['roles'],
            (string) ($this->config['role_map_json'] ?? ''),
            (string) ($this->config['default_role'] ?? '')
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
        $vorname = trim((string) ($payload['first_name'] ?? ''));
        $nachname = trim((string) ($payload['last_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
    
        if ($vorname !== '' && $nachname !== '' && $email !== '') {
            return $this->findUserIdbyFirstnameLastnameBirthday($vorname, $nachname, $email);
        }

        return null;
    }

    public function findUserIdbyFirstnameLastnameBirthday(string $firstName, string $lastName, string $birthday): ?int
    {
        // search for existing user with same name and read user data
        $sql = 'SELECT MAX(usr_id) AS usr_id
                  FROM '.TBL_USERS.'
            INNER JOIN '.TBL_USER_DATA.' AS last_name
                    ON last_name.usd_usr_id = usr_id
                   AND last_name.usd_usf_id = ? -- $gProfileFields->getProperty(\'LAST_NAME\', \'usf_id\')
                   AND last_name.usd_value  = ? -- $user->getValue(\'LAST_NAME\', \'database\')
            INNER JOIN '.TBL_USER_DATA.' AS birthday
                    ON birthday.usd_usr_id = usr_id
                   AND birthday.usd_usf_id = ? -- $gProfileFields->getProperty(\'BIRTHDAY\', \'usf_id\')
                   AND birthday.usd_value  = ? -- $user->getValue(\'BIRTHDAY\', \'database\')
            INNER JOIN '.TBL_USER_DATA.' AS first_name
                    ON first_name.usd_usr_id = usr_id
                   AND first_name.usd_usf_id = ? -- $gProfileFields->getProperty(\'FIRST_NAME\', \'usf_id\')
                   AND first_name.usd_value  = ? -- $user->getValue(\'FIRST_NAME\', \'database\')
                 WHERE usr_valid = true';
        $queryParams = array(
            $this->profileFields->getProperty('LAST_NAME', 'usf_id'),
            $lastName,
            $this->profileFields->getProperty('BIRTHDAY', 'usf_id'),
            $birthday,
            $this->profileFields->getProperty('FIRST_NAME', 'usf_id'),
            $firstName
        );
        $pdoStatement = $this->db->queryPrepared($sql, $queryParams);
        $maxUserId = (int) $pdoStatement->fetchColumn();

        return $maxUserId > 0 ? $maxUserId : null;
    }

}
