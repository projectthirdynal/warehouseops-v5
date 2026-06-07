<?php

namespace App\Http\Controllers;

use App\Services\TelesalesLeadImportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TelesalesLeadImportController extends Controller
{
    public function __construct(
        private TelesalesLeadImportService $importService
    ) {
        $this->middleware(function ($request, $next) {
            if (!in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor'])) {
                abort(403);
            }
            return $next($request);
        });
    }

    public function create()
    {
        return Inertia::render('Telesales/Import');
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $result = $this->importService->import(
            $request->file('file'),
            auth()->id()
        );

        return redirect()->route('telesales.import.create')
            ->with('success', "Telesales import complete: {$result['created']} created, {$result['updated']} updated, {$result['skipped']} skipped")
            ->with('importErrors', $result['errors']);
    }
}
