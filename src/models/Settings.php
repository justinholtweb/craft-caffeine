<?php

namespace justinholtweb\caffeine\models;

use craft\base\Model;
use Psr\Log\LogLevel;

/**
 * Plugin-wide settings.
 *
 * Nothing here is marked `required`. A required plugin setting breaks fresh installs outright:
 * `savePluginSettings()` validates the whole model, so one unfilled value blocks saving every
 * *other* setting, and the install is stuck. Validate for correctness when a value is present
 * instead.
 */
class Settings extends Model
{
    /**
     * Handle of the Craft filesystem artifacts are published to. Empty means the local web
     * root, which is right for most sites; naming a filesystem puts artifacts on S3 or a CDN
     * so PHP never serves them.
     */
    public string $filesystemHandle = '';

    /** Path within the filesystem (or web root) that artifacts are written under. */
    public string $publishPath = 'caffeine';

    /**
     * How many superseded artifact versions to keep. More than one, always: a visitor who
     * loaded the page a moment before a rebuild is still fetching the old version, and pruning
     * it out from under them turns a rebuild into a 404.
     */
    public int $keepVersions = 3;

    /** Master switch for event-driven rebuilds. Off means indexes only update when told to. */
    public bool $autoUpdate = true;

    /**
     * Queue jobs, rather than building during the request that saved the element. Turning this
     * off is only sensible in tests — a synchronous build blocks the editor's save.
     */
    public bool $useQueue = true;

    /** Write `.gz` and `.br` sidecars so a web server can serve them precompressed. */
    public bool $precompress = true;

    /**
     * Serve artifacts through Caffeine's own controller instead of as static files. Slower,
     * but works when the publish filesystem is not web-accessible.
     */
    public bool $serveThroughPhp = false;

    public string $logLevel = LogLevel::WARNING;

    protected function defineRules(): array
    {
        return [
            [['publishPath'], 'match', 'pattern' => '/^[a-zA-Z0-9\-_\/]*$/', 'message' => 'The publish path can contain only letters, numbers, hyphens, underscores and slashes.'],
            [['keepVersions'], 'integer', 'min' => 1, 'max' => 100],
            [['logLevel'], 'in', 'range' => [
                LogLevel::DEBUG,
                LogLevel::INFO,
                LogLevel::NOTICE,
                LogLevel::WARNING,
                LogLevel::ERROR,
                LogLevel::CRITICAL,
                LogLevel::ALERT,
                LogLevel::EMERGENCY,
            ]],
        ];
    }
}
