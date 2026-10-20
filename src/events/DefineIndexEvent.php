<?php

namespace justinholtweb\caffeine\events;

use justinholtweb\caffeine\models\IndexDefinition;
use yii\base\Event;

/**
 * Lets a plugin or module add to an index definition as it loads.
 *
 * For facets that come from content rather than from the index: an attribute set editors grow
 * in the control panel, where every new attribute should become a facet without a deploy.
 * Anything a handler adds to `$index->attributes` or `$index->sortings` applies to the build and
 * the query like any other definition, and is never written to project config.
 */
class DefineIndexEvent extends Event
{
    /** The definition as loaded from project config. Modify it in place. */
    public IndexDefinition $index;
}
