<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ThirdParty;
use App\Models\Contact;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ThirdPartyController extends Controller
{
    public function index(Request $request): Response
    {
        $query = ThirdParty::query()
            ->withCount('contacts')
            ->with(['addresses' => fn ($q) => $q->where('is_default', true)->limit(1)]);

        if ($request->filled('q')) {
            $query->search($request->q);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('risk')) {
            $query->where('risk_level', $request->risk);
        }

        $sortField = $request->input('sort', 'created_at');
        $sortDir   = $request->input('dir', 'desc');
        $allowed   = ['name', 'type', 'status', 'total_orders', 'total_revenue', 'created_at'];
        if (in_array($sortField, $allowed)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $thirdParties = $query->paginate(25)->withQueryString();

        return Inertia::render('Crm/Contacts/Index', [
            'thirdParties' => $thirdParties,
            'filters' => $request->only(['q', 'type', 'status', 'risk', 'sort', 'dir']),
            'stats' => [
                'total'     => ThirdParty::count(),
                'customers' => ThirdParty::customers()->count(),
                'suppliers' => ThirdParty::suppliers()->count(),
                'prospects' => ThirdParty::where('type', 'prospect')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Crm/Contacts/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'alias'         => 'nullable|string|max:255',
            'type'          => 'required|in:customer,supplier,prospect,partner,both',
            'email'         => 'nullable|email|max:255',
            'phone'         => 'nullable|string|max:50',
            'phone_alt'     => 'nullable|string|max:50',
            'website'       => 'nullable|url|max:255',
            'tax_id'        => 'nullable|string|max:50',
            'industry'      => 'nullable|string|max:100',
            'currency'      => 'nullable|string|max:3',
            'payment_terms' => 'nullable|string|max:50',
            'credit_limit'  => 'nullable|numeric|min:0',
            'address_line1' => 'nullable|string|max:255',
            'city'          => 'nullable|string|max:100',
            'state_province'=> 'nullable|string|max:100',
            'postal_code'   => 'nullable|string|max:20',
            'country'       => 'nullable|string|max:100',
            'status'        => 'nullable|in:active,inactive,blacklisted,prospect',
            'notes'         => 'nullable|string',
            'tags'          => 'nullable|array',
            'tags.*'        => 'string|max:50',
            // Optional primary contact
            'contact.first_name' => 'nullable|string|max:100',
            'contact.last_name'  => 'nullable|string|max:100',
            'contact.email'      => 'nullable|email|max:255',
            'contact.phone'      => 'nullable|string|max:50',
            'contact.position'   => 'nullable|string|max:100',
        ]);

        DB::transaction(function () use ($validated, $request) {
            $tp = ThirdParty::create([
                ...$validated,
                'ref'        => $this->generateRef(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'status'     => $validated['status'] ?? 'active',
                'currency'   => $validated['currency'] ?? 'PHP',
                'country'    => $validated['country'] ?? 'Philippines',
            ]);

            if (!empty($validated['contact']['first_name'])) {
                Contact::create([
                    'third_party_id' => $tp->id,
                    'first_name'     => $validated['contact']['first_name'],
                    'last_name'      => $validated['contact']['last_name'] ?? null,
                    'email'          => $validated['contact']['email'] ?? null,
                    'phone'          => $validated['contact']['phone'] ?? null,
                    'position'       => $validated['contact']['position'] ?? null,
                    'is_primary'     => true,
                ]);
            }

            if (!empty($validated['address_line1'])) {
                Address::create([
                    'third_party_id' => $tp->id,
                    'type'           => 'billing',
                    'is_default'     => true,
                    'address_line1'  => $validated['address_line1'],
                    'city'           => $validated['city'] ?? '',
                    'state_province' => $validated['state_province'] ?? null,
                    'postal_code'    => $validated['postal_code'] ?? null,
                    'country'        => $validated['country'] ?? 'Philippines',
                ]);
            }
        });

        return redirect()->route('crm.contacts.index')
            ->with('success', 'Contact created successfully.');
    }

    public function show(ThirdParty $thirdParty): Response
    {
        $thirdParty->load([
            'contacts',
            'addresses',
            'customer',
        ]);

        $recentOrders = DB::table('orders')
            ->where('customer_id', $thirdParty->customer_id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return Inertia::render('Crm/Contacts/Show', [
            'thirdParty'   => $thirdParty,
            'recentOrders' => $recentOrders,
        ]);
    }

    public function edit(ThirdParty $thirdParty): Response
    {
        $thirdParty->load(['contacts', 'addresses']);

        return Inertia::render('Crm/Contacts/Edit', [
            'thirdParty' => $thirdParty,
        ]);
    }

    public function update(Request $request, ThirdParty $thirdParty): RedirectResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'alias'         => 'nullable|string|max:255',
            'type'          => 'required|in:customer,supplier,prospect,partner,both',
            'email'         => 'nullable|email|max:255',
            'phone'         => 'nullable|string|max:50',
            'phone_alt'     => 'nullable|string|max:50',
            'website'       => 'nullable|url|max:255',
            'tax_id'        => 'nullable|string|max:50',
            'industry'      => 'nullable|string|max:100',
            'currency'      => 'nullable|string|max:3',
            'payment_terms' => 'nullable|string|max:50',
            'credit_limit'  => 'nullable|numeric|min:0',
            'address_line1' => 'nullable|string|max:255',
            'city'          => 'nullable|string|max:100',
            'state_province'=> 'nullable|string|max:100',
            'postal_code'   => 'nullable|string|max:20',
            'country'       => 'nullable|string|max:100',
            'status'        => 'nullable|in:active,inactive,blacklisted,prospect',
            'notes'         => 'nullable|string',
            'tags'          => 'nullable|array',
            'tags.*'        => 'string|max:50',
        ]);

        $thirdParty->update([
            ...$validated,
            'updated_by' => Auth::id(),
        ]);

        return redirect()->route('crm.contacts.show', $thirdParty)
            ->with('success', 'Contact updated successfully.');
    }

    public function destroy(ThirdParty $thirdParty): RedirectResponse
    {
        $thirdParty->delete();

        return redirect()->route('crm.contacts.index')
            ->with('success', 'Contact deleted.');
    }

    public function storeContact(Request $request, ThirdParty $thirdParty): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'nullable|string|max:100',
            'title'      => 'nullable|string|max:20',
            'position'   => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'email'      => 'nullable|email|max:255',
            'phone'      => 'nullable|string|max:50',
            'phone_alt'  => 'nullable|string|max:50',
            'is_primary' => 'boolean',
            'notes'      => 'nullable|string',
        ]);

        if (!empty($validated['is_primary'])) {
            $thirdParty->contacts()->update(['is_primary' => false]);
        }

        $thirdParty->contacts()->create($validated);

        return back()->with('success', 'Contact person added.');
    }

    public function storeAddress(Request $request, ThirdParty $thirdParty): RedirectResponse
    {
        $validated = $request->validate([
            'type'          => 'required|in:billing,shipping,branch,other',
            'label'         => 'nullable|string|max:100',
            'is_default'    => 'boolean',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'barangay'      => 'nullable|string|max:100',
            'city'          => 'required|string|max:100',
            'state_province'=> 'nullable|string|max:100',
            'postal_code'   => 'nullable|string|max:20',
            'country'       => 'nullable|string|max:100',
            'contact_name'  => 'nullable|string|max:100',
            'contact_phone' => 'nullable|string|max:50',
        ]);

        if (!empty($validated['is_default'])) {
            $thirdParty->addresses()
                ->where('type', $validated['type'])
                ->update(['is_default' => false]);
        }

        $thirdParty->addresses()->create([
            ...$validated,
            'country' => $validated['country'] ?? 'Philippines',
        ]);

        return back()->with('success', 'Address added.');
    }

    private function generateRef(): string
    {
        $year  = now()->format('Y');
        $count = ThirdParty::whereYear('created_at', $year)->count() + 1;
        return sprintf('TP-%s-%05d', $year, $count);
    }
}
