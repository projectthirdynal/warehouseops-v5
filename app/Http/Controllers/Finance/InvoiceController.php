<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePayment;
use App\Models\Order;
use App\Models\Product;
use App\Models\ThirdParty;
use App\Services\Finance\InvoiceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with('thirdParty:id,name,type')
            ->orderByDesc('date_invoice');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $query->search($request->search);
        }
        if ($request->filled('date_from')) {
            $query->where('date_invoice', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date_invoice', '<=', $request->date_to);
        }

        $invoices = $query->paginate(25)->withQueryString();

        return Inertia::render('Finance/Invoices/Index', [
            'invoices' => $invoices,
            'filters'  => $request->only(['status', 'type', 'search', 'date_from', 'date_to']),
            'statuses' => ['DRAFT', 'VALIDATED', 'SENT', 'PARTIAL', 'PAID', 'OVERDUE', 'CANCELLED'],
            'types'    => ['standard', 'credit_note', 'deposit', 'proforma'],
        ]);
    }

    public function create(Request $request)
    {
        $thirdParties = ThirdParty::select('id', 'name', 'type')->orderBy('name')->get();
        $products     = Product::select('id', 'name', 'sku', 'unit_price')->orderBy('name')->get();

        return Inertia::render('Finance/Invoices/Create', [
            'thirdParties' => $thirdParties,
            'products'     => $products,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'third_party_id' => 'nullable|exists:third_parties,id',
            'type'            => 'in:standard,credit_note,deposit,proforma',
            'date_invoice'    => 'required|date',
            'date_due'        => 'nullable|date',
            'payment_terms'   => 'nullable|string',
            'notes'           => 'nullable|string',
            'lines'           => 'required|array|min:1',
            'lines.*.description'  => 'required|string|max:500',
            'lines.*.qty'          => 'required|numeric|min:0.001',
            'lines.*.unit_price'   => 'required|numeric|min:0',
            'lines.*.tax_rate'     => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $thirdParty = $validated['third_party_id']
                ? ThirdParty::find($validated['third_party_id'])
                : null;

            $invoice = Invoice::create([
                'ref'           => Invoice::generateRef(),
                'type'          => $validated['type'] ?? 'standard',
                'status'        => 'DRAFT',
                'third_party_id'=> $thirdParty?->id,
                'client_name'   => $thirdParty?->name ?? 'Walk-in Customer',
                'client_email'  => $thirdParty?->email,
                'client_phone'  => $thirdParty?->phone,
                'client_address'=> $thirdParty?->full_address,
                'date_invoice'  => $validated['date_invoice'],
                'date_due'      => $validated['date_due'],
                'payment_terms' => $validated['payment_terms'],
                'currency'      => 'PHP',
                'notes'         => $validated['notes'] ?? null,
                'created_by'    => $request->user()->id,
            ]);

            $this->storeLines($invoice, $request->input('lines'));
            $this->recalculate($invoice);

            return redirect()->route('finance.invoices.show', $invoice->id)
                ->with('success', 'Invoice created successfully.');
        });
    }

    public function show(Invoice $invoice)
    {
        $invoice->load([
            'thirdParty',
            'lines.product:id,name,sku',
            'payments.recordedBy:id,name',
            'createdBy:id,name',
            'order:id,order_number',
        ]);

        $thirdParties = ThirdParty::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Finance/Invoices/Show', [
            'invoice'      => $invoice,
            'thirdParties' => $thirdParties,
        ]);
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->status === 'PAID' || $invoice->status === 'CANCELLED') {
            return back()->with('error', 'Cannot edit a paid or cancelled invoice.');
        }

        $validated = $request->validate([
            'client_name'   => 'required|string|max:255',
            'client_email'  => 'nullable|email|max:255',
            'client_phone'  => 'nullable|string|max:50',
            'client_address'=> 'nullable|string',
            'date_invoice'  => 'required|date',
            'date_due'      => 'nullable|date',
            'payment_terms' => 'nullable|string',
            'notes'         => 'nullable|string',
            'tax_rate'      => 'nullable|numeric|min:0|max:100',
        ]);

        $invoice->update($validated + ['updated_by' => $request->user()->id]);
        $this->recalculate($invoice);

        return redirect()->route('finance.invoices.show', $invoice->id)
            ->with('success', 'Invoice updated.');
    }

    public function validateInvoice(Request $request, Invoice $invoice)
    {
        if ($invoice->status !== 'DRAFT') {
            return back()->with('error', 'Only draft invoices can be validated.');
        }

        $invoice->update([
            'status' => 'VALIDATED',
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Invoice validated.');
    }

    public function send(Request $request, Invoice $invoice)
    {
        if ($invoice->status !== 'VALIDATED') {
            return back()->with('error', 'Invoice must be validated before sending.');
        }

        $invoice->update([
            'status' => 'SENT',
            'date_sent' => now(),
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Invoice marked as sent.');
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        if (in_array($invoice->status, ['PAID', 'PARTIAL'])) {
            return back()->with('error', 'Cannot cancel a paid or partially paid invoice.');
        }

        $invoice->update([
            'status'        => 'CANCELLED',
            'cancel_reason' => $request->input('reason'),
            'cancelled_at'  => now(),
            'updated_by'    => $request->user()->id,
        ]);

        return redirect()->route('finance.invoices.index')
            ->with('success', 'Invoice cancelled.');
    }

    // ─── Line management ───

    public function storeLine(Request $request, Invoice $invoice)
    {
        if (in_array($invoice->status, ['PAID', 'CANCELLED'])) {
            return back()->with('error', 'Cannot edit a locked invoice.');
        }

        $validated = $request->validate([
            'description'  => 'required|string|max:500',
            'qty'          => 'required|numeric|min:0.001',
            'unit_price'   => 'required|numeric|min:0',
            'tax_rate'     => 'nullable|numeric|min:0|max:100',
            'discount_pct' => 'nullable|numeric|min:0|max:100',
            'position'     => 'nullable|integer|min:0',
        ]);

        $validated['position'] = $validated['position'] ?? ($invoice->lines()->max('position') + 1);

        InvoiceCalculator::createLine($invoice, $validated);

        InvoiceCalculator::recalculateInvoice($invoice);

        return back()->with('success', 'Line added.');
    }

    public function destroyLine(Invoice $invoice, InvoiceLine $line)
    {
        if (in_array($invoice->status, ['PAID', 'CANCELLED'])) {
            return back()->with('error', 'Invoice is locked.');
        }

        $line->delete();
        InvoiceCalculator::recalculateInvoice($invoice);

        return back()->with('success', 'Line removed.');
    }

    // ─── Payment ───

    public function storePayment(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'amount'          => 'required|numeric|min:0.01|max:' . $invoice->amount_due,
            'payment_date'    => 'required|date',
            'payment_method'  => 'required|string',
            'reference_number'=> 'nullable|string',
            'notes'           => 'nullable|string',
        ]);

        InvoicePayment::create($validated + [
            'invoice_id'   => $invoice->id,
            'recorded_by'  => $request->user()->id,
        ]);

        $invoice->amount_paid = $invoice->payments()->sum('amount');
        $invoice->amount_due  = $invoice->total_amount - $invoice->amount_paid;
        $invoice->status      = $invoice->amount_due <= 0.01 ? 'PAID' : ($invoice->amount_paid > 0 ? 'PARTIAL' : $invoice->status);
        $invoice->save();

        return back()->with('success', 'Payment recorded.');
    }

    // ─── Helpers ───

    protected function storeLines(Invoice $invoice, array $lines): void
    {
        foreach ($lines as $i => $line) {
            $data = [
                'position'    => $i,
                'description' => $line['description'],
                'qty'         => $line['qty'],
                'unit_price'  => $line['unit_price'],
                'tax_rate'    => $line['tax_rate'] ?? 0,
                'discount_pct'=> $line['discount_pct'] ?? 0,
            ];
            if (! empty($line['product_id']))  $data['product_id']  = $line['product_id'];
            if (! empty($line['product_ref'])) $data['product_ref'] = $line['product_ref'];
            if (! empty($line['unit']))        $data['unit']        = $line['unit'];

            InvoiceCalculator::createLine($invoice, $data);
        }
    }

    protected function recalculate(Invoice $invoice): void
    {
        InvoiceCalculator::recalculateInvoice($invoice);
    }
}
