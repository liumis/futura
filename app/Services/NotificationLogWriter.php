<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\User;

class NotificationLogWriter
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function log(
        User $recipient,
        string $channel,
        string $notificationType,
        string $title,
        string $body,
        ?string $url = null,
        array $metadata = [],
    ): NotificationLog {
        return NotificationLog::query()->create([
            'recipient_user_id' => $recipient->getKey(),
            'channel' => $channel,
            'notification_type' => $notificationType,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
