<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\MedicalCase;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * B10 (hospital side): uploadHospitalDocument used to accept a missing file and
 * then invent a "hash" from a timestamp string with a random file size, and
 * stored only a 16-character hash prefix.
 */
class HospitalUploadIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAsRole('hospital_staff');
        Storage::fake('public');
    }

    private function hospitalCase(): MedicalCase
    {
        $staff = \App\Models\User::where('role', 'hospital_staff')->firstOrFail();

        return MedicalCase::where('provider_id', $staff->organization_id)->firstOrFail();
    }

    public function test_upload_without_a_file_is_rejected(): void
    {
        $case = $this->hospitalCase();

        $this->postJson("/api/v1/hospital/cases/{$case->id}/documents", [
            'document_type' => 'treatment_order',
            'title' => 'Physician Treatment Order',
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_upload_stores_the_full_sha256_of_the_real_file(): void
    {
        $case = $this->hospitalCase();
        $before = CaseDocument::count();

        $file = UploadedFile::fake()->create('treatment-order.pdf', 40, 'application/pdf');

        $response = $this->postJson("/api/v1/hospital/cases/{$case->id}/documents", [
            'document_type' => 'treatment_order',
            'title' => 'Physician Treatment Order',
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $this->assertSame($before + 1, CaseDocument::count());

        $doc = CaseDocument::latest('id')->firstOrFail();

        // A full 64-character digest, not a 16-char "DOC-HASH-" prefix.
        $this->assertSame(64, strlen($doc->sha256_hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $doc->sha256_hash);

        // The stored digest must equal the digest of the file we uploaded.
        $expected = hash('sha256', file_get_contents($file->getRealPath()));
        $this->assertSame($expected, $doc->extracted_json['full_sha256'] ?? null);

        // Covers B8 as well: the response must not claim a chain anchor.
        $response->assertJsonPath('chain_anchored', false);
    }

    public function test_upload_does_not_fabricate_a_block_number(): void
    {
        $case = $this->hospitalCase();

        $file = UploadedFile::fake()->create('soa.pdf', 25, 'application/pdf');

        $this->postJson("/api/v1/hospital/cases/{$case->id}/documents", [
            'document_type' => 'statement_of_account',
            'title' => 'Certified Statement of Account',
            'file' => $file,
        ])->assertStatus(200);

        $doc = CaseDocument::latest('id')->firstOrFail();

        $this->assertNotSame(
            '0x1c37b1',
            $doc->extracted_json['blockchain_block_number'] ?? null,
            'A hard-coded block number must not be presented as a real one.'
        );
        $this->assertTrue($doc->extracted_json['ledger_simulated'] ?? false);
        $this->assertFalse($doc->extracted_json['chain_anchored'] ?? true);
    }
}
