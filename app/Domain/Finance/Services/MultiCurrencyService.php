<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MultiCurrencyService
{
    /**
     * Get the exchange rate for a currency pair on a given date.
     * Falls back to the most recent rate before that date.
     * Returns 1.0 if from === to.
     */
    public function getRate(string $from, string $to, ?Carbon $date = null): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $date = $date ?? now();

        $rate = ExchangeRate::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('rate_date', '<=', $date->toDateString())
            ->orderByDesc('rate_date')
            ->first();

        if ($rate) {
            return (float) $rate->rate;
        }

        // Try inverse
        $inverse = ExchangeRate::where('from_currency', $to)
            ->where('to_currency', $from)
            ->where('rate_date', '<=', $date->toDateString())
            ->orderByDesc('rate_date')
            ->first();

        if ($inverse) {
            return 1.0 / (float) $inverse->rate;
        }

        return 1.0;
    }

    /**
     * Convert an amount from one currency to another.
     */
    public function convert(float $amount, string $from, string $to, ?Carbon $date = null): float
    {
        $rate = $this->getRate($from, $to, $date);

        return round($amount * $rate, (new Currency)->where('code', $to)->value('decimal_places') ?? 2);
    }

    /**
     * Convert a collection of amounts, returning both original and converted values.
     */
    public function convertCollection(Collection $items, string $amountField, string $from, string $to, ?Carbon $date = null): Collection
    {
        $rate = $this->getRate($from, $to, $date);
        $decimalPlaces = Currency::where('code', $to)->value('decimal_places') ?? 2;

        return $items->map(function ($item) use ($amountField, $rate, $decimalPlaces, $from, $to) {
            $originalAmount = (float) (is_array($item) ? ($item[$amountField] ?? 0) : ($item->{$amountField} ?? 0));
            $convertedAmount = round($originalAmount * $rate, $decimalPlaces);

            if (is_array($item)) {
                $item['original_amount'] = $originalAmount;
                $item['original_currency'] = $from;
                $item['converted_amount'] = $convertedAmount;
                $item['converted_currency'] = $to;
                $item['exchange_rate'] = $rate;
            } else {
                $item->original_amount = $originalAmount;
                $item->original_currency = $from;
                $item->converted_amount = $convertedAmount;
                $item->converted_currency = $to;
                $item->exchange_rate = $rate;
            }

            return $item;
        });
    }

    /**
     * Upsert an exchange rate for a currency pair and date.
     */
    public function upsertRate(string $from, string $to, float $rate, ?Carbon $date = null, string $source = 'manual'): ExchangeRate
    {
        $date = $date ?? now();

        return ExchangeRate::updateOrCreate(
            [
                'from_currency' => strtoupper($from),
                'to_currency' => strtoupper($to),
                'rate_date' => $date->toDateString(),
            ],
            [
                'rate' => $rate,
                'source' => $source,
            ]
        );
    }

    /**
     * Get all active currencies.
     */
    public function listCurrencies(): Collection
    {
        return Currency::active()->orderBy('code')->get();
    }

    /**
     * Get all currencies (including inactive).
     */
    public function listAllCurrencies(): Collection
    {
        return Currency::orderBy('code')->get();
    }

    /**
     * Get exchange rate history for a currency pair.
     */
    public function getRateHistory(string $from, string $to, int $limit = 30): Collection
    {
        return ExchangeRate::where('from_currency', strtoupper($from))
            ->where('to_currency', strtoupper($to))
            ->orderByDesc('rate_date')
            ->limit($limit)
            ->get()
            ->values();
    }

    /**
     * Get all exchange rates with currency details, paginated.
     */
    public function getExchangeRates(int $perPage = 25): array
    {
        $rates = ExchangeRate::with(['fromCurrency:id,code,name,symbol', 'toCurrency:id,code,name,symbol'])
            ->orderByDesc('rate_date')
            ->orderBy('from_currency')
            ->orderBy('to_currency')
            ->paginate($perPage);

        return [
            'data' => $rates->items(),
            'meta' => [
                'current_page' => $rates->currentPage(),
                'last_page' => $rates->lastPage(),
                'per_page' => $rates->perPage(),
                'total' => $rates->total(),
            ],
        ];
    }

    /**
     * Create or update a currency.
     */
    public function upsertCurrency(string $code, string $name, string $symbol, int $decimalPlaces = 2, bool $isActive = true): Currency
    {
        return Currency::updateOrCreate(
            ['code' => strtoupper($code)],
            [
                'name' => $name,
                'symbol' => $symbol,
                'decimal_places' => $decimalPlaces,
                'is_active' => $isActive,
            ]
        );
    }

    /**
     * Toggle a currency's active status.
     */
    public function toggleCurrency(string $code): Currency
    {
        $currency = Currency::findOrFail(strtoupper($code));
        $currency->update(['is_active' => ! $currency->is_active]);

        return $currency->fresh();
    }

    /**
     * Delete an exchange rate.
     */
    public function deleteRate(int $id): bool
    {
        return ExchangeRate::where('id', $id)->delete() > 0;
    }

    /**
     * Get a summary of all currency pairs with their latest rates.
     */
    public function getRateBoard(): Collection
    {
        $currencies = Currency::active()->orderBy('code')->pluck('code');

        $board = collect();

        foreach ($currencies as $from) {
            foreach ($currencies as $to) {
                if ($from === $to) {
                    continue;
                }

                $rate = ExchangeRate::where('from_currency', $from)
                    ->where('to_currency', $to)
                    ->orderByDesc('rate_date')
                    ->first();

                if ($rate) {
                    $board->push([
                        'from' => $from,
                        'to' => $to,
                        'rate' => (float) $rate->rate,
                        'rate_date' => $rate->rate_date->toDateString(),
                        'source' => $rate->source,
                    ]);
                }
            }
        }

        return $board;
    }
}
