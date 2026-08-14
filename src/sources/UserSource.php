<?php

namespace justinholtweb\caffeine\sources;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use justinholtweb\caffeine\models\SourceDefinition;
use Throwable;

/**
 * Users, scoped to groups.
 *
 * Worth a word of warning, which the CP repeats: an index is a public artifact served as a
 * static file, so every field mapped into it is readable by anyone who can find the URL. A staff
 * directory is a fine use; a user index carrying email addresses is a data leak with a CDN in
 * front of it.
 */
class UserSource extends BaseSource
{
    public static function handle(): string
    {
        return 'user';
    }

    public static function displayName(): string
    {
        return Craft::t('caffeine', 'Users');
    }

    public static function elementType(): string
    {
        return User::class;
    }

    public function containerOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $options[$group->handle] = $group->name;
        }

        return $options;
    }

    public function query(SourceDefinition $definition, int $siteId): ElementQueryInterface
    {
        $query = User::find()
            ->siteId($siteId)
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($definition->containers !== []) {
            $query->group($definition->containers);
        }

        return $this->applyStatus($query, $definition);
    }

    public function covers(SourceDefinition $definition, ElementInterface $element): bool
    {
        if (!$element instanceof User) {
            return false;
        }

        if ($definition->containers !== []) {
            try {
                $handles = array_map(fn($group) => $group->handle, $element->getGroups());
            } catch (Throwable) {
                return false;
            }

            if (array_intersect($handles, $definition->containers) === []) {
                return false;
            }
        }

        // Suspended, pending and inactive accounts are excluded unless the definition asks for
        // everything — `statusFor()` maps both "live" and "enabled" onto active.
        return $this->coversStatus($definition, $element);
    }

    protected function statusFor(string $status): ?string
    {
        // Users are active, pending, suspended or inactive — never "live" or "enabled". Asking
        // for a status this element type does not have returns nothing, silently.
        return $status === 'any' ? null : User::STATUS_ACTIVE;
    }
}
