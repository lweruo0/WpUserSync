<?php

declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Infrastructure\Database;
use PDO;

final class RoleMapper
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function resolveRoleIds(array $wpRoles, string $mappingJson, string $defaultRoleName = ''): array
    {
        $mappedRoleNames = array();

        if (trim($mappingJson) !== '') {
            try {
                $mapping = json_decode($mappingJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ApiException('Role mapping JSON is invalid.', 'invalid_role_mapping', 500, array('json_error' => $e->getMessage()));
            }

            foreach ($wpRoles as $wpRole) {
                if (isset($mapping[$wpRole]) && trim((string) $mapping[$wpRole]) !== '') {
                    $mappedRoleNames[] = trim((string) $mapping[$wpRole]);
                }
            }
        }

        if ($mappedRoleNames === array() && trim($defaultRoleName) !== '') {
            $mappedRoleNames[] = trim($defaultRoleName);
        }

        $mappedRoleNames = array_values(array_unique($mappedRoleNames));
        if ($mappedRoleNames === array()) {
            return array();
        }

        $sql = 'SELECT rol_id, rol_name FROM ' . TBL_ROLES . ' WHERE rol_name IN (' . implode(',', array_fill(0, count($mappedRoleNames), '?')) . ')';
        $statement = $this->db->queryPrepared($sql, $mappedRoleNames);

        $result = array();
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['rol_name']] = (int) $row['rol_id'];
        }

        $roleIds = array();
        foreach ($mappedRoleNames as $roleName) {
            if (!isset($result[$roleName])) {
                throw new ApiException('Mapped role not found in Admidio.', 'mapped_role_not_found', 422, array('role' => $roleName));
            }
            $roleIds[] = $result[$roleName];
        }

        return array_values(array_unique($roleIds));
    }
}
