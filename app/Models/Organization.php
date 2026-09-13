<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'short_code',
        'type',
        'address',
        'contact_email',
        'signatory_name',
        'signatory_role',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function agencyPrograms(): HasMany
    {
        return $this->hasMany(AgencyProgram::class, 'agency_id');
    }
}
