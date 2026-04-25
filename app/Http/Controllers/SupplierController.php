<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Procurement\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $suppliers = Supplier::query()
            ->when($request->search, fn ($q, $v) =>
                $q->where(fn ($w) => $w->where('name', 'ILIKE', "%{$v}%")
                                       ->orWhere('code', 'ILIKE', "%{$v}%")))
            ->when($request->status === 'active',   fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Procurement/Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters'   => $request->only(['search', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:200',
            'code'           => 'required|string|max:20|unique:suppliers,code',
            'contact_person' => 'nullable|string|max:120',
            'email'          => 'nullable|email|max:120',
            'phone'          => 'nullable|string|max:30',
            'address'        => 'nullable|string|max:500',
            'payment_terms'  => 'nullable|string|max:60',
            'lead_time_days' => 'nullable|integer|min:0',
            'is_active'      => 'boolean',
        ]);
        Supplier::create($data + ['is_active' => $data['is_active'] ?? true]);
        return redirect()->route('suppliers.index')->with('success', 'Supplier created.');
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:200',
            'code'           => 'required|string|max:20|unique:suppliers,code,' . $supplier->id,
            'contact_person' => 'nullable|string|max:120',
            'email'          => 'nullable|email|max:120',
            'phone'          => 'nullable|string|max:30',
            'address'        => 'nullable|string|max:500',
            'payment_terms'  => 'nullable|string|max:60',
            'lead_time_days' => 'nullable|integer|min:0',
            'is_active'      => 'boolean',
        ]);
        $supplier->update($data);
        return back()->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        return redirect()->route('suppliers.index')->with('success', 'Supplier deleted.');
    }
}
