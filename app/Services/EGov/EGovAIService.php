<?php

namespace App\Services\EGov;

use App\Models\MedicalCase;
use Illuminate\Support\Str;

class EGovAIService
{
    protected string $accessCode;

    public function __construct()
    {
        $this->accessCode = config('services.egov.ai.access_code', 'f2c81ce889a5850fd59487ce988ec1324183682c62d300bdbd33d5064862942b');
    }

    public function generateToken(string $accessCode): array
    {
        if (empty($accessCode)) {
            return [
                'status' => 401,
                'data' => ['message' => 'Generate Access Token - Error. Invalid access code.'],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'access_token' => (string) Str::uuid(),
                'expires_in_seconds' => 28800,
                'credits_total' => 200,
                'credits_remaining' => 200,
            ],
        ];
    }

    public function aiAssistant(string $prompt, string $category = 'PH'): array
    {
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
        
        return "To obtain assistance or access government services through the eGovPH platform (Category: {$category}), citizens can file digital applications and verify identity using PhilSys. Query received: \"{$prompt}\". For service requirement building and medical guarantee letters, eGov's eGuarantee connects citizens directly to DSWD AICS, PCSO, and hospital providers with zero-fee eGovChain ledger tracking.";
    }

    public function speechMaker(string $prompt, string $category = 'PH'): array
    {
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
        return [
            'status' => 200,
            'data' => [
                'data' => "Here's the information extracted from the image:<br><br><b>Document Type:</b> Philippine Driver's License / Official Medical Document<br><b>Issuing Authority:</b> REPUBLIC OF THE PHILIPPINES<br><b>License Number:</b> N01-18-928491<br><b>Full Name:</b> JOSIE SANTOS DELA CRUZ<br><b>Expiry Date:</b> 2030-08-29",
            ],
        ];
    }

    public function classifyAndExtract(string $fileName, string $docType): array
    {
        $type = match (strtolower($docType)) {
            'id' => 'PhilSys Digital ID',
            'indigency' => 'Barangay Certificate of Indigency',
            'authorization' => 'Patient Authorization Letter',
            'medical_abstract' => 'Hospital Medical Abstract',
            'statement_of_account', 'soa' => 'Statement of Account / Billing Estimate',
            'treatment_order' => 'Physician Treatment Order',
            default => 'Official Medical Record',
        };

        return [
            'classified_type' => $type,
            'confidence' => 0.98,
            'extracted_data' => [
                'document_name' => $fileName,
                'extracted_at' => now()->toIso8601String(),
                'disclaimer' => 'AI-generated extraction — subject to authorized staff review.',
            ],
        ];
    }

    public function generateCaseSummary(MedicalCase $medicalCase): array
    {
        $documents = $medicalCase->documents;
        $docTypes = $documents->pluck('document_type')->toArray();

        $requiredDocs = ['id', 'indigency', 'medical_abstract', 'statement_of_account'];
        $missing = array_diff($requiredDocs, $docTypes);

        $summary = sprintf(
            "Patient %s requires medical treatment (%s) at %s. The verified hospital-certified bill is ₱%s. All core documentary requirements are %s.",
            $medicalCase->patient_name,
            $medicalCase->condition_category,
            $medicalCase->provider ? $medicalCase->provider->name : 'Selected Hospital',
            number_format((float) $medicalCase->verified_bill, 2),
            empty($missing) ? 'complete and certified' : 'pending (' . implode(', ', $missing) . ')'
        );

        return [
            'summary' => $summary,
            'missing_requirements' => array_values($missing),
            'completeness_score' => empty($missing) ? 100 : 75,
            'disclaimer' => 'AI-generated summary — subject to evaluator review.',
        ];
    }
}
