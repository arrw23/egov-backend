<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgencyApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'medical_case_id',
        'agency_program_id',
        'requested_amount',
        'approved_amount',
        'status',
        'decision_reason',
        'remarks',
        'validity_days',
        'evaluator_id',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
    ];

    public function medicalCase(): BelongsTo
    {
        return $this->belongsTo(MedicalCase::class);
    }

    public function agencyProgram(): BelongsTo
    {
        return $this->belongsTo(AgencyProgram::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function guaranteeLetter(): HasOne
    {
        return $this->hasOne(GuaranteeLetter::class);
    }
}
