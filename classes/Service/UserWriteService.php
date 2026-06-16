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
     * POST /core/users/{userId}/fields – set multiple custom fields
     */
    public function setUserField(int $userId): array
    {
        $payload = $this->payload;
        $this->assertUserExists($userId);

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
     * POST /core/users/{userId}/fields/{name} – Set a custom field by name
     */
    public function setUserFieldByName(int $userId, string $name): array
    {
        $payload = $this->payload;
        $this->assertUserExists($userId);

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
     * POST /core/users/{userId}/arbeitsdienst/{year} – Set a custom field by name
     */
    public function setUserArbeitsdienst(int $userId, int $year): array
    {
        $payload = $this->payload;
        $this->assertUserExists($userId);

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
     * DELETE /core/users/{userId}/arbeitsdienst/{id} – Delete a custom field by ID
     */
    public function deleteUserArbeitsdienst(int $userId, int $id): array
    {
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
        if ($existingUserId) {
            return [
                'status' => 'success',
                'firstname' => $firstName,
                'lastname' => $lastName,
                'birthday' => $birthday,
                'message' => 'User already exists.',
                'userId' => $existingUserId,
            ];
        }

        // Create a new user
        $user = new User($this->db, $this->profileFields);
        $user->setValue('FIRST_NAME', $firstName);
        $user->setValue('LAST_NAME', $lastName);
        $user->setValue('BIRTHDAY', $birthday);
        $user->save();



        return [
            'status' => 'success',
            'message' => 'User created successfully.',
            'userId' => $usr_id = (int) $user->getValue('usr_id')  
        ];
    }

    /**
     * POST /core/users/{userId}/memberships/{memId} – Update membership
     */
    public function updateMembership(int $userId, int $memId): array
    {
        $payload = $this->payload;
        $this->assertUserExists($userId);
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


    private function assertUserExists(int $userId): void
    {
        $sql = 'SELECT 1 FROM ' . TBL_USERS . ' WHERE usr_id = ?';
        $result = $this->db->queryPrepared($sql, [(int) $userId]);
        if (!$result->fetch()) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }
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
