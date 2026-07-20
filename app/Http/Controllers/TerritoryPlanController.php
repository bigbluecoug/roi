<?php

namespace App\Http\Controllers;

use App\Models\TerritoryPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TerritoryPlanController extends Controller
{
    private const SHARED_WORKSPACE_KEY = 'team:derivita-territory-planner';
    private const SHARED_WORKSPACE_NAME = 'Derivita Team Workspace';

    public function show(Request $request): JsonResponse
    {
        $email = $this->accountEmail($request);
        $plan = TerritoryPlan::query()->where('account_email', $this->workspaceKey())->first();

        return response()->json($this->payload($email, $plan));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'planningState' => ['nullable', 'array'],
            'focusedStateCodes' => ['nullable', 'array'],
            'focusedStateCodes.*' => ['string', 'max:40'],
            'plannerSettings' => ['nullable', 'array'],
        ]);

        $email = $this->accountEmail($request);
        $plan = TerritoryPlan::query()->updateOrCreate(
            ['account_email' => $this->workspaceKey()],
            [
                'planning_state' => $validated['planningState'] ?? [],
                'focused_state_codes' => array_values(array_unique($validated['focusedStateCodes'] ?? [])),
                'planner_settings' => $validated['plannerSettings'] ?? [],
            ],
        );

        return response()->json($this->payload($email, $plan));
    }

    private function payload(string $email, ?TerritoryPlan $plan): array
    {
        return [
            'accountEmail' => $email,
            'workspaceKey' => $this->workspaceKey(),
            'workspaceName' => $this->workspaceName(),
            'planningState' => $plan?->planning_state ?? [],
            'focusedStateCodes' => $plan?->focused_state_codes ?? [],
            'plannerSettings' => $plan?->planner_settings ?? [],
            'csrfToken' => csrf_token(),
            'savedAt' => $plan?->updated_at?->toIso8601String(),
            'source' => $plan ? 'account' : 'empty',
        ];
    }

    private function accountEmail(Request $request): string
    {
        return Str::of((string) $request->user()->email)->trim()->lower()->toString();
    }

    private function workspaceKey(): string
    {
        return (string) config('services.territory_planner.workspace_key', self::SHARED_WORKSPACE_KEY);
    }

    private function workspaceName(): string
    {
        return (string) config('services.territory_planner.workspace_name', self::SHARED_WORKSPACE_NAME);
    }
}
