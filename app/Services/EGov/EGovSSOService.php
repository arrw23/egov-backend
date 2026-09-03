<?php

namespace App\Services\EGov;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class EGovSSOService
{
    protected ?string $partnerCode;
    protected ?string $partnerSecret;
    protected string $baseUrl;

    public function __construct()
    {
        $this->partnerCode = config('services.egov.sso.partner_code');
        $this->partnerSecret = config('services.egov.sso.partner_secret');
        $this->baseUrl = config('services.egov.sso.base_url', 'http://localhost:3000/egovph/sso');
    }

    public function exchangeToken(string $exchangeCode, string $scope, string $partnerCode, string $partnerSecret): array
    {
        if (empty($exchangeCode)) {
            return [
                'status' => 422,
                'data' => ['message' => 'The exchange code is invalid or has already been used/expired.'],
            ];
        }

        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = Http::asJson()->timeout(15)->post(rtrim($this->baseUrl, '/') . '/api/token', [
                'exchange_code' => $exchangeCode,
                'scope' => $scope ?: 'SSO_AUTHENTICATION',
                'partner_code' => $this->partnerCode,
                'partner_secret' => $this->partnerSecret,
            ]);
            return ['status' => $response->status(), 'data' => $response->json() ?: ['message' => 'eGov SSO returned an empty response.']];
        }

        if ($partnerCode !== $this->partnerCode || $partnerSecret !== $this->partnerSecret) {
            return [
                'status' => 403,
                'data' => ['message' => 'The request is forbidden. Invalid partner credentials.'],
            ];
        }

        // Standard eGov SSO JWT payload encoding base64 sample token
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode([
            'iss' => 'https://stg-superapp-sso.oueg.info',
            'iat' => time(),
            'scope' => $scope ?: 'SSO_AUTHENTICATION',
            'pc' => $partnerCode,
            'tki' => 68,
            'jti' => 'MVPCBEUVCGPZR',
            'exp' => time() + 3600,
        ]));
        $sig = Str::random(43);

        return [
            'status' => 200,
            'data' => [
                'access_token' => "{$header}.{$payload}.{$sig}",
            ],
        ];
    }

    public function fetchSsoProfile(string $bearerToken): array
    {
        if (empty($bearerToken) || !str_contains($bearerToken, '.')) {
            return [
                'status' => 401,
                'data' => ['message' => 'Unauthorized. Access token is missing, invalid, or expired.'],
            ];
        }

        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = Http::withToken(str_replace('Bearer ', '', $bearerToken))->timeout(15)
                ->post(rtrim($this->baseUrl, '/') . '/api/partner/sso_authentication');
            return ['status' => $response->status(), 'data' => $response->json() ?: ['message' => 'eGov SSO returned an empty response.']];
        }

        return [
            'status' => 200,
            'message' => 'OK',
            'data' => [
                'uniqid' => 'MVPCBEUVCGPZR',
                'email' => 'josie@yopmail.com',
                'birth_date' => '1990-01-01',
                'first_name' => 'JOSIE',
                'middle_name' => 'SANTOS',
                'last_name' => 'DELA CRUZ',
                'suffix' => null,
                'gender' => 'female',
                'nationality' => 'Filipino',
                'photo' => 'https://ui-avatars.com/api/?name=JOSIE+DELA+CRUZ&background=1e1b4b&color=fff',
                'mobile' => '+639090000000',
                'address' => '1123 RIZAL ST., POBLACION, CITY OF ALAMINOS, PANGASINAN, PHILIPPINES',
                'street' => '1123 RIZAL ST.',
                'barangay' => 'POBLACION',
                'municipality' => 'CITY OF ALAMINOS',
                'region' => 'REGION I (ILOCOS REGION)',
                'province' => 'PANGASINAN',
                'country' => 'Philippines',
                'country_alpha_2_code' => 'PH',
                'country_alpha_3_code' => 'PHL',
                'postal' => '2404',
                'address_line_2' => null,
                'barangay_code' => '0105503021',
                'province_code' => '0105500000',
                'municipality_code' => '0105503000',
                'region_code' => '0100000000',
                'country_id' => 175,
                'foreign_address' => null,
                'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
                'signature_url' => 'https://egov-stg.s3.ap-southeast-1.amazonaws.com/tmp/signatures/zgy3rLuiH6JlUYJI.png',
                'additional_information' => [
                    'health_data' => [
                        'weight' => '55',
                        'height' => '168',
                        'eyes_color' => 'Black',
                        'complexion' => 'WHITE',
                    ],
                    'birth_place' => [
                        'birth_country' => 'Philippines',
                        'birth_province' => 'PANGASINAN',
                        'birth_municipality' => 'CITY OF ALAMINOS',
                    ],
                    'other_personal_information' => [
                        'marital_status' => 'Single',
                        'religion' => 'N/A',
                    ],
                    'mother_details' => [
                        'mother_maiden_lastname' => 'SANTOS',
                        'mother_maiden_firstname' => 'MARIE',
                        'mother_maiden_middlename' => 'GARCIA',
                        'mother_birthdate' => '1968-03-18',
                    ],
                    'father_details' => [
                        'father_lastname' => 'DELA CRUZ',
                        'father_firstname' => 'RAMON',
                        'father_birthdate' => '1965-10-09',
                    ],
                    'emergency_information' => [
                        'emergency_name' => 'MARK DELA CRUZ',
                        'emergency_contact' => '+63 9090000010',
                        'emergency_relationship' => 'Parent',
                    ],
                    'industry' => [
                        'industry' => 'Professional, Scientific and Technical Activities',
                    ],
                    'occupation' => [
                        'occupation' => 'Software And Applications Developers And Analyst Not Elsewhere Classified',
                    ],
                    'expected_salary' => [
                        'expected_salary' => '130,001-180,000',
                    ],
                    'educational_attainment' => [
                        [
                            'level' => 'Master',
                            'school' => 'AMA Computer College-Pangasinan',
                            'from' => '2008',
                            'educational_background' => 'INFORMATION TECHNOLOGY',
                            'to' => '2012',
                        ],
                    ],
                ],
                'passport' => [
                    'first_name' => 'Josie',
                    'middle_name' => 'SANTOS',
                    'last_name' => 'Dela Cruz',
                    'suffix' => null,
                    'gender' => 'female',
                    'birth_date' => '1990-01-01',
                    'passport_number' => 'PN1234567',
                    'place_issued' => 'Philippines',
                    'issued_date' => '2023-08-29',
                    'expiry_date' => '2030-08-29',
                ],
                'national_id' => [
                    'code' => 'XXX001',
                    'pcn' => '9639954762664080',
                    'face_url' => 'https://ui-avatars.com/api/?name=JOSIE+DELA+CRUZ&background=1e1b4b&color=fff',
                    'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
                ],
                'tin_id' => '123-456-789-000',
            ],
        ];
    }
}
