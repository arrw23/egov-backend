<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B6: agency issuing details were hard-coded in AgencyController::decision
 * ("GL-DSWD-<year>-<rand>", "ELENA P. ROBLES"). They belong to the issuing
 * organization, so the GL number prefix and the digital signatory can be
 * derived from the agency that owns the program.
 *
 * Additive only: three nullable columns on organizations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('short_code')->nullable()->after('code');
            $table->string('signatory_name')->nullable()->after('contact_email');
            $table->string('signatory_role')->nullable()->after('signatory_name');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['short_code', 'signatory_name', 'signatory_role']);
        });
    }
};
