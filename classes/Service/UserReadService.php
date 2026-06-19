<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use Admidio\Users\Entity\User;
use Admidio\Roles\Entity\Role;
use Admidio\Roles\Entity\Membership;
use Admidio\Roles\Entity\RolesRights;

define('TBL_USER_ARBEITSDIENST', 'adm_user_arbeitsdienst');


final class UserReadService
{
    private Database $db;
    private ProfileFields $profileFields;
    private array $query;
    private array $existingFieldNames = array();

    public function __construct(Database $db, ProfileFields $profileFields, array $query = [])
    {
        $this->db = $db;
        $this->profileFields = $profileFields;
        $this->query = $query;
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
     * GET /core/search – Search for users
     */
    public function searchUser(): array
    {
        $query = $this->query;
        $firstName = trim((string) ($query['firstName'] ?? ''));
        $lastName = trim((string) ($query['lastName'] ?? ''));
        $birthday = trim((string) ($query['birthday'] ?? ''));

        // Require all search criteria so the SQL can stay static and deterministic.
        if ($firstName === '' || $lastName === '' || $birthday === '') {
                return [
                        'userId' => 0,
                        'useruuid' => '',
                        'loginName' => '',
                        'firstName' => '',
                        'lastName' => '',
                        'birthday' => '',
                        'exists' => false
                ];
        }

        $sql = 'SELECT u.usr_id, u.usr_login_name, u.usr_uuid,
                   first_name.usd_value AS first_name,
                   last_name.usd_value AS last_name,
                   birthday.usd_value AS birthday
              FROM ' . TBL_USERS . ' AS u
            INNER JOIN ' . TBL_USER_DATA . ' AS first_name
                ON first_name.usd_usr_id = u.usr_id
               AND first_name.usd_usf_id = ?
               AND first_name.usd_value = ?
            INNER JOIN ' . TBL_USER_DATA . ' AS last_name
                ON last_name.usd_usr_id = u.usr_id
               AND last_name.usd_usf_id = ?
               AND last_name.usd_value = ?
            INNER JOIN ' . TBL_USER_DATA . ' AS birthday
                ON birthday.usd_usr_id = u.usr_id
               AND birthday.usd_usf_id = ?
               AND birthday.usd_value = ?
             WHERE u.usr_valid = true
             ORDER BY u.usr_id DESC LIMIT 1';

        $queryParams = array(
            $this->profileFields->getProperty('FIRST_NAME', 'usf_id'),
            $firstName,
            $this->profileFields->getProperty('LAST_NAME', 'usf_id'),
            $lastName,
            $this->profileFields->getProperty('BIRTHDAY', 'usf_id'),
            $birthday
        );

        $result = $this->db->queryPrepared($sql, $queryParams);
        $users = [];
        $row = $result->fetch();
        if ($row) {
            return [
                'userId' => (int) $row['usr_id'],
                'useruuid' => $row['usr_uuid'],
                'loginName' => (string) $row['usr_login_name'],
                'firstName' => (string) ($row['first_name'] ?? ''),
                'lastName' => (string) ($row['last_name'] ?? ''),
                'birthday' => (string) ($row['birthday'] ?? ''),

                'sql' => $sql,
                'queryParams' => $queryParams,


                'exists' => true
            ];
        } else {
            return [
                'userId' => 0,
                'useruuid' => '',
                'loginName' => '',
                'firstName' => '',
                'lastName' => '',
                'birthday' => '',
                'exists' => false
            ];
        }
    }



    /**
     * GET /core/users – List all users
     */
    public function listUsers(): array
    {
        $query = $this->query;

        $limit = min((int) ($query['limit'] ?? 50), 500);
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $sql = 'SELECT u.usr_id, u.usr_login_name, u.usr_uuid,
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

        $sql .= ' ORDER BY u.usr_id LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $result = $this->db->queryPrepared($sql, $queryParams);
        $users = [];
        while ($row = $result->fetch()) {
            $users[] = [
                'usr_id' => (int) $row['usr_id'],
                'usr_uuid' => $row['usr_uuid'],
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
        $this->assertUserExists($userId);
        return array(
            'status' => 'success',
            'usr_id' => $userId,
        );
    }




    /**
     * GET /core/users/{userId}/fields – Get all custom fields for user
     */
    public function getUserFields(int $userId): array
    {
        $this->assertUserExists($userId);

        $user = new User($this->db, $this->profileFields, $userId);

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
        $this->assertUserExists($userId);

        $user = new User($this->db, $this->profileFields, $userId);

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
     * GET /core/users/{userId}/memberships – Get all roles for user
     */
    public function getUserMemberships(int $userId, ?int $year=null): array
    {
        $this->assertUserExists($userId);
        $user = new User($this->db, $this->profileFields, $userId);

        $roles = array();
        $queryParams = array($userId);
        $sql = 'SELECT mem_id, mem_rol_id, mem_begin, mem_end, rol_name, rol_cost
                FROM ' . TBL_MEMBERS . '
                LEFT JOIN ' . TBL_ROLES . ' ON mem_rol_id = rol_id
                WHERE mem_usr_id = ?';
        if ($year !== null) {
            $sql .= ' AND ((mem_begin IS NULL OR mem_begin <= ?) AND (mem_end IS NULL OR mem_end >= ?))';
            $queryParams[] = $year . '-12-31';
            $queryParams[] = $year . '-01-01';
        }

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
     * GET /core/users/{userId}/arbeitsdienst – Get all roles for user
     */
    public function getUserArbeitsdienst(int $userId, ?int $year=null): array
    {
        $this->assertUserExists($userId);
        $user = new User($this->db, $this->profileFields, $userId);

        $roles = array();
        $queryParams = array($userId);
        $sql = 'SELECT pad_id, pad_user_id, pad_date, pad_name, pad_hours
                FROM ' . TBL_USER_ARBEITSDIENST . '
                WHERE pad_user_id = ?';
        if ($year !== null) {
            $sql .= ' AND ((pad_date IS NULL OR pad_date <= ?) AND (pad_date IS NULL OR pad_date >= ?))';
            $queryParams[] = $year . '-12-31';
            $queryParams[] = $year . '-01-01';
        }

        $memberStatement = $this->db->queryPrepared($sql, $queryParams);
      
        while ($row = $memberStatement->fetch()) {
            $roles[] = [
                'pad_id' => (int) $row['pad_id'],
                'pad_user_id' => (string) $row['pad_user_id'],
                'pad_name' => (string) $row['pad_name'],
                'pad_hours' => (string) $row['pad_hours'],
                'pad_date' => (string) ($row['pad_date'] ?? ''),
            ];
        }   


        if (function_exists('list_members')) {

            // alle aktiven Mitglieder einlesen
            $members = list_members($year, 
                                    array(
                                        'FIRST_NAME',
                                        'LAST_NAME',
                                        'BIRTHDAY',
                                        'GENDER'), 
                                    array('Mitglied' => $userId), ' AND mem.mem_usr_id = '.$userId. ' ');

            // Informationen aller Mitglieder zum Arbeitsdienst einslesen
            $membersworkinfo = list_members_workinfo($members, 
                                                    $year);

        } else {
            $membersworkinfo = ' Arbeitsdienstinformationen können nicht eingelesen werden';
            // Arbeitsdienstinformationen können nicht eingelesen werden, da die Funktionen aus dem Arbeitsdienstplugin nicht verfügbar sind
        }

        return array(
            'status' => 'success',
            'user_id' => $userId,
            'data' => $roles,
            'sumworking' => $membersworkinfo
        );
    }

    private function assertUserExists(int $userId): void
    {
        $sql = 'SELECT 1 FROM ' . TBL_USERS . ' WHERE usr_id = ?';
        $result = $this->db->queryPrepared($sql, [(int) $userId]);
        if (!$result->fetch()) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }
    }

}
