<?php

namespace App\Services\EGov;

use App\Models\Notification;
use App\Models\User;

class EMessageService
{
    public function send(User $user, string $title, string $message, string $type = 'info', ?string $refType = null, ?int $refId = null): Notification
    {
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
