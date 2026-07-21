<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['hospital', 'agency']);
            $table->string('address')->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('egov_sub')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->enum('role', ['applicant', 'hospital_staff', 'agency_evaluator'])->default('applicant');
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->onDelete('set null');
            $table->boolean('verified_identity')->default(false);
            $table->string('avatar_url')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('applicant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('philsys_id')->nullable();
            $table->string('full_name');
            $table->date('birth_date')->nullable();
            $table->boolean('consent_given')->default(false);
            $table->timestamp('consent_timestamp')->nullable();
            $table->string('verification_reference')->nullable();
            $table->string('status')->default('unverified');
            $table->timestamps();
        });

        Schema::create('medical_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number')->unique();
            $table->foreignId('applicant_id')->constrained('users')->onDelete('cascade');
            $table->string('patient_name');
            $table->string('relationship');
            $table->foreignId('provider_id')->constrained('organizations');
            $table->string('condition_category');
            $table->decimal('verified_bill', 12, 2)->default(0);
            $table->date('treatment_date')->nullable();
            $table->string('status')->default('DRAFT');
            $table->timestamps();
        });

        Schema::create('case_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_case_id')->constrained('medical_cases')->onDelete('cascade');
            $table->string('document_type');
            $table->string('title');
            $table->string('storage_path');
            $table->integer('file_size')->default(0);
            $table->string('status')->default('uploaded');
            $table->string('sha256_hash')->nullable();
            $table->string('verification_reference')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->json('extracted_json')->nullable();
            $table->timestamps();
        });

        Schema::create('hospital_document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_case_id')->constrained('medical_cases')->onDelete('cascade');
            $table->foreignId('hospital_id')->constrained('organizations');
            $table->json('requested_document_types');
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('agency_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('organizations')->onDelete('cascade');
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->decimal('max_assistance_amount', 12, 2);
            $table->text('criteria_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('agency_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_case_id')->constrained('medical_cases')->onDelete('cascade');
            $table->foreignId('agency_program_id')->constrained('agency_programs');
            $table->decimal('requested_amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->default(0);
            $table->string('status')->default('submitted');
            $table->text('decision_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->integer('validity_days')->default(30);
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('guarantee_letters', function (Blueprint $table) {
            $table->id();
            $table->string('gl_number')->unique();
            $table->foreignId('agency_application_id')->constrained('agency_applications')->onDelete('cascade');
            $table->foreignId('medical_case_id')->constrained('medical_cases')->onDelete('cascade');
            $table->string('patient_name');
            $table->string('applicant_name');
            $table->string('hospital_name');
            $table->decimal('approved_amount', 12, 2);
            $table->string('covered_service');
            $table->date('issue_date');
            $table->date('expiration_date');
            $table->string('digital_signatory_name');
            $table->string('digital_signatory_role');
            $table->text('qr_payload');
            $table->string('chain_reference');
            $table->string('status')->default('valid');
            $table->timestamps();
        });

        Schema::create('guarantee_utilizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guarantee_letter_id')->constrained('guarantee_letters')->onDelete('cascade');
            $table->foreignId('hospital_id')->constrained('organizations');
            $table->decimal('utilized_amount', 12, 2);
            $table->date('utilization_date');
            $table->string('billing_reference');
            $table->string('status')->default('confirmed');
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('message');
            $table->string('type')->default('info');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_case_id')->nullable()->constrained('medical_cases')->onDelete('cascade');
            $table->foreignId('actor_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('actor_name');
            $table->string('action');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('chain_hash');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('guarantee_utilizations');
        Schema::dropIfExists('guarantee_letters');
        Schema::dropIfExists('agency_applications');
        Schema::dropIfExists('agency_programs');
        Schema::dropIfExists('hospital_document_requests');
        Schema::dropIfExists('case_documents');
        Schema::dropIfExists('medical_cases');
        Schema::dropIfExists('applicant_profiles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');
    }
};
