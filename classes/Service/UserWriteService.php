<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;
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
     * POST /core/users/{userId}/fields – Set or create a custom field
     */
    public function setUserField(int $userId): array
    {
        $payload = $this->payload;
        $this->assertUserExists($userId);

        $name = (string) ($payload['name'] ?? '');
        $value = (string) ($payload['value'] ?? '');

        if ($name === '') {
            throw new ApiException('Field name is required.', 'validation_failed', 422);
        }

        $sql = 'SELECT uff_id FROM ' . TBL_USER_FIELDS . ' WHERE uff_usr_id = ? AND uff_name = ?';
        $result = $this->db->queryPrepared($sql, [(int) $userId, $name]);
        $existing = $result->fetch();

        if ($existing) {
            $sql = 'UPDATE ' . TBL_USER_FIELDS . ' SET uff_value = ? WHERE uff_usr_id = ? AND uff_name = ?';
            $this->db->queryPrepared($sql, [$value, (int) $userId, $name]);
            $status = 'updated';
        } else {
            $sql = 'INSERT INTO ' . TBL_USER_FIELDS . ' (uff_usr_id, uff_name, uff_value) VALUES (?, ?, ?)';
            $this->db->queryPrepared($sql, [(int) $userId, $name, $value]);
            $status = 'created';
        }

        return [
            'status' => 'success',
            'action' => $status,
            'data' => [
                'name' => $name,
                'value' => $value,
            ],
        ];
    }

    /**
     * POST /core/users/{userId}/fields/{name} – Set a custom field by name
     */
    public function setUserFieldByName(int $userId, string $name): array
    {
        $payload = $this->payload;
        $this->assertUserExists($userId);

        $value = (string) ($payload['value'] ?? '');

        $sql = 'SELECT uff_id FROM ' . TBL_USER_FIELDS . ' WHERE uff_usr_id = ? AND uff_name = ?';
        $result = $this->db->queryPrepared($sql, [(int) $userId, $name]);
        $existing = $result->fetch();

        if ($existing) {
            $sql = 'UPDATE ' . TBL_USER_FIELDS . ' SET uff_value = ? WHERE uff_usr_id = ? AND uff_name = ?';
            $this->db->queryPrepared($sql, [$value, (int) $userId, $name]);
            $status = 'updated';
        } else {
            $sql = 'INSERT INTO ' . TBL_USER_FIELDS . ' (uff_usr_id, uff_name, uff_value) VALUES (?, ?, ?)';
            $this->db->queryPrepared($sql, [(int) $userId, $name, $value]);
            $status = 'created';
        }

        return [
            'status' => 'success',
            'action' => $status,
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

    /**
     * POST /core/users/{userId}/memberships/role/{roleId} – Create membership for role
     */
    public function createMembershipForRole(int $userId, int $roleId): array
    {
        $this->assertUserExists($userId);
        $this->assertRoleExists($roleId);

        $payload = $this->payload;
        $beginDate = (string) ($payload['beginDate'] ?? date('Y-m-d'));
        $endDate = $payload['endDate'] ?? null;
        $orgId = (int) ($payload['orgId'] ?? 1);

        $sql = 'INSERT INTO ' . TBL_MEMBERS . ' (mem_usr_id, mem_rol_id, mem_org_id, mem_begin, mem_end) 
                VALUES (?, ?, ?, ?, ?)';

        $this->db->queryPrepared($sql, [
            (int) $userId,
            (int) $roleId,
            (int) $orgId,
            $beginDate,
            $endDate,
        ]);

        $memId = $this->db->lastInsertId();

        return [
            'status' => 'success',
            'action' => 'created',
            'data' => [
                'id' => (int) $memId,
                'roleId' => $roleId,
                'orgId' => $orgId,
                'beginDate' => $beginDate,
                'endDate' => $endDate,
            ],
        ];
    }

    /**
     * POST /core/users/{userId}/memberships/organization/{orgId} – Create/update membership for org
     */
    public function createMembershipForOrg(int $userId, int $orgId): array
    {
        $this->assertUserExists($userId);
        $this->assertOrgExists($orgId);

        $payload = $this->payload;
        $roleId = (int) ($payload['roleId'] ?? null);
        if ($roleId === 0) {
            throw new ApiException('Role ID is required.', 'validation_failed', 422);
        }

        $this->assertRoleExists($roleId);

        $beginDate = (string) ($payload['beginDate'] ?? date('Y-m-d'));
        $endDate = $payload['endDate'] ?? null;

        $sql = 'INSERT INTO ' . TBL_MEMBERS . ' (mem_usr_id, mem_rol_id, mem_org_id, mem_begin, mem_end) 
                VALUES (?, ?, ?, ?, ?)';

        $this->db->queryPrepared($sql, [
            (int) $userId,
            (int) $roleId,
            (int) $orgId,
            $beginDate,
            $endDate,
        ]);

        $memId = $this->db->lastInsertId();

        return [
            'status' => 'success',
            'action' => 'created',
            'data' => [
                'id' => (int) $memId,
                'roleId' => $roleId,
                'orgId' => $orgId,
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
