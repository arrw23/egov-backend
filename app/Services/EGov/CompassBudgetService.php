<?php

namespace App\Services\EGov;

use App\Models\GuaranteeLetter;
use Illuminate\Support\Facades\Http;

class CompassBudgetService
{
    protected ?string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.egov.compass.api_key');
        $this->baseUrl = config('services.egov.compass.base_url', 'http://localhost:3000/egovph/compass');
    }

    public function getSaaodbRecords(array $params = []): array
    {
        $query = array_merge([
            'reportYear' => 2026,
            'period' => 'FY',
            'page' => 1,
            'limit' => 100,
        ], $params);

        if (EGovMode::isLive('compass')) {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/v1/records/saaodb', $query);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'data' => [
                    [
                        'id' => 1,
                        'fileVersionId' => 'FV-2026-001',
                        'sourceRow' => 12,
                        'sheetScope' => $query['sheetScope'] ?? 'summary',
                        'reportYear' => (int) $query['reportYear'],
                        'period' => $query['period'],
                        'entityName' => $query['entityName'] ?? 'Department of Social Welfare and Development',
                        'class' => $query['class'] ?? 'MOOE',
                        'appropriations' => 20000000000.00,
                        'allotments' => 18000000000.00,
                        'obligations' => 12000000000.00,
                        'disbursements' => 9500000000.00,
                        'unobligatedAllotments' => 6000000000.00,
                    ],
                ],
                'total' => 1,
                'page' => (int) $query['page'],
                'limit' => (int) $query['limit'],
            ],
        ];
    }

    public function getSaaodbDashboard(int $reportYear = 2026, string $sheetScope = 'summary'): array
    {
        if (EGovMode::isLive('compass')) {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/v1/records/saaodb/dashboard', [
                'reportYear' => $reportYear,
                'sheetScope' => $sheetScope,
            ]);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'reportYear' => $reportYear,
                'sheetScope' => $sheetScope,
                'cascade' => [
                    'appropriations' => 7446956425179.78,
                    'adjustments' => 67295855521.86,
                    'totalAvailable' => 7514252280701.64,
                    'allotments' => 5264033425281.64,
                    'obligations' => 1523309434959.98,
                    'unobligated' => 3740723990321.66,
                    'disbursements' => 1211496810098.76,
                    'unreleased' => 2250218855420,
                ],
                'rates' => [
                    'obligationRate' => 0.28938065,
                    'disbRateOblig' => 0.79530579,
                    'disbRateAppro' => 0.16122652858091654,
                ],
                'classBreakdown' => [
                    ['class' => 'PS', 'amount' => 396271169433.72],
                    ['class' => 'MOOE', 'amount' => 755239194473.85],
                    ['class' => 'FINEX', 'amount' => 273157681762.55],
                    ['class' => 'CO', 'amount' => 98641389289.86],
                ],
                'appropriationSplit' => [
                    'currentYear' => 6793162000000,
                    'continuing' => 653794425179.78,
                    'hasSplit' => true,
                ],
                'topEntities' => [],
            ],
        ];
    }

    public function getSaaodbEntities(array $params = []): array
    {
        $query = array_merge([
            'reportYear' => 2026,
            'sheetScope' => 'agency',
        ], $params);

        if (EGovMode::isLive('compass')) {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/v1/records/saaodb/entities', $query);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'reportYear' => (int) $query['reportYear'],
                'sheetScope' => $query['sheetScope'],
                'entities' => [
                    [
                        'name' => $query['expandParent'] ?? 'Department of Social Welfare and Development',
                        'agencies' => [
                            ['name' => 'Office of the Secretary', 'code' => '010010000000'],
                            ['name' => 'National Council on Disability Affairs', 'code' => '010020000000'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function getNcaRecords(array $params = []): array
    {
        $query = array_merge([
            'budgetYear' => 2026,
            'page' => 1,
            'limit' => 100,
        ], $params);

        if (EGovMode::isLive('compass')) {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/v1/records/nca', $query);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'data' => [
                    [
                        'ncaNo' => 'NCA-BMB-A-26-0000100',
                        'budgetYear' => (int) $query['budgetYear'],
                        'deptCode' => $query['deptCode'] ?? '010000000000',
                        'agencyCode' => $query['agencyCode'] ?? '010010000000',
                        'amount' => 500000000.00,
                        'dateIssued' => now()->toIso8601String(),
                    ],
                ],
                'total' => 1,
                'page' => (int) $query['page'],
                'limit' => (int) $query['limit'],
            ],
        ];
    }

    public function getSaroRecords(array $params = []): array
    {
        $query = array_merge([
            'page' => 1,
            'limit' => 100,
        ], $params);

        if (EGovMode::isLive('compass')) {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/v1/records/saro', $query);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'data' => [
                    [
                        'saroNo' => $query['saroNo'] ?? 'SARO-BMB-A-26-0000001',
                        'deptCode' => $query['deptCode'] ?? '010000000000',
                        'agencyCode' => $query['agencyCode'] ?? '010010000000',
                        'expenseClass' => $query['expenseClass'] ?? '5020000000',
                        'amount' => 75000000.00,
                        'dateIssued' => now()->toIso8601String(),
                    ],
                ],
                'total' => 1,
                'page' => (int) $query['page'],
                'limit' => (int) $query['limit'],
            ],
        ];
    }

    public function getLgsfRecords(array $params = []): array
    {
        $query = array_merge([
            'fiscalYear' => 2026,
            'page' => 1,
            'limit' => 100,
        ], $params);

        if (EGovMode::isLive('compass')) {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/v1/records/lgsf', $query);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'data' => [
                    [
                        'id' => 'LGSF-2026-FALGU-001',
                        'fiscalYear' => (int) $query['fiscalYear'],
                        'programCode' => $query['programCode'] ?? 'FALGU',
                        'regionCode' => $query['regionCode'] ?? 'PH030000000',
                        'province' => $query['province'] ?? 'Bulacan',
                        'cityMunicipality' => $query['cityMunicipality'] ?? 'Malolos',
                        'amount' => 25000000.00,
                    ],
                ],
                'total' => 1,
                'page' => (int) $query['page'],
                'limit' => (int) $query['limit'],
            ],
        ];
    }

    public function getLgsfDashboard(array $params = []): array
    {
        $query = array_merge([
            'programCode' => 'FALGU',
            'reportYear' => 2026,
            'page' => 1,
            'limit' => 25,
        ], $params);

        if (EGovMode::isLive('compass')) {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/v1/records/lgsf/dashboard', $query);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'programCode' => $query['programCode'],
                'reportYear' => (int) $query['reportYear'],
                'kpis' => [
                    'totalReleased' => 1500000000.00,
                    'projectCount' => 120,
                    'lguCount' => 45,
                    'barangayCount' => 180,
                    'regionCount' => 17,
                    'provinceCount' => 82,
                    'fiscalYearCount' => 1,
                ],
                'trend' => [],
                'projects' => [
                    'rows' => [
                        [
                            'projectId' => 'PRJ-2026-001',
                            'title' => 'Healthcare Access Facility Support',
                            'allocatedAmount' => 15000000.00,
                            'lgu' => $query['municipality'] ?? 'Malolos',
                        ],
                    ],
                    'total' => 1,
                    'page' => (int) $query['page'],
                    'pageSize' => (int) $query['limit'],
                ],
            ],
        ];
    }

    public function getBudgetStatus(string $programCode = 'DSWD-AICS'): array
    {
        $utilizedAmount = $this->sumUtilizedGuarantees();

        if (EGovMode::isLive('compass')) {
            // In live mode, report the DSWD entity's own figures. Previously
            // the national ₱7.5T cascade was returned labelled as the DSWD
            // program, which overstated the program's budget by orders of
            // magnitude.
            $records = $this->getSaaodbRecords([
                'entityName' => 'Department of Social Welfare and Development',
                'reportYear' => (int) now()->year,
            ]);

            $rows = $records['data']['data'] ?? [];

            if ($records['status'] === 200 && ! empty($rows)) {
                $row = $rows[0];
                $allotments = (float) ($row['allotments'] ?? 0);
                $obligations = (float) ($row['obligations'] ?? 0);
                $unobligated = (float) ($row['unobligatedAllotments'] ?? ($allotments - $obligations));

                return [
                    'program_code' => $programCode,
                    'fund_source' => 'GAA ' . now()->year . ' DSWD SAAODB (DBM Compass)',
                    'entity_name' => $row['entityName'] ?? 'Department of Social Welfare and Development',
                    'total_allocation' => $allotments,
                    'allotments' => $allotments,
                    'utilized_amount' => $utilizedAmount,
                    'agency_obligations' => $obligations,
                    'remaining_balance' => $unobligated,
                    'disbursements' => (float) ($row['disbursements'] ?? 0),
                    'compass_reference' => 'DBM-COMPASS-' . now()->year . '-DSWD-SAAODB',
                    'status' => 'Active · Funds Available',
                    'source' => 'live',
                ];
            }

            return [
                'program_code' => $programCode,
                'fund_source' => 'GAA ' . now()->year . ' DSWD SAAODB (DBM Compass)',
                'total_allocation' => null,
                'utilized_amount' => $utilizedAmount,
                'remaining_balance' => null,
                'compass_reference' => 'DBM-COMPASS-' . now()->year . '-DSWD-SAAODB',
                'status' => 'Unavailable · Compass lookup failed',
                'source' => 'live',
            ];
        }

        $totalAllocation = 20000000.00;

        return [
            'program_code' => $programCode,
            'fund_source' => 'GAA ' . now()->year . ' DSWD AICS Budget Allocation (sandbox figures)',
            'total_allocation' => $totalAllocation,
            'utilized_amount' => $utilizedAmount,
            'remaining_balance' => $totalAllocation - $utilizedAmount,
            'compass_reference' => 'DBM-COMPASS-' . now()->year . '-AICS-NCR',
            'status' => 'Active · Funds Available',
            'source' => 'sandbox',
        ];
    }

    /**
     * Sum of issued guarantee letters that are still live.
     *
     * GL statuses are only valid / partially_utilized / fully_utilized; the
     * previous where('status','active') never matched anything, so the utilizer
     * figure was always ₱0.
     */
    private function sumUtilizedGuarantees(): float
    {
        return (float) GuaranteeLetter::whereIn('status', [
            'valid',
            'partially_utilized',
            'fully_utilized',
        ])->sum('approved_amount');
    }
}
