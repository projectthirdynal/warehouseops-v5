<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use Modules\Finance\Services\MultiCurrencyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class MultiCurrencyController extends Controller
{
    public function __construct(
        private readonly MultiCurrencyService $service,
    ) {}

    public function index()
    {
        $currencies = $this->service->listAllCurrencies();
        $rateBoard = $this->service->getRateBoard();
        $rates = $this->service->getExchangeRates(25);

        return Inertia::render('Finance/MultiCurrency', [
            'currencies' => $currencies,
            'rateBoard' => $rateBoard,
            'rates' => $rates['data'],
            'ratesMeta' => $rates['meta'],
        ]);
    }

    public function apiIndex()
    {
        $currencies = $this->service->listAllCurrencies();
        $rateBoard = $this->service->getRateBoard();

        return response()->json([
            'currencies' => $currencies,
            'rate_board' => $rateBoard,
        ]);
    }

    public function apiCurrencies()
    {
        return response()->json($this->service->listAllCurrencies());
    }

    public function apiStoreCurrency(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:3',
            'name' => 'required|string|max:60',
            'symbol' => 'required|string|max:6',
            'decimal_places' => 'nullable|integer|min:0|max:8',
            'is_active' => 'nullable|boolean',
        ]);

        $currency = $this->service->upsertCurrency(
            $validated['code'],
            $validated['name'],
            $validated['symbol'],
            $validated['decimal_places'] ?? 2,
            $validated['is_active'] ?? true,
        );

        return response()->json($currency, 201);
    }

    public function apiToggleCurrency(string $code)
    {
        $currency = $this->service->toggleCurrency($code);

        return response()->json($currency);
    }

    public function apiExchangeRates(Request $request)
    {
        $perPage = (int) ($request->get('per_page', 25));

        return response()->json($this->service->getExchangeRates($perPage));
    }

    public function apiStoreRate(Request $request)
    {
        $validated = $request->validate([
            'from_currency' => 'required|string|size:3',
            'to_currency' => 'required|string|size:3',
            'rate' => 'required|numeric|min:0.000001',
            'rate_date' => 'nullable|date',
            'source' => 'nullable|string|max:30',
        ]);

        $rate = $this->service->upsertRate(
            $validated['from_currency'],
            $validated['to_currency'],
            (float) $validated['rate'],
            isset($validated['rate_date']) ? Carbon::parse($validated['rate_date']) : null,
            $validated['source'] ?? 'manual',
        );

        return response()->json($rate, 201);
    }

    public function apiDeleteRate(int $id)
    {
        $deleted = $this->service->deleteRate($id);

        return response()->json(['deleted' => $deleted]);
    }

    public function apiRateHistory(Request $request, string $from, string $to)
    {
        $limit = (int) ($request->get('limit', 30));

        return response()->json($this->service->getRateHistory($from, $to, $limit));
    }

    public function apiConvert(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric',
            'from' => 'required|string|size:3',
            'to' => 'required|string|size:3',
            'date' => 'nullable|date',
        ]);

        $date = isset($validated['date']) ? Carbon::parse($validated['date']) : null;
        $rate = $this->service->getRate($validated['from'], $validated['to'], $date);
        $converted = $this->service->convert((float) $validated['amount'], $validated['from'], $validated['to'], $date);

        return response()->json([
            'original_amount' => (float) $validated['amount'],
            'from_currency' => strtoupper($validated['from']),
            'to_currency' => strtoupper($validated['to']),
            'exchange_rate' => $rate,
            'converted_amount' => $converted,
        ]);
    }
}
