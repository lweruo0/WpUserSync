<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;
use Admidio\Users\Entity\User;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use Admidio\Infrastructure\Database;


final class UserWriteService
{
    private Database $db;
    private ProfileFields $profileFields;
    private array $query;
    private array $payload;

    public function __construct(Database $db, ProfileFields $profileFields, array $query = [], array $payload = [])
    {
        $this->db = $db;
        $this->profileFields = $profileFields;
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
            if ($this->profileFields->getProperty($fieldName)) {
                $user->setValue($fieldName, $value);
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
        if ($this->profileFields->getProperty($name)) {
            $user->setValue($name, $value);
        } else {
            throw new ApiException('Field name is invalid.', 'validation_failed', 422);
        }     

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
