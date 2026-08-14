<?php

namespace justinholtweb\caffeine\extractors;

use Throwable;

/**
 * A money value — Craft's Money field, Commerce prices, `moneyphp/money` itself.
 *
 * The amount is the primary, as a float, because that is what makes a price sortable and
 * range-filterable. Money stores minor units (1999 for £19.99), so the currency's subunit scale
 * has to be divided out or every price facet is a hundred times too large.
 */
class MoneyExtractor implements ValueExtractorInterface
{
    public static function supports(object $value): bool
    {
        return method_exists($value, 'getAmount') && method_exists($value, 'getCurrency');
    }

    public function extract(object $value): ?ExtractedValue
    {
        try {
            $amount = $value->getAmount();
            $currency = $value->getCurrency();
        } catch (Throwable) {
            return null;
        }

        if (!is_numeric($amount)) {
            return null;
        }

        $code = is_object($currency) && method_exists($currency, 'getCode')
            ? $currency->getCode()
            : (string)$currency;

        $major = (float)$amount / (10 ** self::subunits($code));

        return new ExtractedValue($major, [
            'amount' => $major,
            'minor' => (int)$amount,
            'currency' => $code,
        ]);
    }

    /**
     * Decimal places for a currency.
     *
     * A short table rather than a dependency on `moneyphp/money`'s ISO currency list: the
     * zero-decimal currencies are the ones that actually break, and everything else is two.
     */
    private static function subunits(string $code): int
    {
        return in_array(strtoupper($code), [
            'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG',
            'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ], true) ? 0 : 2;
    }
}
