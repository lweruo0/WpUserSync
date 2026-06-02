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
    private array $existingFieldNames = array();

    public function __construct($db, $profileFields, array $config)
    {
        $this->db = $db;
        $this->profileFields = $profileFields;

        foreach ($this->profileFields->getProfileFields() as $field) {
            $this->existingFieldNames[] = $field->getValue('usf_name_intern');
        }

        $this->roleMapper = new RoleMapper($db);
        $this->config = $config;
    }

    public function upsert(array $payload): array
    {
        $profileData = $payload['profile'] ?? array();    
    
        $userId = $this->findUserId($profileData);
        $user = new User($this->db, $this->profileFields, $userId ?? 0);
        $isNew = $userId === null;


        foreach ($profileData as $fieldName => $value) {
            if (in_array($fieldName, $this->existingFieldNames, true)) {
                $user->setValue($fieldName, $value);
            }
        }

        $user->saveChangesWithoutRights();
        $user->save();
        $usr_id = (int) $user->getValue('usr_id');

        if ($isNew && !empty($this->config['assign_default_roles'])) {
            $user->assignDefaultRoles();
        }

        $roleIds = array();
        $roles = is_array($payload['roles'] ?? null) ? $payload['roles'] : array();
        foreach ($roles as $role -> $roledata) {
            $roleId = $this->assignRoleToUserByName($usr_id, $role, $roledata['start_date'] ?? '', $roledata['end_date'] ?? '');
            if (isset($roleId)) {
                $roleIds[] = $roleId;
            }
        }

        return array(
            'status' => $isNew ? 'created' : 'updated',
            'user_id' => (int) $user->getValue('usr_id'),
            'email' => $profileData['EMAIL'] ?? '',
            'roles_applied' => $roleIds,
        );
    }

    public function assignRoleToUserByName(int $userId, string $roleName, string $startDate = '', string $endDate = ''): bool
    {
        global $gDb, $gCurrentOrgId;

        $roleName = trim($roleName);
        if ($userId <= 0 || $roleName === '') {
            echo "Role '" . $roleName . "' not found. Cannot assign to user ID " . $userId . ".<br />";
            return null;
        }

        $role = new Role($gDb);
        $role->readDataByColumns(array(
            'rol_name' => $roleName,
            'cat_org_id' => $gCurrentOrgId
        ));

        if ($role->isNewRecord()) {
            echo "Role '" . $roleName . "' not found. Cannot assign to user ID " . $userId . ".<br />";
            return null;
        }

        $startDate = $this->normalizeDateToYmd($startDate) ?: DATE_NOW;
        $endDate = $this->normalizeDateToYmd($endDate) ?: DATE_MAX;

        $role->setMembership($userId, $startDate, $endDate, false, true);

        return (int) $role->getValue('rol_id');
    }


    private function findUserId(array $data): ?int
    {
        $vorname = trim((string) ($data['FIRST_NAME'] ?? ''));
        $nachname = trim((string) ($data['LAST_NAME'] ?? ''));
        $birthday = trim((string) ($data['BIRTHDAY'] ?? ''));
    
        if ($vorname !== '' && $nachname !== '' && $birthday !== '') {
            return $this->findUserIdbyFirstnameLastnameBirthday($vorname, $nachname, $birthday);
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
