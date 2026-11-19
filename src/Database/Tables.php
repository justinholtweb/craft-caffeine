<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Database;

/**
 * Caffeine's table names.
 *
 * Kept here rather than on the install migration, as they were in Craft 5: Craft 6 `require`s
 * `database/migrations/Install.php` outside the autoloader, so nothing at runtime can reference
 * a constant on it. Unprefixed — Laravel's query builder and `Schema` add the connection's table
 * prefix themselves.
 */
final class Tables
{
    public const RECORDS = 'caffeine_records';

    public const DEPS = 'caffeine_deps';

    public const ARTIFACTS = 'caffeine_artifacts';
}
