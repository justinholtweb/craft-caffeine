<?php

namespace justinholtweb\caffeine\events;

use yii\base\Event;

/**
 * Lets a plugin or module add its own element type to Caffeine.
 */
class RegisterSourcesEvent extends Event
{
    /** @var class-string<\justinholtweb\caffeine\sources\SourceInterface>[] */
    public array $sources = [];
}
