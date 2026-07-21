<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuaranteeLetter extends Model
{
    use HasFactory;

    protected $fillable = [
        'gl_number',
        'agency_application_id',
        'medical_case_id',
        'patient_name',
        'applicant_name',
        'hospital_name',
        'approved_amount',
        'covered_service',
        'issue_date',
        'expiration_date',
        'digital_signatory_name',
        'digital_signatory_role',
        'qr_payload',
        'chain_reference',
        'status',
    ];

    protected $casts = [
        'approved_amount' => 'decimal:2',
        'issue_date' => 'date',
        'expiration_date' => 'date',
    ];

    public function agencyApplication(): BelongsTo
    {
        return $this->belongsTo(AgencyApplication::class);
    }

    public function medicalCase(): BelongsTo
    {
        return $this->belongsTo(MedicalCase::class);
    }

    public function utilizations(): HasMany
    {
        return $this->hasMany(GuaranteeUtilization::class);
    }
}
