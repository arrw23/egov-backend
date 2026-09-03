<?php

namespace App\Services\EGov;

use App\Models\ApplicantProfile;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class EVerifyService
{
    protected ?string $clientId;
    protected ?string $clientSecret;
    protected string $baseUrl;

    public function __construct()
    {
        $this->clientId = config('services.egov.everify.client_id');
        $this->clientSecret = config('services.egov.everify.client_secret');
        $this->baseUrl = config('services.egov.everify.base_url', 'http://localhost:3000/egovph/everify');
    }

    public function authenticate(string $clientId, string $clientSecret): array
    {
        if (empty($clientId) || empty($clientSecret)) {
            return [
                'status' => 403,
                'data' => ['message' => 'Forbidden. Invalid client credentials.'],
            ];
        }

        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = Http::asJson()->timeout(15)->post(rtrim($this->baseUrl, '/') . '/api/auth', [
                'client_id' => $clientId ?: $this->clientId,
                'client_secret' => $clientSecret ?: $this->clientSecret,
            ]);
            return ['status' => $response->status(), 'data' => $response->json() ?: ['message' => 'eVerify returned an empty response.']];
        }

        return [
            'status' => 200,
            'data' => [
                'data' => [
                    'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.' . base64_encode(json_encode([
                        'client_id' => $clientId,
                        'scope' => 'EVERIFY_READ',
                        'exp' => time() + 86400,
                    ])) . '.' . Str::random(32),
                    'token_type' => 'Bearer',
                    'expires_at' => (string) (time() + 86400),
                ],
            ],
        ];
    }

    public function verifyDemographics(array $params, string $token = ''): array
    {
        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = $this->liveRequest('/api/query', $params, $token);
            if ($response !== null) return $response;
        }
        $firstName = strtoupper($params['first_name'] ?? 'JUAN');
        $middleName = strtoupper($params['middle_name'] ?? 'SANTOS');
        $lastName = strtoupper($params['last_name'] ?? 'DELA CRUZ');
        $suffix = $params['suffix'] ?? null;
        $birthDate = $params['birth_date'] ?? '1990-01-01';

        return [
            'status' => 200,
            'data' => [
                'code' => 'AAA000',
                'token' => '268259975162549530929556586925358978',
                'reference' => '3013490625984368',
                'face_url' => 'https://ui-avatars.com/api/?name=JUAN+DELA+CRUZ&background=1e1b4b&color=fff',
                'full_name' => trim("{$firstName} {$middleName} {$lastName} {$suffix}"),
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'suffix' => $suffix,
                'gender' => 'Male',
                'marital_status' => 'Single',
                'blood_type' => 'A',
                'email' => 'josie@yopmail.com',
                'mobile_number' => '639090000000',
                'birth_date' => $birthDate,
                'full_address' => '123 Sample Street, Sample Barangay, Sample City, Sample Province, Philippines, 1000',
                'address_line_1' => '123 Sample Street',
                'address_line_2' => null,
                'barangay' => 'Sample Barangay',
                'municipality' => 'Sample City',
                'province' => 'Sample Province',
                'country' => 'Philippines',
                'postal_code' => '1000',
                'present_full_address' => '123 Sample Street, Sample Barangay, Sample City, Sample Province, Philippines, 1000',
                'present_address_line_1' => '123 Sample Street',
                'present_address_line_2' => null,
                'present_barangay' => 'Sample Barangay',
                'present_municipality' => 'Sample City',
                'present_province' => 'Sample Province',
                'present_country' => 'Philippines',
                'present_postal_code' => '1000',
                'residency_status' => 'N/A',
                'place_of_birth' => 'Sample City, Sample Province',
                'pob_municipality' => 'Sample City',
                'pob_province' => 'Sample Province',
                'pob_country' => 'Philippines',
            ],
            'meta' => [
                'tier_level' => 'Tier II',
                'result_grade' => 1,
            ],
        ];
    }

    public function checkQr(string $qrValue, string $token = ''): array
    {
        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = $this->liveRequest('/api/query/qr/check', ['value' => $qrValue], $token);
            if ($response !== null) return $response;
        }
        if (empty($qrValue)) {
            return [
                'status' => 422,
                'data' => ['message' => 'Invalid QR code format.'],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'pcn' => '9639-9547-6266-4080',
            ],
            'meta' => [
                'qr_type' => 'Philsys Card Number',
            ],
        ];
    }

    public function verifyQr(string $qrValue, string $sessionId, string $token = ''): array
    {
        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = $this->liveRequest('/api/query/qr', ['value' => $qrValue, 'face_liveness_session_id' => $sessionId], $token);
            if ($response !== null) return $response;
        }
        return [
            'status' => 200,
            'data' => [
                'code' => 'AAA001',
                'token' => 'TOKEN-1',
                'reference' => '1234123412341324',
                'face_url' => 'https://ui-avatars.com/api/?name=JUAN+DELA+CRUZ&background=1e1b4b&color=fff',
                'full_name' => 'JUAN SANTOS DELA CRUZ',
                'first_name' => 'JUAN',
                'middle_name' => 'SANTOS',
                'last_name' => 'DELA CRUZ',
                'suffix' => 'JR',
                'gender' => 'Male',
                'marital_status' => 'Single',
                'blood_type' => 'Unknown',
                'email' => 'josie@yopmail.com',
                'mobile_number' => '639661231231',
                'birth_date' => '1989-09-12',
                'full_address' => '1123 RIZAL ST., POBLACION, CITY OF ALAMINOS, PANGASINAN, PHILIPPINES',
                'address_line_1' => '1123 RIZAL ST.',
                'address_line_2' => 'POBLACION',
                'barangay' => 'POBLACION',
                'municipality' => 'CITY OF ALAMINOS',
                'province' => 'PANGASINAN',
                'country' => 'Philippines',
                'postal_code' => '2404',
                'present_full_address' => '1123 RIZAL ST., POBLACION, CITY OF ALAMINOS, PANGASINAN, PHILIPPINES',
                'present_address_line_1' => '1123 RIZAL ST.',
                'present_address_line_2' => 'POBLACION',
                'present_barangay' => 'POBLACION',
                'present_municipality' => 'CITY OF ALAMINOS',
                'present_province' => 'PANGASINAN',
                'present_country' => 'Philippines',
                'present_postal_code' => '2404',
                'residency_status' => 'N/A',
                'place_of_birth' => 'CITY OF ALAMINOS, PANGASINAN',
                'pob_municipality' => 'CITY OF ALAMINOS',
                'pob_province' => 'PANGASINAN',
                'pob_country' => 'Philippines',
            ],
            'meta' => [
                'tier_level' => 'Tier II',
                'result_grade' => 1,
            ],
        ];
    }

    private function liveRequest(string $path, array $payload, string $token = ''): ?array
    {
        $http = Http::asJson()->timeout(20);
        if ($token !== '') $http = $http->withToken(str_replace('Bearer ', '', $token));
        $response = $http->post(rtrim($this->baseUrl, '/') . $path, $payload);
        if (!$response->successful()) return ['status' => $response->status(), 'data' => $response->json() ?: ['message' => 'eVerify request failed.']];
        return ['status' => $response->status(), 'data' => $response->json() ?: []];
    }

    public function recordConsentAndVerify(User $user, bool $consentGiven): ApplicantProfile
    {
        if (!$consentGiven) {
            throw new \InvalidArgumentException("Consent must be granted to proceed with PhilSys eVerify identity check.");
        }

        $verificationRef = 'EVR-' . strtoupper(Str::random(4)) . '-' . rand(1000, 9999) . '-2026';

        $profile = ApplicantProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'philsys_id' => 'PSN-8192-3049-1829',
                'full_name' => $user->name,
                'birth_date' => '1989-09-18',
                'consent_given' => true,
                'consent_timestamp' => now(),
                'verification_reference' => $verificationRef,
                'status' => 'verified',
            ]
        );

        $user->verified_identity = true;
        $user->save();

        return $profile;
    }
}
