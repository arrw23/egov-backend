<?php

namespace App\Services\EGov;

use App\Models\User;

class EReportService
{
    protected string $accessToken;

    public function __construct()
    {
        $this->accessToken = config('services.egov.report.access_token', 'ce8691fdca4e8365f2c9ec6279e3558c7e9b6387b0c5e986147e436aebeb4705');
    }

    public function submitAuditReport(string $action, array $payload, ?User $actor = null): array
    {
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
