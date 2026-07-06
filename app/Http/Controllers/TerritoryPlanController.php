<?php

namespace App\Http\Controllers;

use App\Models\TerritoryPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TerritoryPlanController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $email = $this->accountEmail($request);
        $plan = TerritoryPlan::query()->where('account_email', $email)->first();

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
            ['account_email' => $email],
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
}
