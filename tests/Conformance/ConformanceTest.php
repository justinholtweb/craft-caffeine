<?php

declare(strict_types=1);

use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\MappedRecord;
use justinholtweb\caffeine\search\ArtifactDecoder;
use justinholtweb\caffeine\search\ArtifactEncoder;
use justinholtweb\caffeine\search\Compiler;
use justinholtweb\caffeine\search\Engine;
use justinholtweb\caffeine\search\QueryState;
use justinholtweb\caffeine\search\FacetValue;
use justinholtweb\caffeine\search\UrlState;
use justinholtweb\caffeine\search\Varint;
use justinholtweb\caffeine\services\Tokenizer;

/**
 * The PHP half of the conformance suite.
 *
 * The same fixtures are run against the JavaScript engine by tests/Conformance/run.mjs. Both
 * must produce identical results, because the same page uses both: PHP renders the first paint,
 * the browser takes over refinement, and any disagreement shows up as the page rearranging
 * itself under the visitor.
 *
 * As a side effect this writes each compiled artifact to tests/Conformance/build/, which is what
 * the JavaScript runner consumes. That mirrors production exactly — compilation only ever
 * happens in PHP — and means the JS engine is tested against a real artifact rather than a
 * hand-written one.
 */

$fixtures = glob(__DIR__ . '/*.json') ?: [];

foreach ($fixtures as $path) {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    if (($fixture['type'] ?? null) === 'facetvalue') {
        describe("conformance: {$fixture['name']}", function() use ($fixture) {
            foreach ($fixture['cases'] as $case) {
                it('projects ' . var_export($case['value'], true), function() use ($case) {
                    expect(FacetValue::toKey($case['value']))->toBe($case['expect']);

                    // And back again, out of a dictionary holding exactly this value — the
                    // round trip a facet link makes on every click.
                    expect(FacetValue::fromKey($case['expect'], [$case['value']]))->toEqual($case['value']);
                });
            }
        });

        continue;
    }

    if (($fixture['type'] ?? null) === 'urlstate') {
        describe("conformance: {$fixture['name']}", function() use ($fixture) {
            foreach ($fixture['cases'] as $case) {
                it($case['name'], function() use ($fixture, $case) {
                    $options = $case['options'] ?? [];

                    // Parse-only cases cover input shapes nothing ever encodes — the `_min`/`_max`
                    // fields a plain form posts, and the junk a real query string arrives with.
                    if (isset($case['parseParams'])) {
                        $parsed = UrlState::parse($case['parseParams'], $fixture['facets'], $options);

                        expect([
                            'query' => $parsed->query,
                            'refinements' => $parsed->refinements,
                            'ranges' => $parsed->ranges,
                            'sortBy' => $parsed->sortBy,
                            'page' => $parsed->page,
                            'hitsPerPage' => $parsed->hitsPerPage,
                        ])->toEqual($case['expectParsed']);

                        return;
                    }

                    $state = QueryState::fromArray($case['state']);

                    // The encoded parameters and the finished href are compared strictly. This is
                    // where a cross-language disagreement actually bites: the server renders the
                    // href and the runtime compares against it.
                    expect(UrlState::encode($state, $options))->toBe($case['expectParams']);
                    expect(UrlState::url($fixture['path'], $state, $options))->toBe($case['expectUrl']);

                    // Parsing is compared loosely, because a refinement's PHP type comes from the
                    // artifact's dictionary — int(10) where JSON says 10 — and that is the point
                    // of resolving values against the dictionary rather than coercing them.
                    $parsed = UrlState::parse($case['expectParams'], $fixture['facets'], $options);

                    expect([
                        'query' => $parsed->query,
                        'refinements' => $parsed->refinements,
                        'ranges' => $parsed->ranges,
                        'sortBy' => $parsed->sortBy,
                        'page' => $parsed->page,
                        'hitsPerPage' => $parsed->hitsPerPage,
                    ])->toEqual($case['expectParsed']);
                });
            }
        });

        continue;
    }

    if (($fixture['type'] ?? null) === 'varint') {
        describe("conformance: {$fixture['name']}", function() use ($fixture) {
            foreach ($fixture['cases'] as $case) {
                $label = $case['codec'] . ' ' . json_encode($case['values']);

                it("encodes and decodes {$label}", function() use ($case) {
                    $delta = $case['codec'] === 'delta';

                    // The encoding is what pins this implementation against the JavaScript one;
                    // the round trip catches a decoder that is wrong in the same direction as
                    // its own encoder, which an encode-only check would wave through.
                    expect($delta ? Varint::encodeDelta($case['values']) : Varint::encode($case['values']))
                        ->toBe($case['expect']);

                    expect($delta ? Varint::decodeDelta($case['expect']) : Varint::decode($case['expect']))
                        ->toBe($case['values']);
                });
            }
        });

        continue;
    }

    if (($fixture['type'] ?? 'search') === 'tokens') {
        describe("conformance: {$fixture['name']}", function() use ($fixture) {
            foreach ($fixture['cases'] as $case) {
                it('tokenises ' . var_export($case['input'], true), function() use ($case) {
                    expect(\justinholtweb\caffeine\search\Tokenizer::tokenize($case['input']))
                        ->toBe($case['expect']);
                });
            }
        });

        continue;
    }

    describe("conformance: {$fixture['name']}", function() use ($fixture) {
        beforeEach(function() use ($fixture) {
            $this->index = IndexDefinition::fromConfig('fixture-uid', $fixture['index']);
            $this->artifact = (new Compiler())->compile($this->index, conformanceRecords($fixture, $this->index));

            conformanceWriteArtifact($fixture['name'], $this->artifact);

            $encoded = (new ArtifactEncoder())->encode($this->artifact);
            // The version is passed in because the shard documents deliberately omit it; in
            // production it comes from the manifest the publisher writes alongside them.
            $this->decoded = (new ArtifactDecoder())->decode($encoded['index'], $encoded['payload'], $this->artifact->version);
        });

        it('survives the wire format unchanged', function() {
            // Identical, not merely equivalent. The server renders the first paint from a
            // compiled artifact and the browser refines against a decoded one, so anything the
            // codec rounds, reorders or retypes is a disagreement waiting to surface as the page
            // rearranging itself under the visitor.
            expect($this->decoded->toArray())->toBe($this->artifact->toArray());
        });

        foreach ($fixture['cases'] as $case) {
            it($case['name'], function() use ($case) {
                $engine = new Engine($this->artifact);
                $result = $engine->search(
                    QueryState::fromArray($case['state']),
                    $this->index->hitsPerPage,
                );

                conformanceAssert($result, $case['expect']);
            });

            // The same case against an artifact that has been through the wire format. The
            // round-trip assertion above should make this redundant — and if it ever stops being
            // redundant, this is what says so.
            it($case['name'] . ' — decoded', function() use ($case) {
                $engine = new Engine($this->decoded);
                $result = $engine->search(
                    QueryState::fromArray($case['state']),
                    $this->index->hitsPerPage,
                );

                conformanceAssert($result, $case['expect']);
            });
        }
    });
}
