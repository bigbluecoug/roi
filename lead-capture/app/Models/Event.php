<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'state_code',
        'starts_on',
        'venue',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function captures(): HasMany
    {
        return $this->hasMany(Capture::class);
    }
}
