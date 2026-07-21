<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'philsys_id',
        'full_name',
        'birth_date',
        'consent_given',
        'consent_timestamp',
        'verification_reference',
        'status',
    ];

    protected $casts = [
        'consent_given' => 'boolean',
        'consent_timestamp' => 'datetime',
        'birth_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
