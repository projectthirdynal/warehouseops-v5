<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Models\CodSettlement;
use App\Domain\Finance\Models\PaymentTransaction;
use App\Domain\Finance\Services\PaymentGatewayService;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentGatewayController extends Controller
{
    public function __construct(
        private PaymentGatewayService $gateway,
    ) {}

    public function index(Request $request)
    {
        $query = PaymentTransaction::with([
            'invoice:id,ref,client_name,amount_due,total_amount',
            'order:id,order_number,total_amount',
            'verifiedBy:id,name',
            'reconciledBy:id,name',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->gateway);
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate(20)->withQueryString();
        $stats = $this->gateway->getStats();
        $settings = $this->gateway->getSettings();

        return Inertia::render('Finance/PaymentGateway', [
            'transactions' => $transactions,
            'stats' => $stats,
            'settings' => $settings,
            'filters' => $request->only(['status', 'gateway']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'gateway' => ['required', 'in:GCASH,BANK_TRANSFER,MAYA,CARD'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'sender_account' => ['nullable', 'string', 'max:255'],
            'sender_phone' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:1000'],
            'transaction_date' => ['nullable', 'date'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'order_id' => ['nullable', 'exists:orders,id'],
            'reference_number' => ['nullable', 'string', 'unique:payment_transactions,reference_number'],
        ]);

        $transaction = $this->gateway->recordTransaction($validated);

        return back()->with('success', "Transaction {$transaction->reference_number} recorded.");
    }

    public function verify(Request $request, PaymentTransaction $transaction)
    {
        $transaction = $this->gateway->verifyTransaction($transaction->id, $request->user()->id);

        return back()->with('success', "Transaction {$transaction->reference_number} verified.");
    }

    public function fail(Request $request, PaymentTransaction $transaction)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $transaction = $this->gateway->failTransaction($transaction->id, $request->user()->id, $validated['reason']);

        return back()->with('success', "Transaction {$transaction->reference_number} marked as failed.");
    }

    public function reconcile(Request $request, PaymentTransaction $transaction)
    {
        $validated = $request->validate([
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'cod_settlement_id' => ['nullable', 'exists:cod_settlements,id'],
        ]);

        if (! empty($validated['invoice_id'])) {
            $invoice = Invoice::findOrFail($validated['invoice_id']);
            $transaction = $this->gateway->reconcileWithInvoice($transaction, $invoice, $request->user()->id);
        } elseif (! empty($validated['cod_settlement_id'])) {
            $settlement = CodSettlement::findOrFail($validated['cod_settlement_id']);
            $transaction = $this->gateway->reconcileWithCodSettlement($transaction, $settlement, $request->user()->id);
        } else {
            $transaction = $this->gateway->autoReconcileTransaction($transaction, $request->user()->id);
        }

        if ($transaction->isReconciled()) {
            return back()->with('success', "Transaction {$transaction->reference_number} reconciled.");
        }

        return back()->with('error', 'No matching invoice or settlement found for auto-reconciliation.');
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'gcash_enabled' => ['nullable', 'boolean'],
            'gcash_number' => ['nullable', 'string', 'max:30'],
            'bank_transfer_enabled' => ['nullable', 'boolean'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'maya_enabled' => ['nullable', 'boolean'],
            'maya_number' => ['nullable', 'string', 'max:30'],
            'card_enabled' => ['nullable', 'boolean'],
            'auto_verify' => ['nullable', 'boolean'],
            'auto_reconcile' => ['nullable', 'boolean'],
        ]);

        $this->gateway->updateSettings($validated);

        return back()->with('success', 'Payment gateway settings updated.');
    }
}
