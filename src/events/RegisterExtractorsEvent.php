<?php

namespace justinholtweb\caffeine\events;

use yii\base\Event;

/**
 * Lets a plugin teach Caffeine how to index its own field type.
 */
class RegisterExtractorsEvent extends Event
{
    /** @var class-string<\justinholtweb\caffeine\extractors\ValueExtractorInterface>[] */
    public array $extractors = [];
}
