<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Finance\Models\CodSettlement;
use Modules\Finance\Models\PaymentTransaction;

class PaymentGatewayService
{
    private const SETTINGS_KEYS = [
        'pg_gcash_enabled',
        'pg_gcash_number',
        'pg_bank_transfer_enabled',
        'pg_bank_name',
        'pg_bank_account_name',
        'pg_bank_account_number',
        'pg_maya_enabled',
        'pg_maya_number',
        'pg_card_enabled',
        'pg_auto_verify',
        'pg_auto_reconcile',
    ];

    /**
     * Record a new incoming payment transaction from a gateway.
     */
    public function recordTransaction(array $data): PaymentTransaction
    {
        $data['reference_number'] = $data['reference_number'] ?? $this->generateReference($data['gateway']);
        $data['status'] = PaymentTransaction::STATUS_PENDING;
        $data['transaction_type'] = $data['transaction_type'] ?? PaymentTransaction::TYPE_INCOMING;
        $data['transaction_date'] = $data['transaction_date'] ?? now();

        $transaction = PaymentTransaction::create($data);

        Log::info('Payment transaction recorded', [
            'reference' => $transaction->reference_number,
            'gateway' => $transaction->gateway,
            'amount' => (float) $transaction->amount,
        ]);

        // Auto-verify if enabled
        if (SiteSetting::get('pg_auto_verify', '0') === '1') {
            $this->verifyTransaction($transaction->id, null);
        }

        return $transaction;
    }

    /**
     * Verify a pending transaction — confirms it was received from the gateway.
     */
    public function verifyTransaction(int $transactionId, ?int $userId): PaymentTransaction
    {
        $transaction = PaymentTransaction::findOrFail($transactionId);

        if (! $transaction->canBeVerified()) {
            throw new \DomainException('Transaction must be in PENDING status to verify.');
        }

        $transaction->update([
            'status' => PaymentTransaction::STATUS_VERIFIED,
            'verified_at' => now(),
            'verified_by' => $userId,
        ]);

        Log::info('Payment transaction verified', [
            'reference' => $transaction->reference_number,
            'verified_by' => $userId,
        ]);

        // Auto-reconcile if enabled
        if (SiteSetting::get('pg_auto_reconcile', '0') === '1') {
            $this->autoReconcileTransaction($transaction, $userId);
        }

        return $transaction->fresh();
    }

    /**
     * Mark a verified transaction as failed.
     */
    public function failTransaction(int $transactionId, ?int $userId, string $reason): PaymentTransaction
    {
        $transaction = PaymentTransaction::findOrFail($transactionId);

        if ($transaction->isReconciled()) {
            throw new \DomainException('Cannot fail a reconciled transaction.');
        }

        $transaction->update([
            'status' => PaymentTransaction::STATUS_FAILED,
            'notes' => $reason,
        ]);

        Log::info('Payment transaction failed', [
            'reference' => $transaction->reference_number,
            'reason' => $reason,
        ]);

        return $transaction->fresh();
    }

    /**
     * Auto-reconcile a verified transaction against matching invoices/orders.
     */
    public function autoReconcileTransaction(PaymentTransaction $transaction, ?int $userId): PaymentTransaction
    {
        if (! $transaction->canBeReconciled()) {
            return $transaction;
        }

        $amount = (float) $transaction->amount;

        // Try to match against invoices with outstanding balances
        if ($transaction->invoice_id) {
            $invoice = $transaction->invoice;
        } else {
            $invoice = $this->findMatchingInvoice($amount, $transaction);
        }

        if ($invoice && (float) $invoice->amount_due > 0) {
            return $this->reconcileWithInvoice($transaction, $invoice, $userId);
        }

        // Try to match against COD settlements
        if ($transaction->cod_settlement_id) {
            $settlement = $transaction->codSettlement;
        } else {
            $settlement = $this->findMatchingCodSettlement($amount, $transaction);
        }

        if ($settlement && $settlement->isReconcilable()) {
            return $this->reconcileWithCodSettlement($transaction, $settlement, $userId);
        }

        // No match found — leave as verified
        Log::info('Auto-reconcile: no matching invoice/settlement found', [
            'reference' => $transaction->reference_number,
            'amount' => $amount,
        ]);

        return $transaction;
    }

    /**
     * Manually reconcile a transaction with an invoice.
     */
    public function reconcileWithInvoice(PaymentTransaction $transaction, Invoice $invoice, ?int $userId): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $invoice, $userId) {
            $amount = min((float) $transaction->amount, (float) $invoice->amount_due);

            // Create invoice payment record
            InvoicePayment::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'payment_date' => $transaction->transaction_date?->toDateString() ?? now()->toDateString(),
                'payment_method' => strtolower($transaction->gateway),
                'reference_number' => $transaction->reference_number,
                'notes' => "Auto-reconciled from gateway transaction {$transaction->reference_number}",
                'recorded_by' => $userId,
            ]);

            // Update invoice totals
            $invoice->amount_paid = $invoice->payments()->sum('amount');
            $invoice->amount_due = $invoice->total_amount - $invoice->amount_paid;
            $invoice->status = $invoice->amount_due <= 0.01 ? 'PAID' : ($invoice->amount_paid > 0 ? 'PARTIAL' : $invoice->status);
            $invoice->save();

            // Create financial transaction
            DB::table('financial_transactions')->insert([
                'type' => 'REVENUE',
                'amount' => $amount,
                'reference_type' => PaymentTransaction::class,
                'reference_id' => $transaction->id,
                'description' => "Payment received via {$transaction->gateway} — Ref: {$transaction->reference_number}",
                'recorded_by' => $userId,
                'transaction_date' => $transaction->transaction_date?->toDateString() ?? now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $transaction->update([
                'status' => PaymentTransaction::STATUS_RECONCILED,
                'invoice_id' => $invoice->id,
                'reconciled_at' => now(),
                'reconciled_by' => $userId,
                'reconciliation_ref' => $invoice->ref,
            ]);

            Log::info('Payment transaction reconciled with invoice', [
                'reference' => $transaction->reference_number,
                'invoice' => $invoice->ref,
                'amount' => $amount,
            ]);

            return $transaction->fresh();
        });
    }

    /**
     * Manually reconcile a transaction with a COD settlement.
     */
    public function reconcileWithCodSettlement(PaymentTransaction $transaction, CodSettlement $settlement, ?int $userId): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $settlement, $userId) {
            $transaction->update([
                'status' => PaymentTransaction::STATUS_RECONCILED,
                'cod_settlement_id' => $settlement->id,
                'reconciled_at' => now(),
                'reconciled_by' => $userId,
                'reconciliation_ref' => "COD-{$settlement->id}",
            ]);

            Log::info('Payment transaction reconciled with COD settlement', [
                'reference' => $transaction->reference_number,
                'settlement' => $settlement->id,
            ]);

            return $transaction->fresh();
        });
    }

    /**
     * Get gateway settings.
     */
    public function getSettings(): array
    {
        return [
            'gcash_enabled' => SiteSetting::get('pg_gcash_enabled', '0') === '1',
            'gcash_number' => SiteSetting::get('pg_gcash_number', ''),
            'bank_transfer_enabled' => SiteSetting::get('pg_bank_transfer_enabled', '0') === '1',
            'bank_name' => SiteSetting::get('pg_bank_name', ''),
            'bank_account_name' => SiteSetting::get('pg_bank_account_name', ''),
            'bank_account_number' => SiteSetting::get('pg_bank_account_number', ''),
            'maya_enabled' => SiteSetting::get('pg_maya_enabled', '0') === '1',
            'maya_number' => SiteSetting::get('pg_maya_number', ''),
            'card_enabled' => SiteSetting::get('pg_card_enabled', '0') === '1',
            'auto_verify' => SiteSetting::get('pg_auto_verify', '0') === '1',
            'auto_reconcile' => SiteSetting::get('pg_auto_reconcile', '0') === '1',
        ];
    }

    /**
     * Update gateway settings.
     */
    public function updateSettings(array $settings): void
    {
        $keyMap = [
            'gcash_enabled' => 'pg_gcash_enabled',
            'gcash_number' => 'pg_gcash_number',
            'bank_transfer_enabled' => 'pg_bank_transfer_enabled',
            'bank_name' => 'pg_bank_name',
            'bank_account_name' => 'pg_bank_account_name',
            'bank_account_number' => 'pg_bank_account_number',
            'maya_enabled' => 'pg_maya_enabled',
            'maya_number' => 'pg_maya_number',
            'card_enabled' => 'pg_card_enabled',
            'auto_verify' => 'pg_auto_verify',
            'auto_reconcile' => 'pg_auto_reconcile',
        ];

        foreach ($keyMap as $field => $key) {
            if (array_key_exists($field, $settings)) {
                $value = is_bool($settings[$field]) ? ($settings[$field] ? '1' : '0') : (string) $settings[$field];
                SiteSetting::set($key, $value);
            }
        }
    }

    /**
     * Get dashboard stats.
     */
    public function getStats(): array
    {
        $totalReceived = (float) PaymentTransaction::incoming()
            ->whereIn('status', [PaymentTransaction::STATUS_VERIFIED, PaymentTransaction::STATUS_RECONCILED])
            ->sum('amount');

        $pendingCount = PaymentTransaction::pending()->count();
        $verifiedCount = PaymentTransaction::verified()->count();
        $reconciledCount = PaymentTransaction::reconciled()->count();

        $byGateway = [];
        foreach ([PaymentTransaction::GATEWAY_GCASH, PaymentTransaction::GATEWAY_BANK_TRANSFER, PaymentTransaction::GATEWAY_MAYA, PaymentTransaction::GATEWAY_CARD] as $gw) {
            $byGateway[$gw] = [
                'count' => PaymentTransaction::byGateway($gw)->count(),
                'total' => (float) PaymentTransaction::byGateway($gw)->whereIn('status', [
                    PaymentTransaction::STATUS_VERIFIED, PaymentTransaction::STATUS_RECONCILED,
                ])->sum('amount'),
            ];
        }

        return [
            'total_received' => $totalReceived,
            'pending_count' => $pendingCount,
            'verified_count' => $verifiedCount,
            'reconciled_count' => $reconciledCount,
            'by_gateway' => $byGateway,
        ];
    }

    /**
     * Find invoices matching the transaction amount.
     */
    private function findMatchingInvoice(float $amount, PaymentTransaction $transaction): ?Invoice
    {
        $query = Invoice::where('amount_due', '>', 0)
            ->whereNotIn('status', ['PAID', 'CANCELLED'])
            ->whereRaw('ABS(amount_due - ?) < 0.01', [$amount]);

        // Try to match by sender name/client name
        if ($transaction->sender_name) {
            $query->orWhereRaw('LOWER(client_name) LIKE ?', ['%'.strtolower($transaction->sender_name).'%']);
        }

        return $query->orderBy('date_invoice', 'desc')->first();
    }

    /**
     * Find COD settlements matching the transaction amount.
     */
    private function findMatchingCodSettlement(float $amount, PaymentTransaction $transaction): ?CodSettlement
    {
        return CodSettlement::where('status', 'RECEIVED')
            ->whereRaw('ABS(net_amount - ?) < 0.01', [$amount])
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Generate a unique reference number.
     */
    private function generateReference(string $gateway): string
    {
        $prefix = match ($gateway) {
            PaymentTransaction::GATEWAY_GCASH => 'GCASH',
            PaymentTransaction::GATEWAY_BANK_TRANSFER => 'BT',
            PaymentTransaction::GATEWAY_MAYA => 'MAYA',
            PaymentTransaction::GATEWAY_CARD => 'CARD',
            default => 'PAY',
        };

        return $prefix.'-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -6));
    }
}
