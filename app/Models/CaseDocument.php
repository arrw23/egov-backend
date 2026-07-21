<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'medical_case_id',
        'document_type',
        'title',
        'storage_path',
        'file_size',
        'status',
        'sha256_hash',
        'verification_reference',
        'verified_by_user_id',
        'extracted_json',
    ];

    protected $casts = [
        'extracted_json' => 'array',
    ];

    public function medicalCase(): BelongsTo
    {
        return $this->belongsTo(MedicalCase::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
