<?php

namespace Tests\Feature;

use App\Models\TerritoryPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerritoryPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_account_plan_returns_empty_payload(): void
    {
        $user = User::factory()->create(['email' => 'AE@Derivita.com']);

        $this->actingAs($user)->getJson('/api/territory-plan')
            ->assertOk()
            ->assertJsonPath('accountEmail', 'ae@derivita.com')
            ->assertJsonPath('workspaceKey', 'team:derivita-territory-planner')
            ->assertJsonPath('workspaceName', 'Derivita Team Workspace')
            ->assertJsonPath('planningState', [])
            ->assertJsonPath('focusedStateCodes', [])
            ->assertJsonPath('plannerSettings', [])
            ->assertJsonPath('source', 'empty');
    }

    public function test_account_plan_can_be_saved_and_loaded_from_shared_workspace(): void
    {
        $user = User::factory()->create(['email' => 'AE@Derivita.com']);
        $teammate = User::factory()->create(['email' => 'teammate@derivita.com']);

        $payload = [
            'planningState' => [
                'CO:lea-0803450:douglas:castle-rock' => [
                    'tier' => 'tier1',
                    'note' => 'Strong district target.',
                    'penetrationPct' => 35,
                ],
            ],
            'focusedStateCodes' => ['CO', 'TX', 'CO'],
            'plannerSettings' => [
                'CO' => [
                    'rate' => 15,
                    'defaultPenetration' => 30,
                ],
            ],
        ];

        $this->actingAs($user)->putJson('/api/territory-plan', $payload)
            ->assertOk()
            ->assertJsonPath('accountEmail', 'ae@derivita.com')
            ->assertJsonPath('workspaceName', 'Derivita Team Workspace')
            ->assertJsonPath('planningState.CO:lea-0803450:douglas:castle-rock.tier', 'tier1')
            ->assertJsonPath('focusedStateCodes', ['CO', 'TX'])
            ->assertJsonPath('plannerSettings.CO.rate', 15)
            ->assertJsonPath('source', 'account');

        $this->assertDatabaseHas('territory_plans', [
            'account_email' => 'team:derivita-territory-planner',
        ]);

        $this->actingAs($teammate)->getJson('/api/territory-plan')
            ->assertOk()
            ->assertJsonPath('accountEmail', 'teammate@derivita.com')
            ->assertJsonPath('planningState.CO:lea-0803450:douglas:castle-rock.note', 'Strong district target.')
            ->assertJsonPath('plannerSettings.CO.defaultPenetration', 30);
    }

    public function test_saving_again_replaces_the_account_plan(): void
    {
        $user = User::factory()->create(['email' => 'ae@derivita.com']);

        TerritoryPlan::query()->create([
            'account_email' => 'team:derivita-territory-planner',
            'planning_state' => [
                'old' => ['tier' => 'tier3'],
            ],
            'focused_state_codes' => ['CO'],
            'planner_settings' => [],
        ]);

        $this->actingAs($user)->postJson('/api/territory-plan', [
            'planningState' => [
                'new' => ['tier' => 'tier2'],
            ],
            'focusedStateCodes' => ['UT'],
            'plannerSettings' => [
                'UT' => ['rate' => 20],
            ],
        ])
            ->assertOk()
            ->assertJsonMissingPath('planningState.old')
            ->assertJsonPath('planningState.new.tier', 'tier2')
            ->assertJsonPath('focusedStateCodes', ['UT'])
            ->assertJsonPath('plannerSettings.UT.rate', 20);
    }

    public function test_account_plan_requires_an_authenticated_user(): void
    {
        $this->getJson('/api/territory-plan')
            ->assertUnauthorized();

        $this->postJson('/api/territory-plan', [
            'planningState' => [],
        ])->assertUnauthorized();
    }
}
