<?php

declare(strict_types=1);

use justinholtweb\caffeine\extractors\AddressExtractor;
use justinholtweb\caffeine\extractors\CollectionExtractor;
use justinholtweb\caffeine\extractors\ColorExtractor;
use justinholtweb\caffeine\extractors\ExtractedValue;
use justinholtweb\caffeine\extractors\LinkExtractor;
use justinholtweb\caffeine\extractors\MoneyExtractor;
use justinholtweb\caffeine\extractors\OptionExtractor;
use justinholtweb\caffeine\helpers\ValueHelper;

/**
 * The built-in extractors match on shape rather than class name, so these stubs are the honest
 * test: if a plugin's value object looks like this, Caffeine indexes it usefully.
 */

class FakeLink
{
    public function __construct(private string $url, private ?string $text = null, private string $type = 'entry')
    {
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getType(): string
    {
        return $this->type;
    }
}

class FakeLinkCollection
{
    public function __construct(private array $links)
    {
    }

    public function all(): array
    {
        return $this->links;
    }
}

class FakeAddress
{
    public function __construct(
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $city = null,
        public ?string $country = null,
    ) {
    }

    public function __toString(): string
    {
        return trim("{$this->city}, {$this->country}", ', ');
    }
}

class FakeCurrency
{
    public function __construct(private string $code)
    {
    }

    public function getCode(): string
    {
        return $this->code;
    }
}

class FakeMoney
{
    public function __construct(private string $minor, private FakeCurrency $currency)
    {
    }

    public function getAmount(): string
    {
        return $this->minor;
    }

    public function getCurrency(): FakeCurrency
    {
        return $this->currency;
    }
}

class FakeOption
{
    public function __construct(public mixed $value, public ?string $label = null, public bool $selected = true)
    {
    }
}

/** A link model that also happens to carry `value` and `label`, as FreeLink's does. */
class FakeValueLabelLink
{
    public function __construct(public string $value, public ?string $label = null)
    {
    }

    public function getUrl(): ?string
    {
        return $this->value;
    }

    public function getText(): ?string
    {
        return $this->label;
    }
}

class FakeColor
{
    public function __construct(private string $hex)
    {
    }

    public function getHex(): string
    {
        return $this->hex;
    }

    public function getRgb(): string
    {
        return 'rgb(1,2,3)';
    }
}

// -------------------------------------------------------------------------------------------------

describe('LinkExtractor', function() {
    it('takes the URL as the value and the text as a part', function() {
        $extracted = (new LinkExtractor())->extract(new FakeLink('/products/saw', 'Buy a saw'));

        expect($extracted->primary)->toBe('/products/saw');
        expect($extracted->parts)->toBe(['url' => '/products/saw', 'text' => 'Buy a saw', 'type' => 'entry']);
    });

    it('ignores a link with no URL', function() {
        expect((new LinkExtractor())->extract(new FakeLink('')))->toBeNull();
    });
});

describe('CollectionExtractor', function() {
    it('unwraps a collection to its items', function() {
        $collection = new FakeLinkCollection([new FakeLink('/a'), new FakeLink('/b')]);

        expect(CollectionExtractor::supports($collection))->toBeTrue();
        expect((new CollectionExtractor())->extract($collection)->primary)->toHaveCount(2);
    });
});

describe('AddressExtractor', function() {
    it('exposes coordinates as parts a path can reach', function() {
        $extracted = (new AddressExtractor())->extract(new FakeAddress(35.2271, -80.8431, 'Charlotte', 'US'));

        expect($extracted->part('lat'))->toBe(35.2271);
        expect($extracted->part('lng'))->toBe(-80.8431);
        expect($extracted->part('city'))->toBe('Charlotte');
        expect($extracted->primary)->toBe('Charlotte, US');
    });

    it('does not claim an object with no coordinates', function() {
        expect(AddressExtractor::supports(new FakeAddress()))->toBeFalse();
    });
});

describe('MoneyExtractor', function() {
    it('converts minor units to major', function() {
        $extracted = (new MoneyExtractor())->extract(new FakeMoney('1999', new FakeCurrency('GBP')));

        expect($extracted->primary)->toBe(19.99);
        expect($extracted->part('minor'))->toBe(1999);
        expect($extracted->part('currency'))->toBe('GBP');
    });

    it('leaves a zero-decimal currency alone', function() {
        // The bug this guards: dividing yen by 100 makes every price a hundredth of itself, and
        // a price facet that is wrong by two orders of magnitude still looks plausible.
        $extracted = (new MoneyExtractor())->extract(new FakeMoney('1999', new FakeCurrency('JPY')));

        expect($extracted->primary)->toBe(1999.0);
    });
});

describe('OptionExtractor', function() {
    it('keeps the stored value, not the label', function() {
        // A refinement URL carries the value, so it has to be the one that does not change when
        // an editor rewords the label.
        $extracted = (new OptionExtractor())->extract(new FakeOption('lrg', 'Large'));

        expect($extracted->primary)->toBe('lrg');
        expect($extracted->part('label'))->toBe('Large');
    });
});

describe('extractor specificity', function() {
    it('does not read a link as a dropdown option', function() {
        // FreeLink's link model carries `value` and `label`, which is most of what option data
        // looks like. Before `selected` was required as well, this matched OptionExtractor and
        // `link.url` resolved to nothing while `link.value` quietly worked.
        $link = new FakeValueLabelLink('https://craftcms.com', 'Craft CMS');

        expect(OptionExtractor::supports($link))->toBeFalse();
        expect(LinkExtractor::supports($link))->toBeTrue();

        $extracted = (new LinkExtractor())->extract($link);

        expect($extracted->part('url'))->toBe('https://craftcms.com');
        expect($extracted->part('text'))->toBe('Craft CMS');
    });
});

describe('ColorExtractor', function() {
    it('takes the hex', function() {
        expect((new ColorExtractor())->extract(new FakeColor('#ff0000'))->primary)->toBe('#ff0000');
    });
});

describe('ValueHelper with extracted values', function() {
    $link = new ExtractedValue('/products/saw', ['url' => '/products/saw', 'text' => 'Buy a saw']);

    it('facets on the primary', function() use ($link) {
        expect(ValueHelper::flatten($link))->toBe(['/products/saw']);
    });

    it('keeps the wrapper when asked not to unwrap', function() use ($link) {
        expect(ValueHelper::flatten($link, false))->toBe([$link]);
    });

    it('puts every part in the payload', function() use ($link) {
        expect(ValueHelper::payloadValue($link))->toBe(['url' => '/products/saw', 'text' => 'Buy a saw']);
    });

    it('indexes the text and not the URL', function() use ($link) {
        // Nobody searches for "https".
        expect(ValueHelper::searchableText($link))->toBe('Buy a saw');
    });
});
