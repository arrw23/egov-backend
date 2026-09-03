<?php

namespace App\Services\EGov;

use App\Models\User;
use Illuminate\Support\Facades\Http;

class EReportService
{
    protected ?string $accessCode;
    protected ?string $accessToken;
    protected string $baseUrl;

    public function __construct()
    {
        $this->accessCode = config('services.egov.report.access_code', '2a72bdcac1b0405fb2c679d029f03cfb');
        $this->accessToken = config('services.egov.report.access_token');
        $this->baseUrl = config('services.egov.report.base_url', 'http://localhost:3000/egovph/ereport');
    }

    public function generateToken(?string $accessCode = null): array
    {
        $code = $accessCode ?: $this->accessCode;

        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(20)->post(rtrim($this->baseUrl, '/') . '/api/integration/token', [
                'access_code' => $code,
            ]);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: ['message' => 'Empty response from eReport token endpoint.'],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'access_token' => 'mock-ereport-integration-token',
                'expires_at' => now()->addDays(2)->toIso8601String(),
            ],
        ];
    }

    public function getReportTypes(?string $token = null): array
    {
        $authToken = $token ?: $this->accessToken;

        if (str_starts_with($this->baseUrl, 'https://') && $authToken) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$authToken}",
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/integration/datasets/report_types');

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'jsonapi' => ['version' => '1.0'],
                'data' => [
                    ['type' => 'report_types', 'id' => '0ef6d51a-75be-4ff5-9259-e7f080504f48', 'attributes' => ['code' => 'crime', 'name' => 'Crime', 'sequence' => 1]],
                    ['type' => 'report_types', 'id' => 'faa2eb76-db67-4c17-9bc6-6c65e87a0ea1', 'attributes' => ['code' => 'red_tape', 'name' => 'Red Tape', 'sequence' => 2]],
                    ['type' => 'report_types', 'id' => '488172b8-9ede-4aa7-bcbb-f8f9b3777b02', 'attributes' => ['code' => 'scam', 'name' => 'Scam', 'sequence' => 3]],
                    ['type' => 'report_types', 'id' => 'a3b6104d-1c41-4af8-b898-a05d0fe88226', 'attributes' => ['code' => 'overpricing', 'name' => 'Overpricing', 'sequence' => 6]],
                ],
            ],
        ];
    }

    public function getRegions(?string $token = null): array
    {
        $authToken = $token ?: $this->accessToken;

        if (str_starts_with($this->baseUrl, 'https://') && $authToken) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$authToken}",
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/integration/datasets/regions');

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'jsonapi' => ['version' => '1.0'],
                'data' => [
                    ['type' => 'regions', 'id' => '010000000', 'attributes' => ['name' => 'REGION I (ILOCOS REGION)']],
                    ['type' => 'regions', 'id' => '040000000', 'attributes' => ['name' => 'REGION IV-A (CALABARZON)']],
                    ['type' => 'regions', 'id' => '130000000', 'attributes' => ['name' => 'NATIONAL CAPITAL REGION (NCR)']],
                ],
            ],
        ];
    }

    public function getProvinces(string $regionCode, ?string $token = null): array
    {
        $authToken = $token ?: $this->accessToken;

        if (str_starts_with($this->baseUrl, 'https://') && $authToken) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$authToken}",
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/integration/datasets/provinces', [
                'region_code' => $regionCode,
            ]);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'jsonapi' => ['version' => '1.0'],
                'data' => [
                    ['type' => 'provinces', 'id' => '042100000', 'attributes' => ['region_code' => $regionCode, 'name' => 'CAVITE']],
                    ['type' => 'provinces', 'id' => '043400000', 'attributes' => ['region_code' => $regionCode, 'name' => 'LAGUNA']],
                ],
            ],
        ];
    }

    public function getMunicipalities(string $provinceCode, ?string $token = null): array
    {
        $authToken = $token ?: $this->accessToken;

        if (str_starts_with($this->baseUrl, 'https://') && $authToken) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$authToken}",
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/integration/datasets/municipalities', [
                'province_code' => $provinceCode,
            ]);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'jsonapi' => ['version' => '1.0'],
                'data' => [
                    ['type' => 'municipalities', 'id' => '042111000', 'attributes' => ['region_code' => '040000000', 'province_code' => $provinceCode, 'name' => 'KAWIT']],
                    ['type' => 'municipalities', 'id' => '042103000', 'attributes' => ['region_code' => '040000000', 'province_code' => $provinceCode, 'name' => 'BACOOR CITY']],
                ],
            ],
        ];
    }

    public function getBarangays(string $municipalityCode, ?string $token = null): array
    {
        $authToken = $token ?: $this->accessToken;

        if (str_starts_with($this->baseUrl, 'https://') && $authToken) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$authToken}",
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/integration/datasets/barangays', [
                'municipality_code' => $municipalityCode,
            ]);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'jsonapi' => ['version' => '1.0'],
                'data' => [
                    ['type' => 'barangays', 'id' => '042111011', 'attributes' => ['region_code' => '040000000', 'province_code' => '042100000', 'municipality_code' => $municipalityCode, 'name' => 'Toclong']],
                    ['type' => 'barangays', 'id' => '042111006', 'attributes' => ['region_code' => '040000000', 'province_code' => '042100000', 'municipality_code' => $municipalityCode, 'name' => 'Poblacion']],
                ],
            ],
        ];
    }

    public function submitComplaint(array $payload, ?string $token = null): array
    {
        $authToken = $token ?: $this->accessToken;

        $body = array_merge([
            'mobile' => $payload['mobile'] ?? '639090000000',
            'first_name' => $payload['first_name'] ?? 'Josie',
            'last_name' => $payload['last_name'] ?? 'Dela Cruz',
            'gender' => $payload['gender'] ?? 'Female',
            'complainant_email' => $payload['complainant_email'] ?? 'josie@yopmail.com',
            'report_type' => $payload['report_type'] ?? 'red_tape',
            'subject' => $payload['subject'] ?? 'Hospital Processing Delay Report',
            'message' => $payload['message'] ?? 'Guarantee Letter processing delayed at health facility desk.',
            'evidences' => $payload['evidences'] ?? [],
            'region_code' => $payload['region_code'] ?? '040000000',
            'province_code' => $payload['province_code'] ?? '042100000',
            'municipality_code' => $payload['municipality_code'] ?? '042111000',
            'barangay_code' => $payload['barangay_code'] ?? '042111011',
            'latitude' => $payload['latitude'] ?? '14.60',
            'longitude' => $payload['longitude'] ?? '120.98',
        ]);

        if (str_starts_with($this->baseUrl, 'https://') && $authToken) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$authToken}",
                'Content-Type' => 'application/json',
            ])->timeout(20)->post(rtrim($this->baseUrl, '/') . '/api/integration/submit_complaint', $body);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: ['message' => 'Empty response from submit complaint.'],
            ];
        }

        $caseNumber = 'PFM-' . date('mdy') . '-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return [
            'status' => 200,
            'data' => [
                'code' => 200,
                'message' => "We received your report. We'll get back to you.",
                'case_number' => $caseNumber,
            ],
        ];
    }

    public function requestOtp(string $email, ?string $token = null): array
    {
        $authToken = $token ?: $this->accessToken;

        if (str_starts_with($this->baseUrl, 'https://') && $authToken) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$authToken}",
                'Content-Type' => 'application/json',
            ])->timeout(20)->post(rtrim($this->baseUrl, '/') . '/api/integration/verify/request', [
                'email' => $email,
            ]);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'code' => 200,
                'already_verified' => false,
                'message' => "A 6-digit verification code has been sent to {$email}. It expires in 5 minutes.",
            ],
        ];
    }

    public function confirmOtp(string $email, string $otp, ?string $token = null): array
    {
        $authToken = $token ?: $this->accessToken;

        if (str_starts_with($this->baseUrl, 'https://') && $authToken) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$authToken}",
                'Content-Type' => 'application/json',
            ])->timeout(20)->post(rtrim($this->baseUrl, '/') . '/api/integration/verify/confirm', [
                'email' => $email,
                'otp' => $otp,
            ]);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'code' => 200,
                'report_view_token' => 'mock-report-view-token-' . substr(md5($email . time()), 0, 16),
                'expires_at' => now()->addHours(24)->toIso8601String(),
            ],
        ];
    }

    public function getReports(string $viewToken, array $params = []): array
    {
        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = Http::withHeaders([
                'X-EReport-View-Token' => $viewToken,
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/integration/reports', $params);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'jsonapi' => ['version' => '1.0'],
                'meta' => ['pagination' => ['total' => 1, 'per_page' => 25, 'current_page' => 1, 'total_pages' => 1]],
                'data' => [
                    [
                        'type' => 'reports',
                        'id' => '00000000-0000-0000-0000-000000000000',
                        'attributes' => [
                            'case_number' => 'PFM-090326-1489',
                            'complainant' => [
                                'first_name' => 'Josie',
                                'last_name' => 'Dela Cruz',
                                'fullname' => 'Josie Dela Cruz',
                                'phone_number' => '639090000000',
                                'gender' => 'Female',
                                'email' => 'josie@yopmail.com',
                            ],
                            'report_type' => [
                                'id' => 'faa2eb76-db67-4c17-9bc6-6c65e87a0ea1',
                                'code' => 'red_tape',
                                'name' => 'Red Tape',
                            ],
                            'subject' => 'Delayed Medical Clearance Evaluation',
                            'message' => 'Hospital social work assessment processing exceeded standard processing time.',
                            'status' => 'PENDING',
                            'formatted_status' => 'Pending',
                            'created_at' => now()->toIso8601String(),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function getReportByCaseNumber(string $caseNumber, string $viewToken): array
    {
        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = Http::withHeaders([
                'X-EReport-View-Token' => $viewToken,
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/integration/reports/' . $caseNumber);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'data' => [
                    'id' => '00000000-0000-0000-0000-000000000000',
                    'case_number' => $caseNumber,
                    'complainant' => [
                        'first_name' => 'Josie',
                        'last_name' => 'Dela Cruz',
                        'fullname' => 'Josie Dela Cruz',
                        'phone_number' => '639090000000',
                        'gender' => 'Female',
                        'email' => 'josie@yopmail.com',
                    ],
                    'report_type' => [
                        'id' => 'faa2eb76-db67-4c17-9bc6-6c65e87a0ea1',
                        'code' => 'red_tape',
                        'name' => 'Red Tape',
                    ],
                    'subject' => 'Delayed Medical Clearance Evaluation',
                    'message' => 'Hospital social work assessment processing exceeded standard processing time.',
                    'status' => 'PENDING',
                    'formatted_status' => 'Pending',
                    'history' => [
                        ['status' => 'PENDING', 'formatted_status' => 'Pending', 'created_at' => now()->toIso8601String()],
                    ],
                    'created_at' => now()->toIso8601String(),
                ],
            ],
        ];
    }

    public function submitAuditReport(string $action, array $payload, ?User $actor = null): array
    {
        if (str_starts_with($this->baseUrl, 'https://') && config('services.egov.live_mutations')) {
            return $this->submitComplaint(array_merge($payload, ['subject' => $action]));
        }

        return [
            'status' => 'logged',
            'report_id' => 'EREPORT-' . strtoupper(substr(md5($action . time()), 0, 8)),
            'action' => $action,
            'timestamp' => now()->toIso8601String(),
            'actor_name' => $actor ? $actor->name : 'System',
            'actor_role' => $actor ? $actor->role : 'system',
            'payload_hash' => hash('sha256', json_encode($payload)),
        ];
    }

}
