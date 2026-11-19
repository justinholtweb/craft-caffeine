<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Models;

use CraftCms\Cms\Component\Component;

/**
 * Base for Caffeine's definition models.
 *
 * Craft 6 validates components with Laravel rules (`getRules()`). The cross-field checks the
 * definitions need — "an index needs at least one source", "two attributes share a key" — don't
 * fit a rule string, so they live in `checks()`, which runs after the rules on every
 * `validate()`. Unlike Yii's inline validators these always run, empty attribute or not: the
 * Craft 5 version needed `skipOnEmpty => false` to stop "no sources" from passing silently.
 */
abstract class Model extends Component
{
    public function validate(array|string|null $attributeNames = null, bool $clearErrors = true, bool $throw = false): bool
    {
        parent::validate($attributeNames, $clearErrors);

        if ($attributeNames === null) {
            $this->checks();
        }

        return $this->errors()->isEmpty();
    }

    public function addError(string $attribute, string $message): void
    {
        $this->errors()->add($attribute, $message);
    }

    public function hasErrors(?string $attribute = null): bool
    {
        return $attribute === null ? $this->errors()->isNotEmpty() : $this->errors()->has($attribute);
    }

    /**
     * Errors as plain arrays: every attribute's messages, or one attribute's.
     *
     * @return array<string, list<string>>|list<string>
     */
    public function getErrors(?string $attribute = null): array
    {
        return $attribute === null ? $this->errors()->getMessages() : $this->errors()->get($attribute);
    }

    /**
     * Cross-field validation, run after the rules. Report problems with `addError()`.
     */
    protected function checks(): void {}
}
