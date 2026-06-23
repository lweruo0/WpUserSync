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
    public function setUserField(string $uuid): array
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
     * POST /core/users/{uuid}/memberships – Update memberships
     */
    public function updateMemberships(string $uuid): array
    {
        $payload = $this->payload;
        $userId = $this->assertUUIDExists($uuid);
        $roles = is_array($payload['roles'] ?? null) ? $payload['roles'] : array();
        
        if (count($roles) === 0) {
            throw new ApiException('No roles provided.', 'validation_failed', 422);
        }

        $roles_applied = array();
        foreach ($roles as $role => $roledata) {
            $roleId = $this->assignRoleToUserByName($userId, $role, $roledata['start_date'] ?? '', $roledata['end_date'] ?? '');
            if (isset($roleId)) {
                $roles_applied[] = array( $role, $roledata['start_date'] ?? '', $roledata['end_date'] ?? '');
            }
        }

        if (count($roles_applied) === 0) {
            throw new ApiException('No roles applied.', 'validation_failed', 422);
        }

        return array(
            'status' => 'success',
            'count' => count($roles_applied),
            'roles_applied' => $roles_applied,
        );
    }

    /**
     * POST /core/users/{uuid}/mitgliedschaftbfv
     */
    public function MitgliedschaftBFV(string $uuid): array
    {
        $payload = $this->payload;
        $userId = $this->assertUUIDExists($uuid);
        $payroles = is_array($payload['payroles'] ?? null) ? $payload['payroles'] : array();
        $role  = isset($payload['role']) ? (string) $payload['role'] : '';
        $year = isset($payload['year']) ? (int) $payload['year'] : (int) date('Y') + 1;

        $allowedyears = array((int) date('Y') + 1, (int) date('Y'));
        if ($role === '') {
            throw new ApiException('No role provided.', 'validation_failed', 422);
        }
        if (!in_array($year, $allowedyears, true)) {
            throw new ApiException('Invalid year provided.', 'validation_failed', 422);
        }

        $membershipResult = $this->updateMitgliedschaftBFV($userId, $role, $year);
        $payroleResult   = $this->updateBeitragsrolleBFV($userId, $role, $payroles, $year);

        return [
            'status'     => 'success',
            'membership' => $membershipResult,
            'payroles'   => $payroleResult,
        ];
    }


    /**
     * updateMembershipBFV
     * 
     * der BFV hat folgende exlusiven Rollen: 
     * 
     *  ++ 'Aktiv'
     *  ++ 'Passiv'
     *  ++ 'Jugend'
     *  ++ 'Förder'
     *  ++ 'Ehren'
     * 
     * startdatum ist ist immer der yyyy-01-01 des Jahres, enddatum immer yyyy-12-31 des Jahres oder (unbefristet 9999-12-31)
     * das bedeutet, wenn eine rolle für ein Jahr gesetzt werden soll,
     *  - Ein Rollenwechsel für das aktuelle Jahr ist nur für den übergang 'Passiv' nach 'Aktiv' möglich in diesem Fall wird eine erfolt eine Rückabwicklung als wäre der Rollenwechsel zum 01.01. des aktuellen Jahres erfolgt.
     *  - wird zuerst geprüft ob eine Rolle mit diesem Namen existiert die ein enddatum >= yyyy-12-31 hat. --> es muss nichts geändert werden.
     *  - es wird die Rolle des Vorjahres gesucht und das Enddatum auf das Vorjahr gesetzt
     *  - es wird eine neue Rolle mit den entsprechenden Startdatum und Enddatum unbefristet angelegt 
     *  - falls weitere Rollen mit dem selben Startdatum existieren, werden diese entfernt.
     * 
     * -> die Rolle 'Jugend' kann nicht gesetzt werden, wenn das Alter des Mitglieds am 01.01. >= 18 Jahre ist.
     * -> bei gesetzer 'Ehren' kann die Rolle nicht geändert werden.
     * -> bei gesetzer 'Förder' kann die Rolle nicht geändert werden.
     * -> bei gesetzer 'Jugend' muss in 'Aktiv' oder 'Passiv' gewechselt werden, wenn das Alter des Mitglieds am 01.01. >= 18 Jahre ist.
     * -> bei gesetzer Rolle 'Aktiv' kann in 'Passiv' gewechselt werden. 
     * -> bei gesetzer Rolle 'Passiv' kann in 'Aktiv' wenn das Alter des Mitglieds am 01.01. >= 18 Jahre ist oder 'Jugend' gewechselt werden, wenn das Alter des Mitglieds am 01.01. < 18 Jahre ist.
     * 
     * 
     *  param uuid: User UUID
     *  param role: 'Aktiv', 'Passiv', 'Jugend', 'Förder' oder 'Ehren'
     *  param year: Jahr für das die Rolle gesetzt werden soll
     * 
     */
    private function updateMitgliedschaftBFV(int $userId, string $role, int $year): array
    {
        $allowedRoles = array('Aktiv', 'Passiv', 'Jugend', 'Förder', 'Ehren');
        $role = trim($role);

        if (!in_array($role, $allowedRoles, true)) {
            throw new ApiException('Invalid BFV role. Allowed roles are Aktiv, Passiv, Jugend, Förder, Ehren.', 'validation_failed', 422);
        }

        if ($year < 1900 || $year > 9999) {
            throw new ApiException('Invalid year.', 'validation_failed', 422);
        }

        $akt_year = (int) date('Y');
        $user = new User($this->db, $this->profileFields, $userId);
        $birthdayStr = (string) $user->getValue('BIRTHDAY');

        $birthday = \DateTime::createFromFormat('Y-m-d', $birthdayStr);
        if (!$birthday) {
            try {
                $birthday = new \DateTime($birthdayStr);
            } catch (\Throwable $e) {
                throw new ApiException('User birthday is invalid or not set.', 'validation_failed', 422);
            }
        }

        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);
        $prevYearEnd = sprintf('%04d-12-31', $year - 1);

        $yearStartDate = new \DateTime($yearStart);
        $ageAtStartOfYear = (int) $birthday->diff($yearStartDate)->y;

        if ($role === 'Jugend' && $ageAtStartOfYear >= 18) {
            throw new ApiException('Role Jugend cannot be set when age at 01.01. is 18 or older.', 'validation_failed', 422);
        }

        $roleIdsByName = $this->getBfvRoleIdsByName($allowedRoles);
        $missingRoles = array_diff($allowedRoles, array_keys($roleIdsByName));
        if (count($missingRoles) > 0) {
            throw new ApiException('Missing BFV roles in this organization: ' . implode(', ', $missingRoles), 'validation_failed', 422);
        }

        $bfvRoleIds = array_values(array_map('intval', $roleIdsByName));
        $targetRoleId = (int) $roleIdsByName[$role];

        $existingTargetMembership = $this->getActiveBfvMembershipAtDate($userId, $bfvRoleIds, $yearEnd, $targetRoleId);
        if ($existingTargetMembership !== null) {
            return array(
                'status' => 'success',
                'user_id' => $userId,
                'role' => $role,
                'year' => $year,
                'no_change' => true,
                'message' => 'Role already set for this year.'
            );
        }

        $activeRoleAtYearStart = $this->getActiveBfvMembershipAtDate($userId, $bfvRoleIds, $yearStart);
        $activeRoleName = $activeRoleAtYearStart['rol_name'] ?? '';

        if ($year === $akt_year) {
            $activeRoleToday = $this->getActiveBfvMembershipAtDate($userId, $bfvRoleIds, date('Y-m-d'));
            $activeRoleTodayName = $activeRoleToday['rol_name'] ?? '';

            if ($activeRoleTodayName !== '' && $activeRoleTodayName !== $role) {
                if (!($activeRoleTodayName === 'Passiv' && $role === 'Aktiv')) {
                    throw new ApiException('For current year, only transition Passiv -> Aktiv is allowed.', 'validation_failed', 422);
                }
            }
        }

        if ($activeRoleName !== '' && $activeRoleName !== $role) {
            if ($activeRoleName === 'Ehren' || $activeRoleName === 'Förder') {
                throw new ApiException('Role ' . $activeRoleName . ' is immutable and cannot be changed.', 'validation_failed', 422);
            }

            if ($activeRoleName === 'Jugend' && $ageAtStartOfYear >= 18 && !in_array($role, array('Aktiv', 'Passiv'), true)) {
                throw new ApiException('At age 18 or older, role Jugend must transition to Aktiv or Passiv.', 'validation_failed', 422);
            }

            if ($activeRoleName === 'Aktiv' && $role !== 'Passiv') {
                throw new ApiException('From role Aktiv, only transition to Passiv is allowed.', 'validation_failed', 422);
            }

            if ($activeRoleName === 'Passiv') {
                if ($ageAtStartOfYear >= 18 && $role !== 'Aktiv') {
                    throw new ApiException('From role Passiv at age 18 or older, only transition to Aktiv is allowed.', 'validation_failed', 422);
                }

                if ($ageAtStartOfYear < 18 && $role !== 'Jugend') {
                    throw new ApiException('From role Passiv below age 18, only transition to Jugend is allowed.', 'validation_failed', 422);
                }
            }
        }

        $inPlaceholders = implode(', ', array_fill(0, count($bfvRoleIds), '?'));

        // End any active BFV role at start of the target year so new role starts cleanly on 01.01.
        $sql = 'UPDATE ' . TBL_MEMBERS . '
                SET mem_end = ?
                WHERE mem_usr_id = ?
                  AND mem_rol_id IN (' . $inPlaceholders . ')
                  AND mem_rol_id <> ?
                  AND (mem_begin IS NULL OR mem_begin <= ?)
                  AND (mem_end IS NULL OR mem_end >= ?)';
        $queryParams = array_merge(array($prevYearEnd, $userId), $bfvRoleIds, array($targetRoleId, $yearStart, $yearStart));
        $this->db->queryPrepared($sql, $queryParams);

        $assignedRoleId = $this->assignRoleToUserByName($userId, $role, $yearStart, '9999-12-31');
        if ($assignedRoleId === null) {
            throw new ApiException('Role could not be assigned.', 'validation_failed', 422);
        }

        $sql = 'SELECT mem_id
                FROM ' . TBL_MEMBERS . '
                WHERE mem_usr_id = ?
                  AND mem_rol_id = ?
                  AND mem_begin = ?
                ORDER BY mem_id DESC';
        $stmt = $this->db->queryPrepared($sql, array($userId, $targetRoleId, $yearStart));
        $newMembership = $stmt->fetch();
        $newMembershipId = (int) ($newMembership['mem_id'] ?? 0);

        // Keep only one BFV role starting at 01.01.<year>.
        $sql = 'DELETE FROM ' . TBL_MEMBERS . '
                WHERE mem_usr_id = ?
                  AND mem_rol_id IN (' . $inPlaceholders . ')
                  AND mem_begin = ?
                  AND mem_id <> ?';
        $queryParams = array_merge(array($userId), $bfvRoleIds, array($yearStart, $newMembershipId));
        $this->db->queryPrepared($sql, $queryParams);

        return array(
            'status' => 'success',
            'user_id' => $userId,
            'role' => $role,
            'year' => $year,
            'age_at_year_start' => $ageAtStartOfYear,
            'assigned_role_id' => (int) $assignedRoleId,
            'membership_id' => $newMembershipId,
            'active_role_at_year_start' => $activeRoleName,
            'start_date' => $yearStart,
            'end_date' => '9999-12-31',
            'no_change' => false,
        );
    }

    /**
     * Es soll eine Funktion geben, die die Beitragsrolle für ein Jahr setzt. Es gibt folgende payroles:
     * 
     *  ## bei Mitgliedschaft 'Aktive' sind folgende payroles möglich:
     *  'Bearbeitungsgebühr',
     *  'passiv->aktiv Differenz',
     *  'Erstbesatz',
     *  'Aktivbeitrag',
     *  'Bootsbeitrag',
     * 
     * ## bei Mitgliedschaft 'Jugend' sind folgende payroles möglich:
     *  '2.Angel',
     *  'Jugendbeitrag',
     *  'Bearbeitungsgebühr',
     * 
     * ## bei Mitgliedschaft 'Förder' sind folgende payroles möglich:
     *  'Förderbeitrag 12€',
     *  'Förderbeitrag 20€',
     *  'Förderbeitrag 25€',
     *  'Förderbeitrag 50€',
     *  'Förderbeitrag 100€',
     * 
     * ## bei Mitgliedschaft 'Passiv' sind folgende payroles möglich:
     *  'Passivbeitrag',
     *  'Erstbesatz', --> hier muss der startdatum auf das Jahr 9999-1-1 gesetzt werden, damit die payrole nicht für das aktuelle Jahr berechnet wird.
     * 
     * 
     * 
     * Wenn das aktuelle Jahr übergeben wird ist nur der Wechsel von Passiv nach aktiv möglich.
     * Die Beitragsrolle Passiv muss dann zum 31.12. des aktuellen Jahres beendet werden und die Beitragsrolle
     * 'passiv->aktiv Differenz' gesetzt werden mit startdatum 01.01. des aktuellen Jahres und enddatum 31.12. des aktuellen Jahres, damit die Beitragsdifferenz korrekt berechnet werden kann.
     * 'Aktivbeitrag' muss zum 01.01. des aktuellen Jahres gesetzt werden. enddatum unbefristet 9999-12-31
     * Wenn das aktuelle Jahr übergeben wird, und die Aktuelle Rolle nicht 'Aktiv' ist, muss eine Fehlermeldung ausgegeben werden, 
     * dass die payrole für das aktuelle Jahr nur gesetzt werden kann, wenn die Rolle 'Aktiv' ist.
     * 
     * Wenn die Rolle Mitgliedschaft "Aktiv" ist, muss geprüft werden ob am Mitglied ein payrole "Erstbesatz" gestundet wurde (schon gesetzt ist mit einem Startdatum von 9999-1-1. 
     * Wenn ja, muss das startdatum der Rolle Erstbesatz auf den year-01-01 und Enddatum year-12-31 gesetzt werden (Stundung entfernen).
     * Wenn die Rolle Mitgliedschaft "Passiv" ist, muss geprüft werden ob am Mitglied ein payrole "Erstbesatz" gesetzt ist mit startdatum der Rolle Erstbesatz auf year-01-01 und Enddatum year-12-31 gesetzt werden 
     * 
     * 
     **/

    private function updateBeitragsrolleBFV(int $userId, string $role, array $beitragsrollen, int $year): array
    {
        $akt_year  = (int) date('Y');
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd   = sprintf('%04d-12-31', $year);

        // Allowed payroles per membership role
        $allowedPayrolesByRole = [
            'Aktiv'  => ['Aktivbeitrag', 'Bearbeitungsgebühr', 'passiv->aktiv Differenz', 'Erstbesatz', 'Bootsbeitrag'],
            'Jugend' => ['Jugendbeitrag', '2.Angel', 'Bearbeitungsgebühr'],
            'Förder' => ['Förderbeitrag 12€', 'Förderbeitrag 20€', 'Förderbeitrag 25€', 'Förderbeitrag 50€', 'Förderbeitrag 100€'],
            'Passiv' => ['Passivbeitrag', 'Erstbesatz'],
            'Ehren'  => ['Ehrenbeitrag'],
        ];

        $allowedPayroles = $allowedPayrolesByRole[$role] ?? [];

        foreach ($beitragsrollen as $payrole) {
            if (!in_array($payrole, $allowedPayroles, true)) {
                throw new ApiException(
                    'Payrole "' . $payrole . '" is not allowed for membership role "' . $role . '".',
                    'validation_failed', 422
                );
            }
        }

        // Current year: only Passiv -> Aktiv transition is allowed for payrole updates
        if ($year === $akt_year) {
            if ($role !== 'Aktiv') {
                throw new ApiException(
                    'For the current year, payroles can only be set when the membership role is "Aktiv".',
                    'validation_failed', 422
                );
            }

            // End Passivbeitrag at 31.12. of current year
            $passivIds = $this->getBfvRoleIdsByName(['Passivbeitrag']);
            if (!empty($passivIds['Passivbeitrag'])) {
                $sql = 'UPDATE ' . TBL_MEMBERS . '
                        SET mem_end = ?
                        WHERE mem_usr_id = ?
                          AND mem_rol_id = ?
                          AND (mem_end IS NULL OR mem_end > ?)';
                $this->db->queryPrepared($sql, [$yearEnd, $userId, (int) $passivIds['Passivbeitrag'], $yearEnd]);
            }

            // Assign passiv->aktiv Differenz for the transition (year-scoped)
            $this->assignRoleToUserByName($userId, 'passiv->aktiv Differenz', $yearStart, $yearEnd);
            // Assign Aktivbeitrag open-ended from 01.01. of current year
            $this->assignRoleToUserByName($userId, 'Aktivbeitrag', $yearStart, '9999-12-31');
        }

        // For Aktiv: undefer 'Erstbesatz' if it was previously deferred (start = 9999-01-01)
        if ($role === 'Aktiv') {
            $erstbesatzIds = $this->getBfvRoleIdsByName(['Erstbesatz']);
            if (!empty($erstbesatzIds['Erstbesatz'])) {
                $sql = 'SELECT mem_id FROM ' . TBL_MEMBERS . '
                        WHERE mem_usr_id = ?
                          AND mem_rol_id = ?
                          AND mem_begin = ?
                        LIMIT 1';
                $stmt = $this->db->queryPrepared($sql, [$userId, (int) $erstbesatzIds['Erstbesatz'], '9999-01-01']);
                $deferred = $stmt->fetch();
                if ($deferred) {
                    $sql = 'UPDATE ' . TBL_MEMBERS . '
                            SET mem_begin = ?, mem_end = ?
                            WHERE mem_id = ?';
                    $this->db->queryPrepared($sql, [$yearStart, $yearEnd, (int) $deferred['mem_id']]);
                }
            }
        }

        // For Passiv: defer 'Erstbesatz' back if it is currently active for this year
        if ($role === 'Passiv') {
            $erstbesatzIds = $this->getBfvRoleIdsByName(['Erstbesatz']);
            if (!empty($erstbesatzIds['Erstbesatz'])) {
                $sql = 'SELECT mem_id FROM ' . TBL_MEMBERS . '
                        WHERE mem_usr_id = ?
                          AND mem_rol_id = ?
                          AND mem_begin = ?
                        LIMIT 1';
                $stmt = $this->db->queryPrepared($sql, [$userId, (int) $erstbesatzIds['Erstbesatz'], $yearStart]);
                $active = $stmt->fetch();
                if ($active) {
                    $sql = 'UPDATE ' . TBL_MEMBERS . '
                            SET mem_begin = ?, mem_end = ?
                            WHERE mem_id = ?';
                    $this->db->queryPrepared($sql, ['9999-01-01', '9999-12-31', (int) $active['mem_id']]);
                }
            }
        }

        // Assign all requested payroles with appropriate dates
        $appliedPayroles = [];
        foreach ($beitragsrollen as $payrole) {
            $payroleStart = $yearStart;
            $payroleEnd   = '9999-12-31';

            if ($role === 'Passiv' && $payrole === 'Erstbesatz') {
                // Defer: not billed in the target year
                $payroleStart = '9999-01-01';
                $payroleEnd   = '9999-12-31';
            } elseif ($payrole === 'Bearbeitungsgebühr') {
                // One-time fee, scoped to the year
                $payroleEnd = $yearEnd;
            }

            $roleId = $this->assignRoleToUserByName($userId, $payrole, $payroleStart, $payroleEnd);
            if ($roleId !== null) {
                $appliedPayroles[] = [
                    'payrole' => $payrole,
                    'start'   => $payroleStart,
                    'end'     => $payroleEnd,
                ];
            }
        }

        return [
            'status'           => 'success',
            'user_id'          => $userId,
            'role'             => $role,
            'year'             => $year,
            'applied_payroles' => $appliedPayroles,
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

    private function getBfvRoleIdsByName(array $roleNames): array
    {
        if (count($roleNames) === 0) {
            return array();
        }

        $placeholders = implode(', ', array_fill(0, count($roleNames), '?'));
        $sql = 'SELECT rol_id, rol_name
                FROM ' . TBL_ROLES . '
                INNER JOIN ' . TBL_CATEGORIES . ' ON rol_cat_id = cat_id
                WHERE cat_org_id = ?
                  AND rol_name IN (' . $placeholders . ')';
        $queryParams = array_merge(array((int) $this->gCurrentOrgId), $roleNames);
        $stmt = $this->db->queryPrepared($sql, $queryParams);

        $roleIdsByName = array();
        while ($row = $stmt->fetch()) {
            $roleIdsByName[(string) $row['rol_name']] = (int) $row['rol_id'];
        }

        return $roleIdsByName;
    }

    private function getActiveBfvMembershipAtDate(int $userId, array $roleIds, string $date, ?int $specificRoleId = null): ?array
    {
        if (count($roleIds) === 0) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));
        $sql = 'SELECT mem_id, mem_rol_id, mem_begin, mem_end, rol_name
                FROM ' . TBL_MEMBERS . '
                INNER JOIN ' . TBL_ROLES . ' ON mem_rol_id = rol_id
                WHERE mem_usr_id = ?
                  AND mem_rol_id IN (' . $placeholders . ')
                  AND (mem_begin IS NULL OR mem_begin <= ?)
                  AND (mem_end IS NULL OR mem_end >= ?)';

        $queryParams = array_merge(array($userId), $roleIds, array($date, $date));

        if ($specificRoleId !== null) {
            $sql .= ' AND mem_rol_id = ?';
            $queryParams[] = $specificRoleId;
        }

        $sql .= ' ORDER BY mem_begin DESC, mem_id DESC';
        $stmt = $this->db->queryPrepared($sql, $queryParams);
        $row = $stmt->fetch();

        return $row ? $row : null;
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
