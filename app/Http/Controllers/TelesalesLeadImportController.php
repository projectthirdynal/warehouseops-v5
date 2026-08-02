<?php

namespace App\Http\Controllers;

use App\Services\TelesalesLeadImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TelesalesLeadImportController extends Controller
{
    public function __construct(
        private TelesalesLeadImportService $importService
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor'])) {
                abort(403);
            }

            return $next($request);
        });
    }

    public function create()
    {
        return Inertia::render('Telesales/Import');
    }

    /**
     * Preview import: validate file, detect duplicates, return results without writing.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $result = $this->importService->preview($request->file('file'));

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
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
