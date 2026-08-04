<?php

namespace App\Http\Controllers;

use App\Models\AgentBurnoutPrediction;
use App\Models\User;
use App\Services\BurnoutPredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BurnoutPredictionController extends Controller
{
    public function __construct(
        private BurnoutPredictionService $predictionService
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()?->role, ['superadmin', 'admin', 'supervisor'])) {
                abort(403, 'Supervisors only');
            }

            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        $riskLevel = $request->input('risk_level');
        $search = $request->input('search');

        $data = $this->predictionService->getAgentList($riskLevel, $search);

        return Inertia::render('Agents/Burnout', [
            'agents' => $data['agents'],
            'summary' => $data['summary'],
            'filters' => [
                'risk_level' => $riskLevel,
                'search' => $search,
            ],
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $data = $this->predictionService->getAgentList(
            $request->input('risk_level'),
            $request->input('search')
        );

        return response()->json($data);
    }

    public function agent(User $user): JsonResponse
    {
        $prediction = AgentBurnoutPrediction::where('agent_id', $user->id)
            ->latest('calculated_at')
            ->first();

        if (! $prediction) {
            $predictionData = $this->predictionService->predictForAgent($user);
        } else {
            $predictionData = [
                'risk_score' => $prediction->risk_score,
                'risk_level' => $prediction->risk_level,
                'features' => $prediction->features,
                'recommendation' => $prediction->recommendation,
                'model_version' => $prediction->model_version,
                'calculated_at' => $prediction->calculated_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'agent_id' => $user->id,
            'name' => $user->name,
            ...$predictionData,
        ]);
    }

    public function recalculate(): JsonResponse
    {
        $result = $this->predictionService->recalculateAll();

        return response()->json([
            'success' => true,
            'processed' => $result['processed'],
            'high_risk' => $result['high_risk'],
            'critical' => $result['critical'],
        ]);
    }
}
