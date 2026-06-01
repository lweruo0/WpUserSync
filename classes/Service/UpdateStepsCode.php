<?php

declare(strict_types=1);

namespace WpUserSync\classes\Service;

use Admidio\Infrastructure\Database;

final class UpdateStepsCode
{
    private static Database $db;

    public static function setDatabase(Database $database): void
    {
        self::$db = $database;
    }

    /**
     * Placeholder for future schema changes.
     */
    public static function updateStep10Initialize(): void
    {
        // Intentionally left blank. Add schema migrations here if needed later.
    }
}
