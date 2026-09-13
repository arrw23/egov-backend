<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            // Digest of the exact payload that was hashed into chain_hash.
            // Verification cannot reproduce that payload from the stored row
            // (metadata is augmented with anchor details after hashing), so the
            // digest is persisted to make the chain independently checkable.
            $table->string('payload_sha256', 64)->nullable()->after('chain_hash');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn('payload_sha256');
        });
    }
};
