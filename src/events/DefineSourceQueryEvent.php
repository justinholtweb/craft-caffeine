<?php

namespace justinholtweb\caffeine\events;

use craft\elements\db\ElementQueryInterface;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SourceDefinition;
use justinholtweb\caffeine\sources\SourceInterface;
use yii\base\Event;

/**
 * Lets a plugin or module adjust the query a build loads elements with.
 *
 * For eager loading, above all. Mapping reads variants, relations and field values element by
 * element, and without eager loading each of those is a query of its own, per element: on a
 * Commerce catalogue that was most of the build. Declaring them here loads each one once per
 * batch instead:
 *
 * ```php
 * Event::on(Builder::class, Builder::EVENT_DEFINE_SOURCE_QUERY, function(DefineSourceQueryEvent $event) {
 *     if ($event->index->handle === 'products') {
 *         $event->query->with(['variants', 'productCategories']);
 *     }
 * });
 * ```
 *
 * Fired for the queries whose elements are mapped into records: a full build, and the rebuild of
 * changed elements. Not for the queries that only count elements or check which still exist, so
 * this is the wrong place to change *which* elements an index holds. That is the source
 * definition's job, and narrowing the query here would make a full build prune records that the
 * incremental path would then rebuild.
 */
class DefineSourceQueryEvent extends Event
{
    public IndexDefinition $index;

    public SourceInterface $source;

    public SourceDefinition $definition;

    public int $siteId;

    /** The query about to be run. Modify it in place. */
    public ElementQueryInterface $query;
}
