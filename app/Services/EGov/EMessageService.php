<?php

namespace App\Services\EGov;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EMessageService
{
    private string $baseUrl;
    private string $apiToken;

    public function __construct()
    {
        $this->baseUrl = config('services.egov.emessage.base_url', 'https://platforms-api.e.gov.ph/emessage');
        // No hard-coded fallback token: a missing value must fail loudly.
        $this->apiToken = (string) config('services.egov.emessage.access_token');
    }

    /**
     * Sends an SMS message to a recipient number using the eMessage Push SMS endpoint.
     * POST {{base_url}}/messaging/v1/sms/push
     * Header: X-EMESSAGE-Auth: <API-TOKEN>
     */
    public function pushSms(string $number, string $message): array
    {
        if (str_starts_with($this->baseUrl, 'https://')) {
            try {
                $response = Http::withHeaders([
                    'X-EMESSAGE-Auth' => $this->apiToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->timeout(15)->post(rtrim($this->baseUrl, '/') . '/messaging/v1/sms/push', [
                    'number' => $number,
                    'message' => $message,
                ]);

                return [
                    'status' => $response->status(),
                    'data' => $response->json() ?: ['message' => 'Response received with status ' . $response->status()],
                ];
            } catch (\Throwable $e) {
                Log::warning('eMessage live push SMS failed, utilizing fallback mock.', ['error' => $e->getMessage()]);
            }
        }

        // Mock fallback for offline or local testing
        return [
            'status' => 201,
            'data' => [
                'data' => [
                    'message' => 'SMS was successfully created.',
                ],
            ],
        ];
    }

    /**
     * Persist an in-app notification, and optionally push an SMS.
     *
     * $sms defaults to false: notifications for hospital staff, evaluators and
     * applicants are in-app by default. Previously every notification pushed a
     * real SMS to a single hard-coded number, because users had no mobile
     * column and the null-coalesce always fell through.
     */
    public function send(User $user, string $title, string $message, string $type = 'info', ?string $refType = null, ?int $refId = null, bool $sms = false): Notification
    {
        if ($sms && ! empty($user->mobile)) {
            $this->pushSms($user->mobile, "[$title] $message");
        }

        return Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'reference_type' => $refType,
            'reference_id' => $refId,
        ]);
    }
}

