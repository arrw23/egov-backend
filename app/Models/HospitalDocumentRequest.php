<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalDocumentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'medical_case_id',
        'hospital_id',
        'requested_document_types',
        'status',
        'notes',
    ];

    protected $casts = [
        'requested_document_types' => 'array',
    ];

    public function medicalCase(): BelongsTo
    {
        return $this->belongsTo(MedicalCase::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'hospital_id');
    }
}
