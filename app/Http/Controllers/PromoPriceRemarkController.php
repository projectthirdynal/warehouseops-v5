<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Promo\Models\PromoPriceRemark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Rap2hpoutre\FastExcel\FastExcel;

class PromoPriceRemarkController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $remarks = PromoPriceRemark::query()
            ->when($search, static function ($query, $search) {
                $query->where('price_key', 'ilike', "%{$search}%")
                    ->orWhere('remarks', 'ilike', "%{$search}%");
            })
            ->latest('imported_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Telesales/Promos/PriceRemarks', [
            'remarks' => $remarks,
            'filters' => ['search' => $search],
            'stats' => [
                'total' => PromoPriceRemark::count(),
                'uniquePrices' => PromoPriceRemark::distinct('price_key')->count('price_key'),
            ],
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $file = $request->file('file');
        $userId = $request->user()?->id;
        $now = now();

        $rows = (new FastExcel)->withoutHeaders()->import($file);

        DB::beginTransaction();

        try {
            $inserted = 0;
            $lastPriceKey = '';

            foreach ($rows as $row) {
                $values = array_values($row);
                $rawPriceKey = $this->normalizeValue($values[0] ?? null);
                $remarks = $this->normalizeValue($values[1] ?? null);

                if ($rawPriceKey !== '') {
                    $lastPriceKey = $rawPriceKey;
                }

                if ($lastPriceKey === '' || $remarks === '') {
                    continue;
                }

                PromoPriceRemark::create([
                    'price_key' => $lastPriceKey,
                    'remarks' => $remarks,
                    'imported_by' => $userId,
                    'imported_at' => $now,
                ]);

                $inserted++;
            }

            DB::commit();

            return redirect()
                ->route('telesales.promos.price-remarks.index')
                ->with('success', "Imported {$inserted} price remark rows.");
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('telesales.promos.price-remarks.index')
                ->with('error', 'Import failed: '.$e->getMessage());
        }
    }

    public function truncate(Request $request): RedirectResponse
    {
        PromoPriceRemark::query()->delete();

        return redirect()
            ->route('telesales.promos.price-remarks.index')
            ->with('success', 'Price remarks cleared.');
    }

    public function listAll(Request $request): JsonResponse
    {
        $remarks = PromoPriceRemark::orderBy('price_key')->orderBy('remarks')->get([
            'id',
            'price_key',
            'remarks',
        ]);

        return response()->json(['remarks' => $remarks]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $price = (float) $request->input('price', 0);

        if ($price <= 0) {
            return response()->json(['remarks' => []]);
        }

        $remarks = PromoPriceRemark::all()
            ->filter(function (PromoPriceRemark $row) use ($price) {
                $range = $this->parsePriceKey($row->price_key);

                if ($range === null) {
                    return false;
                }

                return $price >= $range['min'] && $price <= $range['max'];
            })
            ->map(fn (PromoPriceRemark $row) => [
                'id' => $row->id,
                'price_key' => $row->price_key,
                'remarks' => $row->remarks,
            ])
            ->values();

        return response()->json(['remarks' => $remarks]);
    }

    private function parsePriceKey(string $priceKey): ?array
    {
        preg_match_all('/\d+(?:\.\d+)?/', $priceKey, $matches);

        if (empty($matches[0])) {
            return null;
        }

        $numbers = array_map('floatval', $matches[0]);

        if (count($numbers) === 1) {
            return ['min' => $numbers[0], 'max' => $numbers[0]];
        }

        return [
            'min' => min($numbers[0], $numbers[1]),
            'max' => max($numbers[0], $numbers[1]),
        ];
    }

    private function normalizeValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = trim((string) $value);

        return $value;
    }
}
