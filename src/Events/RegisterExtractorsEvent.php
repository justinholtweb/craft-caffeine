<?php

namespace JustinHoltWeb\Caffeine\Events;

use yii\base\Event;

/**
 * Lets a plugin teach Caffeine how to index its own field type.
 */
class RegisterExtractorsEvent extends Event
{
    /** @var class-string<\JustinHoltWeb\Caffeine\Extractors\ValueExtractorInterface>[] */
    public array $extractors = [];
}
