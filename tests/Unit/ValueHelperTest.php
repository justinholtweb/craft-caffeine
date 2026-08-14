<?php

declare(strict_types=1);

use justinholtweb\caffeine\helpers\ValueHelper;
use justinholtweb\caffeine\models\AttributeDefinition;

describe('flatten', function() {
    it('flattens nested arrays', function() {
        expect(ValueHelper::flatten([1, [2, [3, 4]], 5]))->toBe([1, 2, 3, 4, 5]);
    });

    it('drops nulls and empty strings', function() {
        expect(ValueHelper::flatten([null, 'a', '', 'b']))->toBe(['a', 'b']);
    });

    it('wraps a scalar', function() {
        expect(ValueHelper::flatten('a'))->toBe(['a']);
    });

    it('treats an iterable object as a single value, not a container', function() {
        // The regression that made every relation-backed facet come out empty. Craft elements
        // extend yii\base\Model, which implements IteratorAggregate, so flattening "anything
        // iterable" explodes an element into its attribute values and nothing downstream can
        // tell it was ever an element.
        $iterable = new ArrayObject(['a' => 1, 'b' => 2]);

        expect(ValueHelper::flatten($iterable))->toBe([$iterable]);
    });
});

describe('facetValues', function() {
    it('deduplicates and sorts, so identical content builds identical postings', function() {
        $attribute = attribute(['facetType' => AttributeDefinition::FACET_STRING]);

        expect(ValueHelper::facetValues(['b', 'a', 'b'], $attribute))->toBe(['a', 'b']);
        expect(ValueHelper::facetValues(['a', 'b'], $attribute))
            ->toBe(ValueHelper::facetValues(['b', 'a'], $attribute));
    });

    it('casts numeric facets to floats', function() {
        $attribute = attribute(['facetType' => AttributeDefinition::FACET_NUMERIC]);

        expect(ValueHelper::facetValues(['10', 2, '3.5'], $attribute))->toBe([2.0, 3.5, 10.0]);
    });

    it('drops non-numeric values from a numeric facet rather than coercing them to zero', function() {
        $attribute = attribute(['facetType' => AttributeDefinition::FACET_NUMERIC]);

        expect(ValueHelper::facetValues(['abc', '5'], $attribute))->toBe([5.0]);
    });

    it('keeps booleans as booleans on a boolean facet', function() {
        $attribute = attribute(['facetType' => AttributeDefinition::FACET_BOOLEAN]);

        expect(ValueHelper::facetValues([true], $attribute))->toBe([true]);
        expect(ValueHelper::facetValues([false], $attribute))->toBe([false]);
    });

    it('turns a date into a timestamp on a date facet', function() {
        $attribute = attribute(['facetType' => AttributeDefinition::FACET_DATE]);
        $date = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        expect(ValueHelper::facetValues([$date], $attribute))->toBe([(float)$date->getTimestamp()]);
    });

    it('formats a date as a day on a string facet', function() {
        $attribute = attribute(['facetType' => AttributeDefinition::FACET_STRING]);
        $date = new DateTimeImmutable('2026-01-01 12:34:56', new DateTimeZone('UTC'));

        expect(ValueHelper::facetValues([$date], $attribute))->toBe(['2026-01-01']);
    });
});

describe('sortableValue', function() {
    it('collapses a list to its first value', function() {
        expect(ValueHelper::sortableValue(['b', 'a']))->toBe('b');
    });

    it('returns null for nothing, so the sort spec decides where nulls go', function() {
        expect(ValueHelper::sortableValue([]))->toBeNull();
        expect(ValueHelper::sortableValue(null))->toBeNull();
    });

    it('returns numbers as floats so comparisons are numeric', function() {
        expect(ValueHelper::sortableValue(['10']))->toBe(10.0);
    });
});

describe('payloadValue', function() {
    it('keeps a single value single rather than wrapping it', function() {
        expect(ValueHelper::payloadValue(['a']))->toBe('a');
    });

    it('keeps a list a list', function() {
        expect(ValueHelper::payloadValue(['a', 'b']))->toBe(['a', 'b']);
    });

    it('formats dates as ISO 8601', function() {
        $date = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        expect(ValueHelper::payloadValue([$date]))->toBe('2026-01-01T00:00:00+00:00');
    });
});

describe('applyTransforms', function() {
    it('applies transforms in order', function() {
        expect(ValueHelper::applyTransforms(['  HELLO  '], ['trim', 'lower']))->toBe(['hello']);
    });

    it('strips tags', function() {
        expect(ValueHelper::applyTransforms(['<p>hi <b>there</b></p>'], ['stripTags']))->toBe(['hi there']);
    });

    it('takes the first value', function() {
        expect(ValueHelper::applyTransforms(['a', 'b'], ['first']))->toBe(['a']);
    });

    it('drops empties with compact', function() {
        expect(ValueHelper::applyTransforms(['a', '', null, 'b'], ['compact']))->toBe(['a', 'b']);
    });

    it('formats dates', function() {
        $date = new DateTimeImmutable('2026-03-04 00:00:00', new DateTimeZone('UTC'));

        expect(ValueHelper::applyTransforms([$date], ['date:Y-m']))->toBe(['2026-03']);
    });

    it('ignores a transform it does not know rather than throwing', function() {
        // Transforms arrive from project config, which can be edited by hand and can arrive
        // from a newer version of the plugin. Failing the whole build over one unknown name
        // would be a worse outcome than skipping it.
        expect(ValueHelper::applyTransforms(['a'], ['nonsense']))->toBe(['a']);
    });
});

describe('searchableText', function() {
    it('joins everything into one string', function() {
        expect(ValueHelper::searchableText(['a', ['b', 'c']]))->toBe('a b c');
    });

    it('leaves dates and booleans out — neither is a word anyone searches for', function() {
        $date = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        expect(ValueHelper::searchableText([$date, true, 'real']))->toBe('real');
    });
});
