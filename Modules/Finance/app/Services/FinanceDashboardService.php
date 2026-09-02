<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SupplierInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\AgentCommission;
use Modules\Finance\Models\CodSettlement;
use Modules\Finance\Models\CogsEntry;
use Modules\Finance\Models\FinancialTransaction;
use Modules\Finance\Models\PaymentTransaction;
use Modules\Inventory\Models\CapexAsset;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;

class FinanceDashboardService
{
    /**
     * Get cash flow data for a date range.
     * Inflows: revenue, COD received, payment gateway incoming
     * Outflows: supplier invoices paid, shipping, commissions paid, COD courier fees
     */
    public function getCashFlow(Carbon $from, Carbon $to): array
    {
        // Inflows
        $revenueInflow = (float) FinancialTransaction::where('type', 'REVENUE')
            ->whereBetween('transaction_date', [$from, $to])
            ->sum('amount');

        $codReceived = (float) CodSettlement::where('status', 'RECEIVED')
            ->whereBetween('received_at', [$from, $to])
            ->sum('net_amount');

        $gatewayInflow = (float) PaymentTransaction::where('transaction_type', 'INCOMING')
            ->whereIn('status', ['VERIFIED', 'RECONCILED'])
            ->whereBetween('transaction_date', [$from, $to])
            ->sum('amount');

        $invoicePayments = (float) InvoicePayment::whereBetween('payment_date', [$from, $to])
            ->sum('amount');

        $totalInflows = $revenueInflow + $codReceived + $gatewayInflow + $invoicePayments;

        // Outflows
        $supplierPayments = (float) SupplierInvoice::where('status', 'PAID')
            ->whereBetween('updated_at', [$from, $to])
            ->sum('amount_paid');

        $shippingOutflow = (float) abs(FinancialTransaction::where('type', 'SHIPPING_COST')
            ->whereBetween('transaction_date', [$from, $to])
            ->sum('amount'));

        $commissionsPaid = (float) AgentCommission::where('status', 'PAID')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('commission_amount');

        $codCourierFees = (float) CodSettlement::where('status', 'RECEIVED')
            ->whereBetween('received_at', [$from, $to])
            ->sum('courier_fee');

        $totalOutflows = $supplierPayments + $shippingOutflow + $commissionsPaid + $codCourierFees;

        $netCashFlow = $totalInflows - $totalOutflows;

        // Daily cash flow trend
        $dailyInflows = FinancialTransaction::whereIn('type', ['REVENUE'])
            ->whereBetween('transaction_date', [$from, $to])
            ->selectRaw('transaction_date, SUM(amount) as total')
            ->groupBy('transaction_date')
            ->orderBy('transaction_date')
            ->get()
            ->keyBy(fn ($r) => $r->transaction_date->toDateString());

        $dailyOutflows = FinancialTransaction::whereIn('type', ['SHIPPING_COST', 'COMMISSION'])
            ->whereBetween('transaction_date', [$from, $to])
            ->selectRaw('transaction_date, SUM(abs(amount)) as total')
            ->groupBy('transaction_date')
            ->orderBy('transaction_date')
            ->get()
            ->keyBy(fn ($r) => $r->transaction_date->toDateString());

        $trend = [];
        $period = $from->copy();
        while ($period <= $to) {
            $dateStr = $period->toDateString();
            $inflow = (float) ($dailyInflows[$dateStr]?->total ?? 0);
            $outflow = (float) ($dailyOutflows[$dateStr]?->total ?? 0);
            $trend[] = [
                'date' => $period->format('M d'),
                'inflow' => $inflow,
                'outflow' => $outflow,
                'net' => $inflow - $outflow,
            ];
            $period->addDay();
        }

        return [
            'inflows' => [
                'revenue' => $revenueInflow,
                'cod_received' => $codReceived,
                'gateway' => $gatewayInflow,
                'invoice_payments' => $invoicePayments,
                'total' => $totalInflows,
            ],
            'outflows' => [
                'supplier_payments' => $supplierPayments,
                'shipping' => $shippingOutflow,
                'commissions' => $commissionsPaid,
                'courier_fees' => $codCourierFees,
                'total' => $totalOutflows,
            ],
            'net_cash_flow' => $netCashFlow,
            'trend' => $trend,
        ];
    }

    /**
     * Get P&L trend — monthly breakdown for comparison.
     */
    public function getPlTrend(int $months = 6): array
    {
        $trend = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();

            $transactions = FinancialTransaction::whereBetween('transaction_date', [$monthStart, $monthEnd]);

            $revenue = (float) $transactions->clone()->where('type', 'REVENUE')->sum('amount');
            $refunds = (float) abs($transactions->clone()->where('type', 'REFUND')->sum('amount'));
            $shipping = (float) abs($transactions->clone()->where('type', 'SHIPPING_COST')->sum('amount'));
            $commissions = (float) abs($transactions->clone()->where('type', 'COMMISSION')->sum('amount'));

            $cogs = (float) CogsEntry::whereBetween('recorded_at', [$monthStart, $monthEnd])
                ->sum('total_cost');

            $netRevenue = $revenue - $refunds;
            $grossProfit = $netRevenue - $cogs;
            $netProfit = $grossProfit - $shipping - $commissions;
            $margin = $netRevenue > 0 ? (float) round(($netProfit / $netRevenue) * 100, 1) : 0.0;

            $trend[] = [
                'month' => $monthStart->format('M Y'),
                'revenue' => $netRevenue,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'shipping' => $shipping,
                'commissions' => $commissions,
                'net_profit' => $netProfit,
                'margin' => $margin,
            ];
        }

        return $trend;
    }

    /**
     * Get balance sheet summary — assets, liabilities, equity.
     */
    public function getBalanceSheet(): array
    {
        // Assets
        $inventoryValue = (float) DB::table('stock_cost_lots')
            ->where('quantity_remaining', '>', 0)
            ->sum(DB::raw('quantity_remaining * unit_cost'));

        $accountsReceivable = (float) Invoice::whereNotIn('status', ['DRAFT', 'CANCELLED', 'PAID'])
            ->sum('amount_due');

        $codInTransit = (float) CodSettlement::where('status', 'PENDING')
            ->sum('net_amount');

        $capexAssets = (float) CapexAsset::whereNotIn('status', ['DISPOSED'])
            ->sum('current_book_value');

        $cashOnHand = (float) PaymentTransaction::where('transaction_type', 'INCOMING')
            ->whereIn('status', ['VERIFIED', 'RECONCILED'])
            ->sum('amount');

        $totalAssets = $inventoryValue + $accountsReceivable + $codInTransit + $capexAssets + $cashOnHand;

        // Liabilities
        $accountsPayable = (float) SupplierInvoice::whereNotIn('status', ['DRAFT', 'CANCELLED', 'PAID'])
            ->sum('amount_due');

        $commissionsPayable = (float) AgentCommission::whereIn('status', ['PENDING', 'APPROVED'])
            ->sum('commission_amount');

        $totalLiabilities = $accountsPayable + $commissionsPayable;

        // Equity (simplified: assets - liabilities)
        $totalEquity = $totalAssets - $totalLiabilities;

        return [
            'assets' => [
                'inventory' => $inventoryValue,
                'accounts_receivable' => $accountsReceivable,
                'cod_in_transit' => $codInTransit,
                'capex_assets' => $capexAssets,
                'cash_on_hand' => $cashOnHand,
                'total' => $totalAssets,
            ],
            'liabilities' => [
                'accounts_payable' => $accountsPayable,
                'commissions_payable' => $commissionsPayable,
                'total' => $totalLiabilities,
            ],
            'equity' => $totalEquity,
            'total' => $totalAssets,
        ];
    }

    /**
     * Get revenue trends with period-over-period comparison.
     */
    public function getRevenueTrends(Carbon $from, Carbon $to): array
    {
        $currentRevenue = (float) FinancialTransaction::where('type', 'REVENUE')
            ->whereBetween('transaction_date', [$from, $to])
            ->sum('amount');

        $currentRefunds = (float) abs(FinancialTransaction::where('type', 'REFUND')
            ->whereBetween('transaction_date', [$from, $to])
            ->sum('amount'));

        $currentNet = $currentRevenue - $currentRefunds;

        // Previous period (same length, immediately before)
        $periodDays = $from->diffInDays($to) + 1;
        $prevFrom = $from->copy()->subDays($periodDays);
        $prevTo = $from->copy()->subDay()->endOfDay();

        $prevRevenue = (float) FinancialTransaction::where('type', 'REVENUE')
            ->whereBetween('transaction_date', [$prevFrom, $prevTo])
            ->sum('amount');

        $prevRefunds = (float) abs(FinancialTransaction::where('type', 'REFUND')
            ->whereBetween('transaction_date', [$prevFrom, $prevTo])
            ->sum('amount'));

        $prevNet = $prevRevenue - $prevRefunds;

        $revenueGrowth = $prevNet > 0
            ? (float) round((($currentNet - $prevNet) / $prevNet) * 100, 1)
            : 0.0;

        // Daily revenue with refund overlay
        $dailyData = FinancialTransaction::whereBetween('transaction_date', [$from, $to])
            ->selectRaw('transaction_date, type, SUM(amount) as total')
            ->groupBy('transaction_date', 'type')
            ->orderBy('transaction_date')
            ->get()
            ->groupBy(fn ($r) => $r->transaction_date->toDateString());

        $trend = [];
        $period = $from->copy();
        while ($period <= $to) {
            $dateStr = $period->toDateString();
            $dayData = $dailyData[$dateStr] ?? collect();
            $rev = (float) ($dayData->where('type', 'REVENUE')->first()?->total ?? 0);
            $ref = (float) abs($dayData->where('type', 'REFUND')->first()?->total ?? 0);

            $trend[] = [
                'date' => $period->format('M d'),
                'revenue' => $rev,
                'refunds' => $ref,
                'net' => $rev - $ref,
            ];
            $period->addDay();
        }

        // Revenue by source (order source_channel)
        $bySource = Order::where('status', OrderStatus::DELIVERED)
            ->whereBetween('delivered_at', [$from, $to])
            ->select('source_channel', DB::raw('SUM(total_amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('source_channel')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'channel' => $r->source_channel ?? 'Unknown',
                'revenue' => (float) $r->total,
                'orders' => (int) $r->count,
            ])
            ->values()
            ->all();

        return [
            'current' => [
                'gross_revenue' => $currentRevenue,
                'refunds' => $currentRefunds,
                'net_revenue' => $currentNet,
            ],
            'previous' => [
                'gross_revenue' => $prevRevenue,
                'refunds' => $prevRefunds,
                'net_revenue' => $prevNet,
            ],
            'growth_pct' => $revenueGrowth,
            'trend' => $trend,
            'by_source' => $bySource,
        ];
    }

    /**
     * Get the complete enhanced dashboard data.
     */
    public function getDashboardData(Carbon $from, Carbon $to): array
    {
        return [
            'cash_flow' => $this->getCashFlow($from, $to),
            'pl_trend' => $this->getPlTrend(6),
            'balance_sheet' => $this->getBalanceSheet(),
            'revenue_trends' => $this->getRevenueTrends($from, $to),
        ];
    }
}
