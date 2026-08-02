<?php

namespace App\Http\Controllers;

use App\Services\LeadImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeadImportController extends Controller
{
    public function __construct(
        private LeadImportService $importService
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['supervisor', 'admin'])) {
                abort(403);
            }

            return $next($request);
        });
    }

    public function create()
    {
        return Inertia::render('LeadPool/Import');
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

        return redirect()->route('lead-pool.index')
            ->with('success', "Import complete: {$result['created']} created, {$result['updated']} updated, {$result['skipped']} skipped");
    }
}
