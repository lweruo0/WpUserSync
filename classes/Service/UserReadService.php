<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use Admidio\Users\Entity\User;
use Admidio\Roles\Entity\Role;
use Admidio\Roles\Entity\Membership;
use Admidio\Roles\Entity\RolesRights;

final class UserReadService
{
    private Database $db;
    private ProfileFields $profileFields;
    private array $query;
    private array $payload;
    private array $existingFieldNames = array();

    public function __construct(Database $db, ProfileFields $profileFields, array $query = [], array $payload = [])
    {
        $this->db = $db;
        $this->profileFields = $profileFields;
        $this->query = $query;
        $this->payload = $payload;
        foreach ($this->profileFields->getProfileFields() as $field) {
            $this->existingFieldNames[] = $field->getValue('usf_name_intern');
        }
    }
    /**
     * GET /core/roles – List all roles
     */
    public function listRoles(): array
    {
        $limit = min((int) ($query['limit'] ?? 50), 500);
        $offset = max(0, (int) ($query['offset'] ?? 0));

        // read information about the roles
        $sql = 'SELECT rol_id, rol_name, rol_valid, rol_uuid, rol_cat_id
            FROM ' . TBL_ROLES . '
            WHERE rol_valid = true';
        $sql .= ' ORDER BY rol_id LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $rolesStatement = $this->db->queryPrepared($sql);
        $rolesData = $rolesStatement->fetchAll();
        
        return [
            'status' => 'success',
            'data' => $rolesData,
            'count' => count($rolesData),
            'offset' => $offset,
            'limit' => $limit,
        ];

    }

    /**
     * GET /core/categories – List all categories
     */
    public function listCategories($type = null): array
    {
        $limit = min((int) ($query['limit'] ?? 50), 500);
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $Condition = $type !== null ? ' WHERE cat_type = ?' : '';
        // read information about the categories
        $sql = 'SELECT cat_id, cat_org_id, cat_name_intern, cat_uuid
            FROM ' . TBL_CATEGORIES . $Condition;

        $sql .= ' ORDER BY cat_id LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;


        $rolesStatement = $this->db->queryPrepared($sql, $type !== null ? [(string) $type] : []);
        $rolesData = $rolesStatement->fetchAll();   
        return [
            'status' => 'success',
            'data' => $rolesData,
            'count' => count($rolesData),
            'offset' => $offset,
            'limit' => $limit,
        ];
    }


    /**
     * GET /core/users – List all users
     */
    public function listUsers(): array
    {
        $query = $this->query;

        $limit = min((int) ($query['limit'] ?? 50), 500);
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $filters = is_array($this->payload['filter'] ?? null) ? $this->payload['filter'] : array();
        $firstName = trim((string) ($filters['firstName'] ?? ''));
        $lastName = trim((string) ($filters['lastName'] ?? ''));
        $birthday = trim((string) ($filters['birthday'] ?? ''));

        $sql = 'SELECT u.usr_id, u.usr_login_name,
                       first_name.usd_value AS first_name,
                       last_name.usd_value AS last_name,
                       birthday.usd_value AS birthday
                  FROM ' . TBL_USERS . ' AS u
            LEFT JOIN ' . TBL_USER_DATA . ' AS first_name
                    ON first_name.usd_usr_id = u.usr_id
                   AND first_name.usd_usf_id = ?
            LEFT JOIN ' . TBL_USER_DATA . ' AS last_name
                    ON last_name.usd_usr_id = u.usr_id
                   AND last_name.usd_usf_id = ?
            LEFT JOIN ' . TBL_USER_DATA . ' AS birthday
                    ON birthday.usd_usr_id = u.usr_id
                   AND birthday.usd_usf_id = ?
                 WHERE u.usr_valid = true';

        $queryParams = array(
            $this->profileFields->getProperty('FIRST_NAME', 'usf_id'),
            $this->profileFields->getProperty('LAST_NAME', 'usf_id'),
            $this->profileFields->getProperty('BIRTHDAY', 'usf_id')
        );

        if ($firstName !== '') {
            $sql .= ' AND first_name.usd_value = ?';
            $queryParams[] = $firstName;
        }

        if ($lastName !== '') {
            $sql .= ' AND last_name.usd_value = ?';
            $queryParams[] = $lastName;
        }

        if ($birthday !== '') {
            $sql .= ' AND birthday.usd_value = ?';
            $queryParams[] = $birthday;
        }

        $sql .= ' ORDER BY u.usr_id LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $result = $this->db->queryPrepared($sql, $queryParams);
        $users = [];
        while ($row = $result->fetch()) {
            $users[] = [
                'usr_id' => (int) $row['usr_id'],
                'usr_login_name' => (string) $row['usr_login_name'],
                'FIRST_NAME' => (string) ($row['first_name'] ?? ''),
                'LAST_NAME' => (string) ($row['last_name'] ?? ''),
                'BIRTHDAY' => (string) ($row['birthday'] ?? ''),
            ];
        }

        return [
            'status' => 'success',
            'data' => $users,
            'count' => count($users),
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    /**
     * GET /core/users/{userId} – Get single user
     */
    public function getUser(int $userId): array
    {
        $user = new User($this->db, $this->profileFields, $userId);
        $usrId = $user->getValue('usr_id');

        if ($usrId === 0) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }

        return array(
            'status' => 'success',
            'usr_id' => $userId,
            'data' => array('usr_id' => usrId,
                            'usr_login_name' => $user->getValue('usr_login_name', 'database'),
                            'FIRST_NAME' => $user->getValue('FIRST_NAME', 'database'),
                            'LAST_NAME' => $user->getValue('LAST_NAME', 'database'),
                            'BIRTHDAY' => $user->getValue('BIRTHDAY', 'database')),
        );
    }




    /**
     * GET /core/users/{userId}/fields – Get all custom fields for user
     */
    public function getUserFields(int $userId): array
    {
        $user = new User($this->db, $this->profileFields, $userId);
        $usrId = $user->getValue('usr_id');

        if ($usrId === 0) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }

        $fields = array();

        foreach ($this->existingFieldNames as $fieldName) {
            $fields[$fieldName] = $user->getValue($fieldName, 'database');
        }

        return array(
            'status' => 'success',
            'user_id' => $userId,
            'data' => $fields,
        );
    }

    /**
     * GET /core/users/{userId}/fields/{name} – Get single custom field
     */
    public function getUserField(int $userId, string $name): array
    {
        $user = new User($this->db, $this->profileFields, $userId);
        $usrId = $user->getValue('usr_id');

        if ($usrId === 0) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }

        if (!in_array($name, $this->existingFieldNames)){
            throw new ApiException('Fieldname not found.', 'field_not_found', 404);            
        }

        return array(
            'status' => 'success',
            'user_id' => $userId,
            'data' => $user->getValue($name, 'database'),
        );
    }

    /**
     * GET /core/users/{userId}/next_memberships – get possible next memberships for user based on current memberships and role/list/org structure
     */
    public function getUserNextMemberships(int $userId): array
    {
        $user = new User($this->db, $this->profileFields, $userId);
        $usrId = $user->getValue('usr_id');

        if ($usrId === 0) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }


    }


    /**
     * GET /core/users/{userId}/memberships – Get all roles for user
     */
    public function getUserMemberships(int $userId): array
    {
        $user = new User($this->db, $this->profileFields, $userId);
        $usrId = $user->getValue('usr_id');

        if ($usrId === 0) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }

        $roles = array();

        $sql = 'SELECT mem_id, mem_rol_id, mem_begin, mem_end, rol_name, rol_cost
                FROM ' . TBL_MEMBERS . '
                LEFT JOIN ' . TBL_ROLES . ' ON mem_rol_id = rol_id
                WHERE mem_usr_id = ?';
        $queryParams = array($userId);
        $memberStatement = $this->db->queryPrepared($sql, $queryParams);
      
        while ($row = $memberStatement->fetch()) {
            $role = new Role($this->db, $row['mem_rol_id']);
            $roles[] = [
                'mem_id' => (int) $row['mem_id'],
                'mem_rol_id' => (string) $row['mem_rol_id'],
                'rol_name' => (string) $row['rol_name'],
                'rol_cost' => (string) $row['rol_cost'],

                'mem_begin' => (string) ($row['mem_begin'] ?? ''),
                'mem_end' => (string) ($row['mem_end'] ?? ''),
            ];
        }   

        return array(
            'status' => 'success',
            'user_id' => $userId,
            'data' => $roles,
        );
    }


    /**
     * GET /core/users/{userId}/lists – Get lists user is member of
     */
    public function getUserLists(int $userId): array
    {
        $this->assertUserExists($userId);

        $sql = 'SELECT DISTINCT lst_id, lst_name 
                FROM ' . TBL_LISTS . ' 
                INNER JOIN ' . TBL_LIST_MEMBERS . ' ON lsm_lst_id = lst_id 
                WHERE lsm_usr_id = ? 
                ORDER BY lst_name';

        $result = $this->db->queryPrepared($sql, [(int) $userId]);
        $lists = [];
        while ($row = $result->fetch()) {
            $lists[] = [
                'id' => (int) $row['lst_id'],
                'name' => (string) $row['lst_name'],
            ];
        }

        return [
            'status' => 'success',
            'data' => $lists,
            'count' => count($lists),
        ];
    }

    /**
     * GET /core/users/{userId}/lists/{listId} – Get list details
     */
    public function getUserList(int $userId, int $listId): array
    {
        $this->assertUserExists($userId);

        $sql = 'SELECT lst_id, lst_name, lst_description 
                FROM ' . TBL_LISTS . ' 
                INNER JOIN ' . TBL_LIST_MEMBERS . ' ON lsm_lst_id = lst_id 
                WHERE lst_id = ? AND lsm_usr_id = ?';

        $result = $this->db->queryPrepared($sql, [(int) $listId, (int) $userId]);
        $row = $result->fetch();

        if (!$row) {
            throw new ApiException('List membership not found.', 'list_not_found', 404);
        }

        return [
            'status' => 'success',
            'data' => [
                'id' => (int) $row['lst_id'],
                'name' => (string) $row['lst_name'],
                'description' => (string) ($row['lst_description'] ?? ''),
            ],
        ];
    }


    /**
     * GET /core/users/{userId}/memberships/{memId} – Get single membership
     */
    public function getUserMembership(int $userId, int $memId): array
    {
        $this->assertUserExists($userId);

        $sql = 'SELECT mem_id, mem_rol_id, mem_org_id, mem_begin, mem_end, rol_name, org_shortname 
                FROM ' . TBL_MEMBERS . ' 
                LEFT JOIN ' . TBL_ROLES . ' ON mem_rol_id = rol_id 
                LEFT JOIN ' . TBL_ORGANIZATIONS . ' ON mem_org_id = org_id 
                WHERE mem_id = ? AND mem_usr_id = ?';

        $result = $this->db->queryPrepared($sql, [(int) $memId, (int) $userId]);
        $row = $result->fetch();

        if (!$row) {
            throw new ApiException('Membership not found.', 'membership_not_found', 404);
        }

        return [
            'status' => 'success',
            'data' => [
                'id' => (int) $row['mem_id'],
                'roleId' => $row['mem_rol_id'] ? (int) $row['mem_rol_id'] : null,
                'roleName' => $row['rol_name'] ? (string) $row['rol_name'] : null,
                'orgId' => $row['mem_org_id'] ? (int) $row['mem_org_id'] : null,
                'orgName' => $row['org_shortname'] ? (string) $row['org_shortname'] : null,
                'beginDate' => (string) $row['mem_begin'],
                'endDate' => (string) $row['mem_end'],
            ],
        ];
    }

    /**
     * GET /core/users/{userId}/memberships/role/{roleId} – Get memberships for role
     */
    public function getUserMembershipsForRole(int $userId, int $roleId): array
    {
        $this->assertUserExists($userId);

        $sql = 'SELECT mem_id, mem_org_id, mem_begin, mem_end, org_shortname 
                FROM ' . TBL_MEMBERS . ' 
                LEFT JOIN ' . TBL_ORGANIZATIONS . ' ON mem_org_id = org_id 
                WHERE mem_usr_id = ? AND mem_rol_id = ? 
                ORDER BY mem_begin DESC';

        $result = $this->db->queryPrepared($sql, [(int) $userId, (int) $roleId]);
        $memberships = [];
        while ($row = $result->fetch()) {
            $memberships[] = [
                'id' => (int) $row['mem_id'],
                'roleId' => $roleId,
                'orgId' => $row['mem_org_id'] ? (int) $row['mem_org_id'] : null,
                'orgName' => $row['org_shortname'] ? (string) $row['org_shortname'] : null,
                'beginDate' => (string) $row['mem_begin'],
                'endDate' => (string) $row['mem_end'],
            ];
        }

        return [
            'status' => 'success',
            'data' => $memberships,
            'count' => count($memberships),
        ];
    }

    /**
     * GET /core/users/{userId}/memberships/organization/{orgId} – Get memberships for org
     */
    public function getUserMembershipsForOrg(int $userId, int $orgId): array
    {
        $this->assertUserExists($userId);

        $sql = 'SELECT mem_id, mem_rol_id, mem_begin, mem_end, rol_name 
                FROM ' . TBL_MEMBERS . ' 
                LEFT JOIN ' . TBL_ROLES . ' ON mem_rol_id = rol_id 
                WHERE mem_usr_id = ? AND mem_org_id = ? 
                ORDER BY mem_begin DESC';

        $result = $this->db->queryPrepared($sql, [(int) $userId, (int) $orgId]);
        $memberships = [];
        while ($row = $result->fetch()) {
            $memberships[] = [
                'id' => (int) $row['mem_id'],
                'roleId' => $row['mem_rol_id'] ? (int) $row['mem_rol_id'] : null,
                'roleName' => $row['rol_name'] ? (string) $row['rol_name'] : null,
                'orgId' => $orgId,
                'beginDate' => (string) $row['mem_begin'],
                'endDate' => (string) $row['mem_end'],
            ];
        }

        return [
            'status' => 'success',
            'data' => $memberships,
            'count' => count($memberships),
        ];
    }

    private function assertUserExists(int $userId): void
    {
        $sql = 'SELECT 1 FROM ' . TBL_USERS . ' WHERE usr_id = ?';
        $result = $this->db->queryPrepared($sql, [(int) $userId]);
        if (!$result->fetch()) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }
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
