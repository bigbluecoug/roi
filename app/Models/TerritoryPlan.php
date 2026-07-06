<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['account_email', 'planning_state', 'focused_state_codes', 'planner_settings'])]
class TerritoryPlan extends Model
{
    protected function casts(): array
    {
        return [
            'planning_state' => 'array',
            'focused_state_codes' => 'array',
            'planner_settings' => 'array',
        ];
    }
}
