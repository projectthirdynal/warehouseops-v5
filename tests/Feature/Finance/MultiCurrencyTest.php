<?php

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\MultiCurrencyService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\VerifyCsrfToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
    $this->service = app(MultiCurrencyService::class);
    $this->withoutMiddleware([VerifyCsrfToken::class]);

    // Seed base currencies
    Currency::create(['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'decimal_places' => 2, 'is_active' => true]);
    Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true]);
    Currency::create(['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'decimal_places' => 2, 'is_active' => true]);
});

describe('MultiCurrencyService', function () {
    it('gets rate for same currency as 1.0', function () {
        $rate = $this->service->getRate('PHP', 'PHP');

        expect($rate)->toBe(1.0);
    });

    it('gets rate for a currency pair', function () {
        $this->service->upsertRate('USD', 'PHP', 56.50);

        $rate = $this->service->getRate('USD', 'PHP');

        expect($rate)->toBeFloat();
        expect($rate)->toBe(56.50);
    });

    it('falls back to inverse rate when direct rate is missing', function () {
        $this->service->upsertRate('PHP', 'USD', 0.0177);

        $rate = $this->service->getRate('USD', 'PHP');

        expect($rate)->toBeFloat();
        expect($rate)->toBeGreaterThan(0);
    });

    it('converts amount between currencies', function () {
        $this->service->upsertRate('USD', 'PHP', 56.50);

        $converted = $this->service->convert(100.0, 'USD', 'PHP');

        expect($converted)->toBeFloat();
        expect($converted)->toBe(5650.0);
    });

    it('upserts exchange rate (creates then updates)', function () {
        $rate1 = $this->service->upsertRate('USD', 'PHP', 56.00);
        expect($rate1->rate)->toBe('56.000000');

        $rate2 = $this->service->upsertRate('USD', 'PHP', 57.00);
        expect($rate2->rate)->toBe('57.000000');
        expect(ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'PHP')->count())->toBe(1);
    });

    it('lists active currencies', function () {
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'is_active' => false]);

        $active = $this->service->listCurrencies();

        expect($active)->toHaveCount(3);
        expect($active->pluck('code')->toArray())->toBe(['CNY', 'PHP', 'USD']);
    });

    it('lists all currencies including inactive', function () {
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'is_active' => false]);

        $all = $this->service->listAllCurrencies();

        expect($all)->toHaveCount(4);
    });

    it('gets rate history for a pair', function () {
        $this->service->upsertRate('USD', 'PHP', 55.00, now()->subDays(2));
        $this->service->upsertRate('USD', 'PHP', 56.00, now()->subDay());
        $this->service->upsertRate('USD', 'PHP', 56.50, now());

        $history = $this->service->getRateHistory('USD', 'PHP', 10);

        expect($history)->toHaveCount(3);
        expect($history[0]['rate'])->toBe('56.500000');
    });

    it('toggles currency active status', function () {
        $currency = $this->service->toggleCurrency('USD');

        expect($currency->is_active)->toBeFalse();

        $currency = $this->service->toggleCurrency('USD');
        expect($currency->is_active)->toBeTrue();
    });

    it('deletes an exchange rate', function () {
        $rate = $this->service->upsertRate('USD', 'PHP', 56.50);

        $deleted = $this->service->deleteRate($rate->id);

        expect($deleted)->toBeTrue();
        expect(ExchangeRate::find($rate->id))->toBeNull();
    });

    it('gets rate board with latest rates for all pairs', function () {
        $this->service->upsertRate('USD', 'PHP', 56.50);
        $this->service->upsertRate('CNY', 'PHP', 7.80);
        $this->service->upsertRate('USD', 'CNY', 7.24);

        $board = $this->service->getRateBoard();

        expect($board)->toHaveCount(6);
        $usdPhp = $board->firstWhere('from', 'USD')->where('to', 'PHP');
        expect($usdPhp)->not->toBeNull();
    });

    it('upserts a currency', function () {
        $currency = $this->service->upsertCurrency('EUR', 'Euro', '€', 2, true);

        expect($currency->code)->toBe('EUR');
        expect($currency->name)->toBe('Euro');
        expect($currency->is_active)->toBeTrue();
    });
});

describe('Multi-Currency API Endpoints', function () {
    it('GET /finance/multi-currency renders the page', function () {
        $response = $this->actingAs($this->user)->get('/finance/multi-currency');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('currencies')
            ->has('rateBoard')
            ->has('rates')
        );
    });

    it('GET /finance/multi-currency/api returns currencies and rate board', function () {
        $response = $this->actingAs($this->user)->getJson('/finance/multi-currency/api');

        $response->assertOk();
        $response->assertJsonStructure([
            'currencies',
            'rate_board',
        ]);
    });

    it('GET /finance/multi-currency/api/currencies returns all currencies', function () {
        $response = $this->actingAs($this->user)->getJson('/finance/multi-currency/api/currencies');

        $response->assertOk();
        $response->assertJsonCount(3);
    });

    it('POST /finance/multi-currency/api/currencies creates a new currency', function () {
        $response = $this->actingAs($this->user)->postJson('/finance/multi-currency/api/currencies', [
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        $response->assertCreated();
        $response->assertJson(['code' => 'EUR', 'name' => 'Euro']);
    });

    it('PATCH /finance/multi-currency/api/currencies/{code}/toggle toggles status', function () {
        $response = $this->actingAs($this->user)->patchJson('/finance/multi-currency/api/currencies/USD/toggle');

        $response->assertOk();
        $response->assertJson(['code' => 'USD', 'is_active' => false]);
    });

    it('GET /finance/multi-currency/api/exchange-rates returns paginated rates', function () {
        $this->service->upsertRate('USD', 'PHP', 56.50);

        $response = $this->actingAs($this->user)->getJson('/finance/multi-currency/api/exchange-rates');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
    });

    it('POST /finance/multi-currency/api/exchange-rates creates a rate', function () {
        $response = $this->actingAs($this->user)->postJson('/finance/multi-currency/api/exchange-rates', [
            'from_currency' => 'USD',
            'to_currency' => 'PHP',
            'rate' => 56.50,
            'source' => 'manual',
        ]);

        $response->assertCreated();
        $response->assertJson(['from_currency' => 'USD', 'to_currency' => 'PHP']);
    });

    it('DELETE /finance/multi-currency/api/exchange-rates/{id} deletes a rate', function () {
        $rate = $this->service->upsertRate('USD', 'PHP', 56.50);

        $response = $this->actingAs($this->user)->deleteJson("/finance/multi-currency/api/exchange-rates/{$rate->id}");

        $response->assertOk();
        $response->assertJson(['deleted' => true]);
    });

    it('GET /finance/multi-currency/api/rate-history/{from}/{to} returns history', function () {
        $this->service->upsertRate('USD', 'PHP', 55.00, now()->subDay());
        $this->service->upsertRate('USD', 'PHP', 56.50, now());

        $response = $this->actingAs($this->user)->getJson('/finance/multi-currency/api/rate-history/USD/PHP');

        $response->assertOk();
        $response->assertJsonCount(2);
    });

    it('POST /finance/multi-currency/api/convert converts an amount', function () {
        $this->service->upsertRate('USD', 'PHP', 56.50);

        $response = $this->actingAs($this->user)->postJson('/finance/multi-currency/api/convert', [
            'amount' => 100,
            'from' => 'USD',
            'to' => 'PHP',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'original_amount',
            'from_currency',
            'to_currency',
            'exchange_rate',
            'converted_amount',
        ]);
        $response->assertJson(['original_amount' => 100.0, 'converted_amount' => 5650.0]);
    });
});
