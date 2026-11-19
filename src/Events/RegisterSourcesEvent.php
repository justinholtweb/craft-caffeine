<?php

namespace JustinHoltWeb\Caffeine\Events;

use yii\base\Event;

/**
 * Lets a plugin or module add its own element type to Caffeine.
 */
class RegisterSourcesEvent extends Event
{
    /** @var class-string<\JustinHoltWeb\Caffeine\Sources\SourceInterface>[] */
    public array $sources = [];
}
