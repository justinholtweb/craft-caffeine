<?php

declare(strict_types=1);

use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\MappedRecord;
use justinholtweb\caffeine\services\Tokenizer;

beforeEach(function() {
    $this->tokenizer = new Tokenizer();
});

describe('tokenize', function() {
    it('lowercases and splits on punctuation', function() {
        expect($this->tokenizer->tokenize('Hello, World!'))->toBe(['hello', 'world']);
    });

    it('folds diacritics so accented text matches unaccented queries', function() {
        expect($this->tokenizer->tokenize('Café Crème'))->toBe(['cafe', 'creme']);
    });

    it('drops single characters', function() {
        expect($this->tokenizer->tokenize('a bb ccc'))->toBe(['bb', 'ccc']);
    });

    it('drops absurdly long tokens', function() {
        $long = str_repeat('x', Tokenizer::MAX_LENGTH + 1);

        expect($this->tokenizer->tokenize("ok {$long}"))->toBe(['ok']);
    });

    it('strips markup rather than tokenising tag names', function() {
        expect($this->tokenizer->tokenize('<p>hello</p>'))->toBe(['hello']);
    });

    it('decodes entities', function() {
        expect($this->tokenizer->tokenize('caf&eacute;'))->toBe(['cafe']);
    });

    it('keeps numbers', function() {
        expect($this->tokenizer->tokenize('model 42'))->toBe(['model', '42']);
    });

    it('leaves scripts with no case or accents alone', function() {
        expect($this->tokenizer->tokenize('日本語 テスト'))->toBe(['日本語', 'テスト']);
    });

    it('tokenises a query exactly as it tokenises a document', function() {
        // The one rule the JS side has to reimplement. If these ever diverge, a query for
        // "Café" stops matching a document containing "café" and nothing else fails loudly.
        $text = 'Café, Crème & 42';

        expect($this->tokenizer->tokenizeQuery($text))->toBe($this->tokenizer->tokenize($text));
    });
});

describe('tokenizeRecord', function() {
    it('sums weights across attributes, so a term in two fields outranks one in a single field', function() {
        $index = new IndexDefinition([
            'handle' => 'test',
            'name' => 'Test',
            'attributes' => [
                new AttributeDefinition([
                    'key' => 'title', 'source' => 'attribute', 'path' => 'title',
                    'roles' => [AttributeDefinition::ROLE_SEARCHABLE], 'searchWeight' => 5.0,
                ]),
                new AttributeDefinition([
                    'key' => 'body', 'source' => 'field', 'path' => 'body',
                    'roles' => [AttributeDefinition::ROLE_SEARCHABLE], 'searchWeight' => 1.0,
                ]),
            ],
        ]);

        $record = new MappedRecord(
            elementId: 1,
            siteId: 1,
            objectId: '1-1',
            searchable: ['title' => 'coffee beans', 'body' => 'coffee is good'],
        );

        $tokens = $this->tokenizer->tokenizeRecord($index, $record);

        expect($tokens['coffee'])->toBe(6.0)   // 5 from the title + 1 from the body
            ->and($tokens['beans'])->toBe(5.0)
            ->and($tokens['good'])->toBe(1.0);
    });

    it('returns tokens in sorted order, so identical content builds byte-identical output', function() {
        $index = new IndexDefinition([
            'handle' => 'test',
            'name' => 'Test',
            'attributes' => [
                new AttributeDefinition([
                    'key' => 'title', 'source' => 'attribute', 'path' => 'title',
                    'roles' => [AttributeDefinition::ROLE_SEARCHABLE],
                ]),
            ],
        ]);

        $record = new MappedRecord(1, 1, '1-1', searchable: ['title' => 'zebra apple mango']);
        $keys = array_keys($this->tokenizer->tokenizeRecord($index, $record));

        expect($keys)->toBe(['apple', 'mango', 'zebra']);
    });

    it('ignores attributes without the searchable role', function() {
        $index = new IndexDefinition([
            'handle' => 'test',
            'name' => 'Test',
            'attributes' => [
                new AttributeDefinition([
                    'key' => 'brand', 'source' => 'field', 'path' => 'brand',
                    'roles' => [AttributeDefinition::ROLE_FACET],
                ]),
            ],
        ]);

        $record = new MappedRecord(1, 1, '1-1', searchable: ['brand' => 'acme']);

        expect($this->tokenizer->tokenizeRecord($index, $record))->toBe([]);
    });
});
