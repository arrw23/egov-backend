<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuaranteeUtilization extends Model
{
    use HasFactory;

    protected $fillable = [
        'guarantee_letter_id',
        'hospital_id',
        'utilized_amount',
        'utilization_date',
        'billing_reference',
        'status',
    ];

    protected $casts = [
        'utilized_amount' => 'decimal:2',
        'utilization_date' => 'date',
    ];

    public function guaranteeLetter(): BelongsTo
    {
        return $this->belongsTo(GuaranteeLetter::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'hospital_id');
    }
}
