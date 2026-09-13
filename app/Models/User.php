<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'egov_sub',
        'name',
        'email',
        'mobile',
        'role',
        'organization_id',
        'verified_identity',
        'avatar_url',
    ];

    protected $casts = [
        'verified_identity' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function applicantProfile(): HasOne
    {
        return $this->hasOne(ApplicantProfile::class);
    }

    public function medicalCases(): HasMany
    {
        return $this->hasMany(MedicalCase::class, 'applicant_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
