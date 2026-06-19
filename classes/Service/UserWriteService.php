<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;
use Admidio\Users\Entity\User;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use Admidio\Infrastructure\Database;


define('TBL_USER_ARBEITSDIENST', 'adm_user_arbeitsdienst');

final class UserWriteService
{
    private Database $db;
    private ProfileFields $profileFields;
    private array $query;
    private array $payload;
    private int $gCurrentOrgId;
    private Database $gDb;
    private ProfileFields $gProfileFields;


    
    public function __construct(Database $db, ProfileFields $profileFields, array $query = [], array $payload = [])
    {
        $this->db = $db;
        $this->profileFields = $profileFields;
        $this->gCurrentOrgId= $GLOBALS['gCurrentOrgId'];
        $this->gProfileFields = $GLOBALS['gProfileFields'];
        $this->gDb = $GLOBALS['gDb'];
        $this->query = $query;
        $this->payload = $payload;
    }

    /**
     * POST /core/users/{uuid}/fields – set multiple custom fields
     */
    public function setUserField(int $uuid): array
    {
        $payload = $this->payload;
        $userId = $this->assertUUIDExists($uuid);

        $user = new User($this->db, $this->profileFields, $userId);

        if (!is_array($payload['data'] ?? null)) {
            throw new ApiException('data value must be an array.', 'validation_failed', 422);
        }

        $resultdata = [];
        foreach ($payload['data'] as $fieldName => $value) {
            if ( $user->setValue($fieldName, $value)){
                $resultdata[$fieldName] =  $value;
            }   
        }   

        $user->saveChangesWithoutRights();
        $user->save();

        return [
            'status' => 'success',
            'data' => $resultdata,
        ];
    }

    /**
     * POST /core/users/{uuid}/fields/{name} – Set a custom field by name
     */
    public function setUserFieldByName(string $uuid, string $name): array
    {
        $payload = $this->payload;
        $userId = $this->assertUUIDExists($uuid);

        $value = (string) ($payload['data'] ?? '');
        
        if (is_array($payload['data'] ?? null)) {
            throw new ApiException('Field value must be a string.', 'validation_failed', 422);
        }

        if ($name === '') {
            throw new ApiException('Field name is required.', 'validation_failed', 422);
        }

        $user = new User($this->db, $this->profileFields, $userId);
        $user->setValue($name, $value);
        $user->saveChangesWithoutRights();
        $user->save();

        return [
            'status' => 'success',
            'data' => [
                'name' => $name,
                'value' => $value,
            ],
        ];
    }

    /**
     * POST /core/users/{uuid}/arbeitsdienst/{year} – Set a custom field by name
     */
    public function setUserArbeitsdienst(string $uuid, int $year): array
    {
        $payload = $this->payload;
        $userId = $this->assertUUIDExists($uuid);

        $data = $payload['data'] ?? array();
        $check_dublicates = $payload['check_dublicates'] ?? False;

        if (empty($payload['data'])) {
            throw new ApiException('Data is required.', 'validation_failed', 422);
        }

        if ($year === 0) {
            throw new ApiException('Year is required.', 'validation_failed', 422);
        }

        // Get or create category for the year
        $sql = 'SELECT cat_id FROM ' . TBL_CATEGORIES . ' 
            WHERE cat_name = ? AND cat_type = ? AND cat_org_id = ?';
        $stmt = $this->db->queryPrepared($sql, [(string) $year, 'ADC', (int) $this->gCurrentOrgId]);
        $result = $stmt->fetch();
        $categoryid = $result['cat_id'] ?? null;
        
        if (!$categoryid) {
            $category = new Category($this->db);
            $category->setValue('cat_name', (string) $year);
            $category->setValue('cat_type', 'ADC');
            $category->setValue('cat_org_id', (int) $this->gCurrentOrgId);
            $category->save();
            $categoryid = $category->getId();
        } 

        # TABELLE TBL_USER_ARBEITSDIENST
        # pad_id, pad_org_id, pad_user_id, pad_date, pad_cat_id, pad_pro_id, pad_name, pad_hours

        $pro_id = $data['pro_id'] ?? Null;
        $date = $data['date'] ?? date('Y-m-d');
        $name = $data['name'] ?? 'Arbeitsdienstbezeichung';
        $hours = $data['hours'] ?? 0;
        $pad_id = $data['id'] ?? null;


        // Get or create category for the year
        $sql = 'SELECT pad_id FROM ' . TBL_USER_ARBEITSDIENST . ' 
            WHERE pad_name = ? AND pad_org_id = ? AND pad_user_id = ? AND pad_date = ? AND pad_cat_id = ? AND pad_hours = ?';
        $stmt = $this->db->queryPrepared($sql, [$name, (int) $this->gCurrentOrgId, $userId, $date, $categoryid, $hours]);
        $pad_id_found = $stmt->fetch();        

        if (!empty($pad_id)) {
            $sql = 'UPDATE ' . TBL_USER_ARBEITSDIENST . '
                    SET pad_cat_id = ? , 
                        pad_pro_id = ? , 
                        pad_date = ? , 
                        pad_name = ? , 
                        pad_hours = ?
                    WHERE pad_id = ?';
            $stmt = $this->db->queryPrepared($sql, [$categoryid, $pro_id, $date, $name, $hours, $pad_id]);

        } elseif (!$check_dublicates || empty($pad_id_found)) {
            $sql = 'INSERT INTO ' . TBL_USER_ARBEITSDIENST . '
                    ( pad_org_id, pad_user_id, pad_cat_id, pad_pro_id, pad_date, pad_name, pad_hours)
                    VALUES (?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->db->queryPrepared($sql, [(int) $this->gCurrentOrgId, 
                                                    $userId,
                                                    $categoryid,
                                                    $pro_id, 
                                                    $date, 
                                                    $name, 
                                                    $hours]);
        } else {
            throw new ApiException('Duplicate entry for the same date.', 'validation_failed', 422);
        }
        return [
            'status' => 'success',
            'check_dublicates' => $check_dublicates,
            'pad_id_found' => $pad_id_found,

            'data' => [
            ],
        ];
    }

    /**
     * DELETE /core/users/{uuid}/arbeitsdienst/{id} – Delete a custom field by ID
     */
    public function deleteUserArbeitsdienst(string $uuid, int $id): array
    {
        $userId = $this->assertUUIDExists($uuid);

        if ($id === 0) {
            throw new ApiException('ID is required.', 'validation_failed', 422);
        }

        // Delete the record with the given ID
        $sql = 'DELETE FROM ' . TBL_USER_ARBEITSDIENST . ' WHERE pad_id = ? AND pad_user_id = ?';
        $stmt = $this->db->queryPrepared($sql, [$id, $userId]);

        return [
            'status' => 'success',
        ];
    }


    public function findUserIdbyFirstnameLastnameBirthday(string $firstName, string $lastName, string $birthday): ?int
    {
        // search for existing user with same name and read user data
        $sql = 'SELECT MAX(usr_id) AS usr_id
                  FROM '.TBL_USERS.'
            INNER JOIN '.TBL_USER_DATA.' AS last_name
                    ON last_name.usd_usr_id = usr_id
                   AND last_name.usd_usf_id = ?
                   AND last_name.usd_value  = ?
            INNER JOIN '.TBL_USER_DATA.' AS birthday
                    ON birthday.usd_usr_id = usr_id
                   AND birthday.usd_usf_id = ?
                   AND birthday.usd_value  = ?
            INNER JOIN '.TBL_USER_DATA.' AS first_name
                    ON first_name.usd_usr_id = usr_id
                   AND first_name.usd_usf_id = ?
                   AND first_name.usd_value  = ?
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

    /**
     * POST /core/users/new
     */
    public function createUser(): array
    {
        $payload = $this->payload;
        $firstName = $payload['firstName'] ?? null;
        $lastName = $payload['lastName'] ?? null;
        $birthday = $payload['birthday'] ?? null;

        if (!$firstName || !$lastName || !$birthday) {
            throw new ApiException('firstName, lastName and birthday are required.', 'validation_failed', 422);
        }

        $existingUserId = $this->findUserIdbyFirstnameLastnameBirthday($firstName, $lastName, $birthday);
        $existingUserUuid = $this->getuuid($existingUserId);


        if ($existingUserId) {
            return [
                'status' => 'success',
                'message' => 'User already exists.',
                'uuid' => $existingUserUuid,
            ];
        }

        // Create a new user
        $user = new User($this->db, $this->profileFields);
        $user->assignDefaultRoles();
        $user->setValue('FIRST_NAME', $firstName);
        $user->setValue('LAST_NAME', $lastName);
        $user->setValue('BIRTHDAY', $birthday);
        $user->save();

        return [
            'status' => 'success',
            'message' => 'User created successfully.',
            'uuid' => $usr_id = (int) $user->getValue('usr_uuid')  
        ];
    }

    /**
     * POST /core/users/{uuid}/memberships/{memId} – Update membership
     */
    public function updateMembership(string $uuid, int $memId): array
    {
        $payload = $this->payload;
        $userId = $this->assertUUIDExists($uuid);
        $this->assertMembershipBelongsToUser($memId, $userId);

        $beginDate = $payload['beginDate'] ?? null;
        $endDate = $payload['endDate'] ?? null;

        $updates = [];
        $params = [];

        if ($beginDate !== null) {
            $updates[] = 'mem_begin = ?';
            $params[] = (string) $beginDate;
        }

        if ($endDate !== null) {
            $updates[] = 'mem_end = ?';
            $params[] = (string) $endDate;
        }

        if (empty($updates)) {
            throw new ApiException('No fields to update.', 'validation_failed', 422);
        }

        $params[] = (int) $memId;
        $sql = 'UPDATE ' . TBL_MEMBERS . ' SET ' . implode(', ', $updates) . ' WHERE mem_id = ?';
        $this->db->queryPrepared($sql, $params);

        return [
            'status' => 'success',
            'action' => 'updated',
            'data' => [
                'id' => $memId,
                'beginDate' => $beginDate,
                'endDate' => $endDate,
            ],
        ];
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

        global $plg_wpusersync_assign_default_roles;

        if ($isNew && ($plg_wpusersync_assign_default_roles ?? true)) {
            $user->assignDefaultRoles();
        }

        $roleIds = array();
        $roles = is_array($payload['roles'] ?? null) ? $payload['roles'] : array();


        foreach ($roles as $role => $roledata) {

            $roleId = $this->assignRoleToUserByName($usr_id, $role, $roledata['start_date'] ?? '', $roledata['end_date'] ?? '');
            if (isset($roleId)) {
                $roleIds[] = array($usr_id, $role, $roledata['start_date'] ?? '', $roledata['end_date'] ?? '');
            }
        }

        return array(
            'status' => $isNew ? 'created' : 'updated',
            'user_id' => (int) $user->getValue('usr_id'),
            'email' => $profileData['EMAIL'] ?? '',
            'roles_applied' => $roleIds,
        );
    }

    public function assignRoleToUserByName(int $userId, string $roleName, string $startDate = '', string $endDate = ''): int|null
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

    private function normalizeDateToYmd(string $dateValue): string
    {
        $dateValue = trim($dateValue);
        if ($dateValue === '') {
            return '';
        }

        try {
            $date = new \DateTime($dateValue);
            return $date->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }


    private function getuuid(int $userId): string
    {
        $sql = 'SELECT usr_uuid FROM ' . TBL_USERS . ' WHERE usr_id = ?';
        $stmt = $this->db->queryPrepared($sql, [$userId]);
        $res = $stmt->fetch();
        return $res['usr_uuid'] ?? '';
    }


    private function assertUUIDExists(string $usr_uuid): int
    {
        $sql = 'SELECT usr_id FROM ' . TBL_USERS . ' WHERE usr_uuid = ?';
        $stmt = $this->db->queryPrepared($sql, [$usr_uuid]);
        $res = $stmt->fetch();
        if (!$res['usr_id']) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }
        return (int) $res['usr_id'];
    }

    private function assertMembershipBelongsToUser(int $memId, int $userId): void
    {
        $sql = 'SELECT 1 FROM ' . TBL_MEMBERS . ' WHERE mem_id = ? AND mem_usr_id = ?';
        $result = $this->db->queryPrepared($sql, [(int) $memId, (int) $userId]);
        if (!$result->fetch()) {
            throw new ApiException('Membership not found.', 'membership_not_found', 404);
        }
    }

    private function assertRoleExists(int $roleId): void
    {
        $sql = 'SELECT 1 FROM ' . TBL_ROLES . ' WHERE rol_id = ?';
        $result = $this->db->queryPrepared($sql, [(int) $roleId]);
        if (!$result->fetch()) {
            throw new ApiException('Role not found.', 'role_not_found', 404);
        }
    }

    private function assertOrgExists(int $orgId): void
    {
        $sql = 'SELECT 1 FROM ' . TBL_ORGANIZATIONS . ' WHERE org_id = ?';
        $result = $this->db->queryPrepared($sql, [(int) $orgId]);
        if (!$result->fetch()) {
            throw new ApiException('Organization not found.', 'organization_not_found', 404);
        }
    }
}
