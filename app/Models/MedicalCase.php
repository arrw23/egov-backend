<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MedicalCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'case_number',
        'applicant_id',
        'patient_name',
        'relationship',
        'provider_id',
        'condition_category',
        'verified_bill',
        'treatment_date',
        'status',
    ];

    protected $casts = [
        'verified_bill' => 'decimal:2',
        'treatment_date' => 'date',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'provider_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CaseDocument::class);
    }

    public function hospitalRequests(): HasMany
    {
        return $this->hasMany(HospitalDocumentRequest::class);
    }

    public function agencyApplications(): HasMany
    {
        return $this->hasMany(AgencyApplication::class);
    }

    public function guaranteeLetters(): HasMany
    {
        return $this->hasMany(GuaranteeLetter::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }
}
