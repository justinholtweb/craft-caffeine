<?php

namespace JustinHoltWeb\Caffeine\Models;

use CraftCms\Cms\Plugin\PluginSettings;
use Illuminate\Validation\Rule;
use Psr\Log\LogLevel;

/**
 * Plugin-wide settings.
 *
 * Nothing here is marked `required`. A required plugin setting breaks fresh installs outright:
 * `savePluginSettings()` validates the whole model, so one unfilled value blocks saving every
 * *other* setting, and the install is stuck. Validate for correctness when a value is present
 * instead.
 */
class Settings extends PluginSettings
{
    /**
     * Name of the Laravel filesystem disk artifacts are published to (`config/filesystems.php`).
     * Empty means the local web root, which is right for most sites; naming a disk puts
     * artifacts on S3 or a CDN so PHP never serves them. Craft 5 called this a filesystem
     * handle, and Craft's upgrade turns each filesystem into a disk with the same name.
     *
     * Nullable, like every text setting here: Laravel turns a blank field into null.
     */
    public ?string $filesystemHandle = null;

    /** Path within the disk (or web root) that artifacts are written under. */
    public ?string $publishPath = 'caffeine';

    /** The control-panel nav label. Empty uses the plugin name. */
    public ?string $navLabel = null;

    /**
     * The control-panel nav icon: an icon name from Craft's icon picker (`magnifying-glass`,
     * `mug-hot`). Empty uses Caffeine's own mug.
     */
    public ?string $navIcon = null;

    /** Raw SVG for the nav icon. Overrides `navIcon`. Sanitised before it's drawn. */
    public ?string $navIconSvg = null;

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

    /**
     * Keep decoded artifacts in OPcache between requests. See `ArtifactCache` for when this pays
     * and what it costs; off unless OPcache has the memory to hold a whole artifact.
     */
    public bool $cacheArtifacts = false;

    public string $logLevel = LogLevel::WARNING;

    public function getRules(): array
    {
        return [
            'filesystemHandle' => ['nullable', 'string'],
            'publishPath' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9\-_\/]*$/'],
            'navLabel' => ['nullable', 'string', 'max:64'],
            'navIcon' => ['nullable', 'string', 'max:128'],
            'navIconSvg' => ['nullable', 'string', 'max:20000', 'regex:/^\s*(<\?xml[^>]*>\s*)?<svg[\s>]/i'],
            'keepVersions' => ['integer', 'min:1', 'max:100'],
            'logLevel' => [Rule::in([
                LogLevel::DEBUG,
                LogLevel::INFO,
                LogLevel::NOTICE,
                LogLevel::WARNING,
                LogLevel::ERROR,
                LogLevel::CRITICAL,
                LogLevel::ALERT,
                LogLevel::EMERGENCY,
            ])],
        ];
    }

    public function getMessages(): array
    {
        return [
            'publishPath.regex' => 'The publish path can contain only letters, numbers, hyphens, underscores and slashes.',
            'navIconSvg.regex' => 'Paste a whole <svg> element.',
        ];
    }
}
