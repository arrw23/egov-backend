<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyProgram extends Model
{
    use HasFactory;

    protected $fillable = [
        'agency_id',
        'name',
        'code',
        'description',
        'max_assistance_amount',
        'criteria_summary',
    ];

    protected $casts = [
        'max_assistance_amount' => 'decimal:2',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'agency_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(AgencyApplication::class);
    }
}
