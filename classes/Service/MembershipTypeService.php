<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Users\Entity\User;
use AdmHistory\AdmidioContext;
use AdmHistory\HistoryLoader;
use AdmHistory\MembershipApi;
use AdmHistory\MembershipChanger;
use AdmHistory\MembershipRepository;
use AdmHistory\MembershipType;
use AdmHistory\MembershipTypeConfig;
use AdmHistory\YearHistory;
use Throwable;

/**
 * Wechsel der Mitgliedsart (mit Zusatzbeiträgen) über das Plugin „Mitglieder-Historie“ (adm-history).
 *
 * Die fachlichen Regeln (erlaubte Wechsel, Pflicht- und optionale Beitragsrollen, Stichtag 1. Januar,
 * nur aktuelles Jahr und Folgejahr) stehen ausschließlich in adm-history/mitgliedsarten.php und werden
 * von AdmHistory\MembershipApi geprüft. Diese Klasse liefert die Optionen für WordPress und reicht
 * Änderungen an MembershipApi weiter.
 *
 * Schreibzugriffe laufen im Namen des technischen API-Benutzers ($plg_wpusersync_api_user_uuid), den
 * api.php vorher über ApiUser::activate() als $gCurrentUser gesetzt hat: MembershipChanger prüft seine
 * Rechte an den betroffenen Rollen und Admidio trägt ihn im Änderungsprotokoll ein.
 *
 * Endpunkte:
 *   GET  /core/mitgliedsarten               – konfigurierte Mitgliedsarten, Beitragsrollen und Wechsel
 *   GET  /core/users/{uuid}/mitgliedsart    – Stand und mögliche Optionen für aktuelles Jahr und Folgejahr
 *   POST /core/users/{uuid}/mitgliedsart    – Mitgliedsart und Zusatzbeiträge ab 1. Januar setzen
 */
final class MembershipTypeService
{
    private MembershipTypeConfig $config;
    private YearHistory $history;
    private string $configFile;

    /**
     * @param array<string, mixed> $payload       JSON-Body der Anfrage
     * @param string               $admHistoryDir Ordner des Plugins adm-history
     */
    public function __construct(
        private readonly array $payload,
        string $admHistoryDir,
    ) {
        $autoload = rtrim($admHistoryDir, '/\\') . '/autoload.php';
        if (!is_file($autoload)) {
            throw new ApiException('Plugin adm-history is not installed.', 'adm_history_missing', 501, array('path' => $admHistoryDir));
        }
        require_once $autoload;

        $this->configFile = rtrim($admHistoryDir, '/\\') . '/mitgliedsarten.php';
        try {
            $this->config = MembershipTypeConfig::fromFile($this->configFile);
        } catch (Throwable $e) {
            throw new ApiException('adm-history configuration invalid: ' . $e->getMessage(), 'adm_history_config_invalid', 500);
        }
        $this->history = new YearHistory($this->config);
    }

    /**
     * GET /core/mitgliedsarten – alle Mitgliedsarten mit Rollen, Beitragsrollen und erlaubten Zielen.
     */
    public function listMembershipTypes(): array
    {
        $types = array();
        foreach ($this->config->getTypes() as $key => $type) {
            $types[] = array(
                'art'            => $key,
                'name'           => $type->name,
                'roles'          => $type->roleNames,
                'mandatory_fees' => $type->mandatoryFeeRoles,
                'optional_fees'  => $type->optionalFeeRoles,
                'targets'        => $this->config->getAllowedTargets($key),
            );
        }

        return array(
            'status'         => 'success',
            'editable_years' => $this->editableYears(),
            'entry_targets'  => $this->config->getAllowedTargets(''),
            'types'          => $types,
        );
    }

    /**
     * GET /core/users/{uuid}/mitgliedsart – Stand am 31.12. und mögliche Optionen je bearbeitbarem Jahr.
     */
    public function getMembershipOptions(string $uuid): array
    {
        $loader = $this->createLoader($this->createContext($this->currentUser()));
        $user = $this->loadUserOrFail($loader, $uuid);

        return array(
            'status'    => 'success',
            'user_uuid' => $uuid,
            'years'     => $this->yearOptions($user),
        );
    }

    /**
     * POST /core/users/{uuid}/mitgliedsart
     *
     * Body: {"year": 2027, "art": "A", "fee": ["Bootsbeitrag"]}
     *   year – Jahr, ab dessen 1. Januar die Änderung gilt (aktuelles Jahr oder Folgejahr)
     *   art  – Kürzel oder Name der Mitgliedsart, "" = kein Mitglied (Kündigung)
     *   fee  – optional: genau diese Zusatzbeiträge gelten danach im Jahr ([] entfernt alle).
     *          Fehlt „fee“, bleiben die bisherigen Zusatzbeiträge, soweit sie zur neuen Mitgliedsart gehören.
     */
    public function changeMembershipType(string $uuid): array
    {
        $year = $this->payload['year'] ?? null;
        if (!is_int($year) && !(is_string($year) && ctype_digit($year))) {
            throw new ApiException('Field "year" is required and must be an integer.', 'validation_failed', 422);
        }
        $year = (int) $year;
        if (!in_array($year, $this->editableYears(), true)) {
            throw new ApiException('Invalid year provided.', 'validation_failed', 422, array('allowed' => $this->editableYears()));
        }

        if (!array_key_exists('art', $this->payload) || !is_scalar($this->payload['art'] ?? '')) {
            throw new ApiException('Field "art" is required ("" = kein Mitglied).', 'validation_failed', 422);
        }
        $art = trim((string) ($this->payload['art'] ?? ''));

        $context = $this->createContext(ApiUser::require());
        $loader = $this->createLoader($context);
        $user = $this->loadUserOrFail($loader, $uuid);

        if (array_key_exists('fee', $this->payload)) {
            if (!is_array($this->payload['fee'])) {
                throw new ApiException('Field "fee" must be an array of fee role names.', 'validation_failed', 422);
            }
            $fee = array_values(array_map(static fn($name): string => trim((string) $name), $this->payload['fee']));
        } else {
            $fee = $this->keptFees($user, $year, $art);
        }

        $api = new MembershipApi($this->config, $loader, $this->history, new MembershipChanger($context, $this->config, $loader, $this->history));
        try {
            $result = $api->change($uuid, $year, $art, $fee);
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ApiException($e->getMessage(), 'membership_change_failed', 422);
        }

        $user = $this->loadUserOrFail($loader, $uuid);
        $type = $this->config->getType($result['type']);

        return array(
            'status'     => 'success',
            'user_uuid'  => $uuid,
            'year'       => $year,
            'art'        => $result['type'],
            'name'       => $type?->name ?? 'kein Mitglied',
            'fees'       => $result['fees'],
            'operations' => $result['operations'],
            'years'      => $this->yearOptions($user),
        );
    }

    /**
     * Stand und Optionen je bearbeitbarem Jahr: Mitgliedsart am 31.12., Zusatzbeiträge und alle
     * Ziele (einschließlich der bisherigen Mitgliedsart, um nur Zusatzbeiträge zu ändern) mit deren
     * Pflicht- und optionalen Beitragsrollen.
     *
     * @param array<string, mixed> $user Person aus HistoryLoader::loadUser()
     */
    private function yearOptions(array $user): array
    {
        $years = array();
        foreach ($this->editableYears() as $year) {
            $state = $this->history->yearStateWithFees($user['periods'], $user['commonPeriods'], $user['feePeriods'], $year);
            $current = $state['value'];

            $options = array();
            foreach (array_merge(array($current), $this->config->getAllowedTargets($current)) as $key) {
                $type = $this->config->getType($key);
                $options[] = array(
                    'art'            => $key,
                    'name'           => $type?->name ?? 'kein Mitglied',
                    'current'        => $key === $current,
                    'mandatory_fees' => $type?->mandatoryFeeRoles ?? array(),
                    'optional_fees'  => $type?->optionalFeeRoles ?? array(),
                );
            }

            $years[(string) $year] = array(
                'art'     => $current,
                'name'    => $this->config->getType($current)?->name ?? 'kein Mitglied',
                'others'  => $state['others'],
                'unknown' => $state['unknown'],
                'fees'    => $state['fees'],
                'options' => $options,
            );
        }

        return $years;
    }

    /**
     * Bisherige Zusatzbeiträge des Jahres, die auch zur neuen Mitgliedsart gehören (Standard, wenn
     * „fee“ im Request fehlt). Unbekannte Mitgliedsart oder Kündigung: keine.
     *
     * @param array<string, mixed> $user
     * @return string[]
     */
    private function keptFees(array $user, int $year, string $art): array
    {
        $type = $this->findType($art);
        if ($type === null) {
            return array();
        }
        $allowed = array_map('mb_strtolower', $type->optionalFeeRoles);
        $current = $this->history->rolesInYear($user['feePeriods'], $year);

        return array_values(array_filter(
            $current,
            static fn(string $roleName): bool => in_array(mb_strtolower($roleName), $allowed, true)
        ));
    }

    /** Mitgliedsart nach Kürzel oder Name (Groß-/Kleinschreibung egal), sonst null. */
    private function findType(string $art): ?MembershipType
    {
        $wanted = mb_strtolower(trim($art));
        if ($wanted === '') {
            return null;
        }
        foreach ($this->config->getTypes() as $key => $type) {
            if (mb_strtolower((string) $key) === $wanted || mb_strtolower($type->name) === $wanted) {
                return $type;
            }
        }

        return null;
    }

    /** @return int[] aktuelles Jahr und Folgejahr */
    private function editableYears(): array
    {
        $currentYear = (int) date('Y');

        return array($currentYear, $currentYear + 1);
    }

    /**
     * @return array<string, mixed> Person aus HistoryLoader::loadUser()
     * @throws ApiException 404, wenn die Person nicht existiert
     */
    private function loadUserOrFail(HistoryLoader $loader, string $uuid): array
    {
        $user = $loader->loadUser($uuid);
        if ($user === null) {
            throw new ApiException('User not found.', 'user_not_found', 404, array('uuid' => $uuid));
        }

        return $user;
    }

    private function createContext(User $currentUser): AdmidioContext
    {
        global $gDb, $gCurrentOrgId, $gProfileFields, $gL10n;

        return new AdmidioContext($gDb, (int) $gCurrentOrgId, $gProfileFields, $gL10n, $currentUser);
    }

    private function createLoader(AdmidioContext $context): HistoryLoader
    {
        return new HistoryLoader(new MembershipRepository($context), $this->config);
    }

    /** Angemeldeter Benutzer der Anfrage (API-Benutzer, ohne Konfiguration der Gast). */
    private function currentUser(): User
    {
        global $gCurrentUser, $gDb, $gProfileFields;

        return $gCurrentUser instanceof User ? $gCurrentUser : new User($gDb, $gProfileFields);
    }
}
