<?php

namespace App\Services\EGov;

use App\Models\MedicalCase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EGovAIService
{
    /**
     * Upstream meters credits per access token, so minting one per call kept
     * the usage counter pinned at zero. One token is reused for its lifetime.
     */
    private const TOKEN_CACHE_KEY = 'egov_ai_access_token';

    protected ?string $accessCode;
    protected string $baseUrl;

    public function __construct()
    {
        $this->accessCode = config('services.egov.ai.access_code');
        $this->baseUrl = config('services.egov.ai.base_url', 'http://localhost:3000/egovph/ai');
    }

    public function generateToken(string $accessCode): array
    {
        $code = !empty($accessCode) ? $accessCode : $this->accessCode;
        if (empty($code)) {
            return [
                'status' => 401,
                'data' => ['message' => 'Generate Access Token - Error. Invalid access code.'],
            ];
        }

        if (EGovMode::isLive()) {
            $response = Http::asJson()->timeout(20)->post($this->endpoint('/api/v1/egov/integration/token'), [
                'access_code' => $code,
            ]);
            return ['status' => $response->status(), 'data' => $response->json() ?: ['message' => 'eGov AI returned an empty response.']];
        }

        return [
            'status' => 200,
            'data' => [
                'access_token' => (string) Str::uuid(),
                'expires_in_seconds' => 28800,
                'credits_total' => 200,
                'credits_remaining' => 200,
                'source' => 'sandbox',
                'simulated' => true,
            ],
        ];
    }

    /**
     * Reads the account's credit balance. It deliberately takes no bearer: the
     * caller's Sanctum token authenticates *this app* and must never be
     * forwarded to a third-party government API.
     */
    public function credits(): array
    {
        if (EGovMode::isLive()) {
            $bearer = $this->requestLiveToken();
            if (! $bearer) {
                return ['status' => 503, 'data' => ['message' => 'eGov AI is not configured.']];
            }

            $response = Http::withToken($bearer)->timeout(20)->get($this->endpoint('/api/v1/egov/integration/credits'));
            $data = $response->json();

            if ($response->successful() && is_array($data)) {
                return ['status' => 200, 'data' => $data + ['source' => 'live_egov_ai', 'simulated' => false]];
            }

            return ['status' => $response->status(), 'data' => $data ?: ['message' => 'eGov AI credits response empty.']];
        }

        return [
            'status' => 200,
            'data' => [
                'credits_total' => 200,
                'credits_used' => 1,
                'credits_remaining' => 199,
                'expires_at' => now()->addDays(2)->toIso8601String(),
                'source' => 'sandbox',
                'simulated' => true,
            ],
        ];
    }


    public function aiAssistant(string $prompt, string $category = 'PH'): array
    {
        if ($live = $this->livePost('/api/v1/egov/integration/ai_assistant/generate', ['prompt' => $prompt, 'category' => $category])) return $live;
        $session = (string) Str::uuid();
        $response = $this->generateContextualResponse($prompt, $category);

        return [
            'status' => 200,
            'data' => [
                'data' => $response,
                'session_id' => $session,
            ],
        ];
    }

    private function generateContextualResponse(string $prompt, string $category): string
    {
        $lowerPrompt = strtolower($prompt);

        if (str_contains($lowerPrompt, 'medical assistance application') || str_contains($lowerPrompt, 'step-by-step')) {
            return "Here is a step-by-step guide for medical assistance applications:\n1. Prepare all required documents.\n2. Submit them via the eGov app or website.\n3. Wait for hospital certification and agency review.\n4. Receive the Guarantee Letter if approved.";
        }
        if (str_contains($lowerPrompt, 'dswd') || str_contains($lowerPrompt, 'aics')) {
            return "The DSWD AICS program provides financial assistance for medical, educational, and transportation needs to individuals in crisis situations.";
        }
        if (str_contains($lowerPrompt, 'pcso')) {
            return "PCSO medical assistance includes financial help for hospitalization, dialysis, chemo, and medicines through the Medical Access Program (MAP).";
        }
        if (str_contains($lowerPrompt, 'document') || str_contains($lowerPrompt, 'requirement')) {
            return "Document requirements typically include a valid PhilSys ID, Certificate of Indigency, Medical Abstract, and Statement of Account or Billing Estimate.";
        }
        if (str_contains($lowerPrompt, 'guarantee letter')) {
            return "A Guarantee Letter (GL) is issued by government agencies like DSWD or PCSO directly to the hospital, guaranteeing payment for your medical bills up to an approved amount.";
        }
        if (str_contains($lowerPrompt, 'egovph') || str_contains($lowerPrompt, 'egov')) {
            return "eGovPH provides a single platform for citizens to access various government services efficiently, securely, and conveniently.";
        }
        if (str_contains($lowerPrompt, 'government services')) {
            return "General government services available include obtaining IDs, requesting documents, and accessing financial or medical assistance through interconnected agencies.";
        }
        
        return "To obtain assistance or access government services through the eGovPH platform (Category: {$category}), citizens can file digital applications and verify identity using PhilSys. Query received: \"{$prompt}\". For service requirement building and medical guarantee letters, GabayMed connects citizens directly to DSWD AICS, PCSO, and hospital providers with zero-fee eGovChain ledger tracking.";
    }

    public function speechMaker(string $prompt, string $category = 'PH'): array
    {
        if ($live = $this->livePost('/api/v1/egov/integration/speech_maker/generate', ['prompt' => $prompt, 'category' => $category])) return $live;
        $session = (string) Str::uuid();
        $speech = "Magandang araw po sa inyong lahat! Isang karangalan po ang tumayo sa inyong harapan upang talakayin ang topic: \"{$prompt}\". Ang ating bansa ay patuloy na umuunlad sa pamamagitan ng digitalisasyon at eGov services.";

        return [
            'status' => 200,
            'data' => [
                'data' => $speech,
                'session_id' => $session,
            ],
        ];
    }

    public function tourism(string $prompt, string $category = 'PH'): array
    {
        if ($live = $this->livePost('/api/v1/egov/integration/tourism/generate', ['prompt' => $prompt, 'category' => $category])) return $live;
        $session = (string) Str::uuid();
        $content = "Explore the beauty of the Philippines! Query: \"{$prompt}\" (Category: {$category}).\n\n**Day 1: Arrival and Beach/Cultural Tour**\nEnjoy world-class hospitality, pristine beaches, and rich cultural heritage.\n\n**Day 2: Eco-Adventure & Local Cuisine**\nExperience authentic Filipino dishes and eco-tourism destinations protected under government heritage programs.";

        return [
            'status' => 200,
            'data' => [
                'data' => $content,
                'session_id' => $session,
            ],
        ];
    }

    public function lawsAndRegulations(string $prompt, string $category = 'PH'): array
    {
        if ($live = $this->livePost('/api/v1/egov/integration/laws_and_regulations/generate', ['prompt' => $prompt, 'category' => $category])) return $live;
        $session = (string) Str::uuid();
        $content = "Ako ay isang eGovPH AI Assistant na nilikha upang tulungan ang mga mamamayang Pilipino sa mga batas at regulasyon (Category: {$category}). Katanungan: \"{$prompt}\".\n\nSa ilalim ng Republic Act No. 11032 (Ease of Doing Business Act) at RA 10173 (Data Privacy Act of 2012), ang lahat ng digital transaction at personal data ay protektado.";

        return [
            'status' => 200,
            'data' => [
                'data' => $content,
                'session_id' => $session,
            ],
        ];
    }

    public function translator(string $prompt, string $sourceLang = 'en', string $targetLang = 'fil'): array
    {
        if ($live = $this->livePost('/api/v1/egov/integration/translator/generate', ['prompt' => $prompt, 'source_lang' => $sourceLang, 'target_lang' => $targetLang])) return $live;
        $translated = match (strtolower($targetLang)) {
            'fil', 'tl' => "Paano dapat umangkop ang sistema ng eGov upang ihanda ang mga mamamayan sa isang mundo ng digital innovation?",
            'es' => "¿Cómo debe adaptarse el sistema eGov para preparar a las futuras generaciones?",
            default => $prompt,
        };

        return [
            'status' => 200,
            'data' => [
                'original_prompt' => $prompt,
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'translate_from' => [
                    'code' => $sourceLang,
                    'label' => strtoupper($sourceLang) === 'EN' ? 'English' : $sourceLang,
                ],
                'translated_prompt' => $translated,
                'transliterated_prompt' => $translated,
            ],
        ];
    }

    public function documentExtractor($file = null): array
    {
        if (EGovMode::isLive()) {
            if (! $file) {
                return ['status' => 422, 'data' => ['message' => 'A file is required for document extraction.']];
            }

            $token = $this->requestLiveToken();
            if (! $token) {
                // Live mode must not substitute a canned extraction.
                return ['status' => 503, 'data' => ['message' => 'eGov AI is not configured.']];
            }

            $response = Http::withToken($token)->timeout(30)
                ->attach('file', $file->get(), $file->getClientOriginalName())
                ->post($this->endpoint('/api/v1/egov/integration/document_extractor/generate'));

            $data = $response->json();

            if ($response->successful() && is_array($data)) {
                return ['status' => $response->status(), 'data' => $data];
            }

            $data = is_array($data) && $data !== [] ? $data : ['message' => 'eGov AI document extraction failed.'];

            if (($data['code'] ?? null) === 'E_INVALID_MULTIPART_REQUEST') {
                // Verified against the live provider: its parser rejects every
                // well-formed multipart body (png/jpeg/pdf, any field name), so
                // no client-side shape can make this call succeed.
                $data['hint'] = 'Upstream defect: the eGov AI document extractor rejects all multipart uploads. OCR is unavailable until eGov fixes the endpoint.';
            }

            return ['status' => $response->status(), 'data' => $data];
        }

        return [
            'status' => 200,
            'data' => [
                'data' => "Here's the information extracted from the image:<br><br><b>Document Type:</b> Philippine Driver's License / Official Medical Document<br><b>Issuing Authority:</b> REPUBLIC OF THE PHILIPPINES<br><b>License Number:</b> N01-18-928491<br><b>Full Name:</b> JOSIE SANTOS DELA CRUZ<br><b>Expiry Date:</b> 2030-08-29",
                'sandbox' => true,
                'simulated' => true,
            ],
        ];
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    private function livePost(string $path, array $payload): ?array
    {
        if (! EGovMode::isLive()) return null;

        $token = $this->requestLiveToken();
        if (! $token) return ['status' => 503, 'data' => ['message' => 'eGov AI is not configured.']];

        $response = $this->postWithToken($path, $payload, $token);

        if ($response->status() === 401) {
            // Upstream can retire a token before its stated expiry; one fresh
            // mint beats surfacing a spurious failure to the caller.
            $token = $this->mintLiveToken();
            if ($token) $response = $this->postWithToken($path, $payload, $token);
        }

        return ['status' => $response->status(), 'data' => $response->json() ?: ['message' => 'eGov AI returned an empty response.']];
    }

    private function postWithToken(string $path, array $payload, string $token)
    {
        return Http::asJson()->withToken($token)->timeout(30)->post($this->endpoint($path), $payload);
    }

    private function requestLiveToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') return $cached;

        return $this->mintLiveToken();
    }

    private function mintLiveToken(): ?string
    {
        if (empty($this->accessCode)) return null;

        $response = Http::asJson()->timeout(20)->post($this->endpoint('/api/v1/egov/integration/token'), [
            'access_code' => $this->accessCode,
        ]);
        if (! $response->successful()) return null;

        $token = $response->json('access_token') ?: $response->json('data.access_token');
        if (! is_string($token) || $token === '') return null;

        // Refresh five minutes early so a token is never used on its boundary.
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, (int) ($response->json('expires_in_seconds') ?: 28800) - 300));

        return $token;
    }

    public function classifyAndExtract(string $fileName, string $docType, $file = null): array
    {
        $type = match (strtolower($docType)) {
            'id' => 'PhilSys Digital ID',
            'indigency' => 'Barangay Certificate of Indigency',
            'authorization' => 'Patient Authorization Letter',
            'medical_abstract' => 'Hospital Medical Abstract',
            'statement_of_account', 'soa' => 'Statement of Account / Billing Estimate',
            'treatment_order' => 'Physician Treatment Order',
            'social_case_study' => 'Social Case Study Report',
            default => 'Official Medical Record',
        };

        $extractedData = [
            'document_name' => $fileName,
            'extracted_at' => now()->toIso8601String(),
        ];

        // The label above comes from the upload metadata, not from a model, so it
        // carries no confidence figure and is never reported as inference.
        $confidence = null;
        $aiAnalysis = null;
        $source = 'sandbox_classifier';

        $extraction = ['status' => 'not_attempted', 'provider' => 'egov_ai_document_extractor'];

        if (EGovMode::isLive()) {
            if ($file !== null) {
                $result = $this->documentExtractor($file);

                if (($result['status'] ?? 500) === 200) {
                    $extractedData['ai_output'] = $result['data']['data'] ?? $result['data'];
                    $confidence = $result['data']['confidence'] ?? null;
                    $extraction = ['status' => 'ok', 'provider' => 'egov_ai_document_extractor'];
                    $source = 'live_document_extractor';
                } else {
                    $extraction = [
                        'status' => 'unavailable',
                        'provider' => 'egov_ai_document_extractor',
                        'message' => $result['data']['message'] ?? 'eGov AI document extraction failed.',
                    ] + (isset($result['data']['hint']) ? ['hint' => $result['data']['hint']] : []);
                }
            }

            // OCR may be unavailable, but the assistant endpoint works, so the
            // reviewer guidance is model output rather than a canned template.
            $ai = $this->livePost('/api/v1/egov/integration/ai_assistant/generate', [
                'prompt' => "A document in a Philippine medical assistance application is labelled \"{$type}\" and filed as \"{$fileName}\". "
                    . "Field-level OCR of the file is unavailable, so do not invent or guess its contents. In at most three sentences, "
                    . "list the specific data elements an evaluator must confirm on this document type before certifying it, and name the "
                    . "most common reason such a document is rejected.",
                'category' => 'PH',
            ]);

            $narrative = $ai['data']['data'] ?? null;
            if (($ai['status'] ?? 500) === 200 && is_string($narrative) && $narrative !== '') {
                $aiAnalysis = $narrative;
                if ($source === 'sandbox_classifier') $source = 'live_ai_assistant';
            }
        } else {
            $extractedData['note'] = 'Sandbox mode: no AI provider was called; the document type was inferred from the upload metadata.';
        }

        $extractedData['disclaimer'] = $aiAnalysis !== null
            ? 'AI-generated guidance — subject to authorized staff review.'
            : 'Classified from upload metadata; no AI provider result was available for this document.';

        return [
            'classified_type' => $type,
            'confidence' => $confidence,
            'source' => $source,
            'ai_analysis' => $aiAnalysis,
            'extraction' => $extraction,
            'extracted_data' => $extractedData,
            'disclaimer' => $extractedData['disclaimer'],
        ];
    }

    /**
     * The document types this service treats as the core requirement set.
     * Shared with the agency completeness calculation so the two cannot drift.
     */
    public const REQUIRED_DOCUMENTS = [
        'id' => 'PhilSys Digital ID',
        'indigency' => 'Barangay Certificate of Indigency',
        'medical_abstract' => 'Hospital Medical Abstract',
        'statement_of_account' => 'Statement of Account / Billing Estimate',
    ];

    /**
     * Requirements list with per-document status, used by the agency inbox so
     * the UI does not have to invent completeness blocks.
     */
    public function requirementStatuses(MedicalCase $medicalCase): array
    {
        $byType = $medicalCase->documents->keyBy('document_type');

        $requirements = [];
        foreach (self::REQUIRED_DOCUMENTS as $type => $title) {
            $doc = $byType->get($type);
            $requirements[] = [
                'type' => $type,
                'title' => $title,
                'document_id' => $doc?->id,
                'status' => $doc?->status ?? 'missing',
            ];
        }

        return $requirements;
    }

    public function completenessScore(MedicalCase $medicalCase): int
    {
        $docs = $medicalCase->documents->keyBy('document_type');

        $satisfied = 0;
        foreach (array_keys(self::REQUIRED_DOCUMENTS) as $type) {
            $doc = $docs->get($type);
            if ($doc && in_array($doc->status, ['verified', 'certified'], true)) {
                $satisfied++;
            }
        }

        return (int) round(($satisfied / max(count(self::REQUIRED_DOCUMENTS), 1)) * 100);
    }

    /**
     * Builds the case summary from real case facts. In live mode the narrative
     * is composed by the eGov AI assistant; in sandbox it is assembled locally
     * from the same facts. Either way the numbers come from the case.
     */
    public function generateCaseSummary(MedicalCase $medicalCase): array
    {
        $requirements = $this->requirementStatuses($medicalCase);
        $missing = array_values(array_map(
            fn ($r) => $r['type'],
            array_filter($requirements, fn ($r) => $r['status'] === 'missing')
        ));
        $completeness = $this->completenessScore($medicalCase);

        $facts = [
            'patient' => $medicalCase->patient_name,
            'case_number' => $medicalCase->case_number,
            'condition' => $medicalCase->condition_category,
            'provider' => $medicalCase->provider?->name ?? 'Selected hospital',
            'verified_bill' => (float) $medicalCase->verified_bill,
            'relationship' => $medicalCase->relationship,
            'status' => $medicalCase->status,
            'documents_present' => $medicalCase->documents->pluck('document_type')->values()->all(),
            'missing' => $missing,
        ];

        $source = 'sandbox_template';

        $summary = sprintf(
            'Patient %s (%s, %s) requires %s at %s. The verified hospital-certified bill is ₱%s. '
            . '%d of %d core documentary requirements are verified or certified (%d%% complete)%s.',
            $facts['patient'],
            $facts['case_number'],
            $facts['relationship'] ?? 'relationship not stated',
            $facts['condition'] ?? 'treatment',
            $facts['provider'],
            number_format($facts['verified_bill'], 2),
            count(self::REQUIRED_DOCUMENTS) - count($missing),
            count(self::REQUIRED_DOCUMENTS),
            $completeness,
            empty($missing) ? '' : '. Outstanding: ' . implode(', ', $missing)
        );

        if (EGovMode::isLive()) {
            $prompt = 'Summarise this medical assistance case for an evaluator, using only these facts: '
                . json_encode($facts, JSON_UNESCAPED_SLASHES);
            $ai = $this->livePost('/api/v1/egov/integration/ai_assistant/generate', [
                'prompt' => $prompt,
                'category' => 'PH',
            ]);

            $narrative = $ai['data']['data'] ?? null;
            if (($ai['status'] ?? 500) === 200 && is_string($narrative) && $narrative !== '') {
                $summary = $narrative;
                $source = 'live_ai_assistant';
            }
        }

        return [
            'summary' => $summary,
            'facts' => $facts,
            'requirements' => $requirements,
            'missing_requirements' => $missing,
            'completeness_score' => $completeness,
            'source' => $source,
            'disclaimer' => 'AI-generated summary — subject to evaluator review.',
        ];
    }
}
