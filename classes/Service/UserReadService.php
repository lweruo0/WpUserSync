<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Utils\ArrayUtils;

final class UserReadService
{
    private Database $db;
    private array $query;
    private array $payload;

    public function __construct(Database $db, array $query = [], array $payload = [])
    {
        $this->db = $db;
        $this->query = $query;
        $this->payload = $payload;
    }

    /**
     * GET /core/users – List all users
     */
    public function listUsers(): array
    {
        $query = $this->query;

        $limit = min((int) ($query['limit'] ?? 50), 500);
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $sql = 'SELECT usr_id, usr_login, usr_email, usr_last_name, usr_first_name 
                FROM ' . TBL_USERS . ' 
                ORDER BY usr_id 
                LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $result = $this->db->queryPrepared($sql);
        $users = [];
        while ($row = $result->fetch()) {
            $users[] = [
                'id' => (int) $row['usr_id'],
                'login' => (string) $row['usr_login'],
                'email' => (string) $row['usr_email'],
                'firstName' => (string) $row['usr_first_name'],
                'lastName' => (string) $row['usr_last_name'],
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
        $sql = 'SELECT usr_id, usr_login, usr_email, usr_last_name, usr_first_name 
                FROM ' . TBL_USERS . ' 
                WHERE usr_id = ?';

        $result = $this->db->queryPrepared($sql, [(int) $userId]);
        $row = $result->fetch();

        if (!$row) {
            throw new ApiException('User not found.', 'user_not_found', 404);
        }

        return [
            'status' => 'success',
            'data' => [
                'id' => (int) $row['usr_id'],
                'login' => (string) $row['usr_login'],
                'email' => (string) $row['usr_email'],
                'firstName' => (string) $row['usr_first_name'],
                'lastName' => (string) $row['usr_last_name'],
            ],
        ];
    }

    /**
     * GET /core/users/{userId}/fields – Get all custom fields for user
     */
    public function getUserFields(int $userId): array
    {
        $this->assertUserExists($userId);

        $sql = 'SELECT * FROM ' . TBL_USER_FIELDS . ' WHERE uff_usr_id = ? ORDER BY uff_name';
        $result = $this->db->queryPrepared($sql, [(int) $userId]);

        $fields = [];
        while ($row = $result->fetch()) {
            $fields[] = [
                'name' => (string) $row['uff_name'],
                'value' => (string) $row['uff_value'],
            ];
        }

        return [
            'status' => 'success',
            'data' => $fields,
            'count' => count($fields),
        ];
    }

    /**
     * GET /core/users/{userId}/fields/{name} – Get single custom field
     */
    public function getUserField(int $userId, string $name): array
    {
        $this->assertUserExists($userId);

        $sql = 'SELECT uff_value FROM ' . TBL_USER_FIELDS . ' WHERE uff_usr_id = ? AND uff_name = ?';
        $result = $this->db->queryPrepared($sql, [(int) $userId, $name]);
        $row = $result->fetch();

        if (!$row) {
            throw new ApiException('Field not found.', 'field_not_found', 404);
        }

        return [
            'status' => 'success',
            'data' => [
                'name' => $name,
                'value' => (string) $row['uff_value'],
            ],
        ];
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
     * GET /core/users/{userId}/memberships – Get all memberships
     */
    public function getUserMemberships(int $userId): array
    {
        $this->assertUserExists($userId);

        $sql = 'SELECT mem_id, mem_rol_id, mem_org_id, mem_begin, mem_end, rol_name, org_shortname 
                FROM ' . TBL_MEMBERS . ' 
                LEFT JOIN ' . TBL_ROLES . ' ON mem_rol_id = rol_id 
                LEFT JOIN ' . TBL_ORGANIZATIONS . ' ON mem_org_id = org_id 
                WHERE mem_usr_id = ? 
                ORDER BY mem_begin DESC';

        $result = $this->db->queryPrepared($sql, [(int) $userId]);
        $memberships = [];
        while ($row = $result->fetch()) {
            $memberships[] = [
                'id' => (int) $row['mem_id'],
                'roleId' => $row['mem_rol_id'] ? (int) $row['mem_rol_id'] : null,
                'roleName' => $row['rol_name'] ? (string) $row['rol_name'] : null,
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
}
