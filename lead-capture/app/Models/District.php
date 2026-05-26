<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    use HasFactory;

    protected $fillable = [
        'state_code',
        'state_name',
        'lea_id',
        'name',
        'short_name',
        'nces_name',
        'city',
        'lea_type',
        'total_students',
        'secondary_students',
        'latitude',
        'longitude',
        'search_text',
    ];

    public function captures(): HasMany
    {
        return $this->hasMany(Capture::class);
    }
}
