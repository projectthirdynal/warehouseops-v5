<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\SupplierInvoice;
use App\Models\ThirdParty;
use App\Services\Finance\SupplierInvoiceCalculator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = SupplierInvoice::with('thirdParty:id,name')
            ->orderByDesc('date_invoice');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
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

        return Inertia::render('Finance/SupplierInvoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['status', 'search', 'date_from', 'date_to']),
            'statuses' => ['DRAFT', 'VALIDATED', 'PARTIAL', 'PAID', 'OVERDUE', 'CANCELLED'],
        ]);
    }

    public function show(SupplierInvoice $invoice)
    {
        $invoice->load('thirdParty:id,name');

        return Inertia::render('Finance/SupplierInvoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    public function create(Request $request)
    {
        $thirdParties = ThirdParty::select('id', 'name', 'type')
            ->where('type', 'supplier')
            ->orWhere('type', 'both')
            ->orderBy('name')
            ->get();

        return Inertia::render('Finance/SupplierInvoices/Create', [
            'thirdParties' => $thirdParties,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'third_party_id' => 'nullable|exists:third_parties,id',
            'date_invoice' => 'required|date',
            'date_due' => 'nullable|date',
            'total_amount' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $thirdParty = $validated['third_party_id']
            ? ThirdParty::find($validated['third_party_id'])
            : null;

        $taxRate = (float) ($validated['tax_rate'] ?? 0);
        $derived = SupplierInvoiceCalculator::deriveFromTotal(
            (float) $validated['total_amount'],
            $taxRate,
        );

        $invoice = SupplierInvoice::create([
            'ref' => SupplierInvoice::generateRef(),
            'status' => 'DRAFT',
            'third_party_id' => $thirdParty?->id,
            'supplier_name' => $thirdParty?->name ?? 'Unknown Supplier',
            'supplier_email' => $thirdParty?->email,
            'supplier_phone' => $thirdParty?->phone,
            'supplier_address' => $thirdParty?->full_address,
            'date_invoice' => $validated['date_invoice'],
            'date_due' => $validated['date_due'],
            'total_amount' => $validated['total_amount'],
            'subtotal' => $derived['subtotal'],
            'tax_rate' => $taxRate,
            'tax_amount' => $derived['tax_amount'],
            'amount_due' => $validated['total_amount'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.supplier-invoices.show', $invoice->id)
            ->with('success', 'Supplier invoice created.');
    }

    public function validateInvoice(Request $request, SupplierInvoice $invoice)
    {
        if ($invoice->status !== 'DRAFT') {
            return back()->with('error', 'Only drafts can be validated.');
        }

        $invoice->update([
            'status' => 'VALIDATED',
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Validated.');
    }

    public function cancel(Request $request, SupplierInvoice $invoice)
    {
        if (in_array($invoice->status, ['PAID', 'PARTIAL'])) {
            return back()->with('error', 'Cannot cancel a paid or partially paid supplier invoice.');
        }

        $invoice->update([
            'status' => 'CANCELLED',
            'cancel_reason' => $request->input('reason'),
            'cancelled_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.supplier-invoices.index')
            ->with('success', 'Cancelled.');
    }
}
