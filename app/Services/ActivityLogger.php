<?php

namespace App\Services;

use App\Enums\ActivityLogEvent;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ActivityLogger
{
    /** Retention period for activity logs. */
    public const RETENTION_YEARS = 3;

    /**
     * @var list<string>
     */
    protected const SENSITIVE_ATTRIBUTES = [
        'password',
        'password_confirmation',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
        'token',
        'file_content',
        'pdf_content',
    ];

    public static function log(
        ActivityLogEvent|string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?Request $request = null,
        ?int $userId = null,
        bool $allowWithoutUser = false,
    ): void {
        $resolvedUserId = $userId ?? auth()->id();

        if ($resolvedUserId === null && ! $allowWithoutUser) {
            return;
        }

        $request ??= request();

        $eventKey = $event instanceof ActivityLogEvent ? $event->value : $event;

        ActivityLog::query()->create([
            'user_id' => $resolvedUserId,
            'event_key' => $eventKey,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => $request?->ip(),
            'user_agent' => filled($request?->userAgent())
                ? substr((string) $request->userAgent(), 0, 1000)
                : null,
        ]);
    }

    /**
     * Log Eloquent create / update / delete for any auditable model.
     */
    public static function logModelEvent(Model $model, string $action): void
    {
        $base = Str::snake(class_basename($model));
        $eventKey = $base.'.'.$action;
        $label = Str::headline(class_basename($model));
        $id = $model->getKey();

        $properties = [];

        if ($action === 'created') {
            $properties['attributes'] = self::safeAttributes($model->getAttributes());
        } elseif ($action === 'updated') {
            $changes = self::safeAttributes($model->getChanges());
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $properties['changes'] = $changes;
        } elseif ($action === 'deleted') {
            $properties['deleted_id'] = $id;
            $properties['attributes'] = self::safeAttributes($model->getAttributes());
        }

        $description = match ($action) {
            'created' => "{$label} #{$id} created",
            'updated' => "{$label} #{$id} updated",
            'deleted' => "{$label} #{$id} deleted",
            default => "{$label} #{$id} {$action}",
        };

        self::log(
            $eventKey,
            $description,
            $action === 'deleted' ? null : $model,
            $properties,
        );
    }

    /**
     * Log report / export / file generation.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function logReportGenerated(
        string $reportName,
        string $format,
        ?Model $subject = null,
        array $properties = [],
    ): void {
        self::log(
            ActivityLogEvent::ReportGenerated,
            'Report generated: '.$reportName.' ('.$format.')',
            $subject,
            array_merge([
                'report' => $reportName,
                'format' => $format,
                'action' => 'generated',
            ], $properties),
        );
    }

    /**
     * Log report / export / file download or inline view.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function logReportDownloaded(
        string $reportName,
        string $format,
        ?Model $subject = null,
        array $properties = [],
    ): void {
        self::log(
            ActivityLogEvent::ReportDownloaded,
            'Report downloaded: '.$reportName.' ('.$format.')',
            $subject,
            array_merge([
                'report' => $reportName,
                'format' => $format,
                'action' => 'downloaded',
            ], $properties),
        );
    }

    /**
     * Login events: authenticated user, no auth()->id() guard issues — use listener.
     */
    public static function logLogin(Model $user, ?Request $request = null): void
    {
        $request ??= request();

        ActivityLog::query()->create([
            'user_id' => $user->getKey(),
            'event_key' => ActivityLogEvent::AuthLogin->value,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->getKey(),
            'description' => 'User logged in: '.$user->email,
            'properties' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => filled($request?->userAgent())
                ? substr((string) $request->userAgent(), 0, 1000)
                : null,
        ]);
    }

    /**
     * Failed login events: can happen without authenticated session.
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function logFailedLogin(?Model $user, array $credentials = [], ?Request $request = null): void
    {
        $request ??= request();

        $identifier = (string) ($credentials['email'] ?? $credentials['name'] ?? $credentials['login'] ?? 'unknown');

        ActivityLog::query()->create([
            'user_id' => $user?->getKey(),
            'event_key' => ActivityLogEvent::AuthLoginFailed->value,
            'subject_type' => $user?->getMorphClass(),
            'subject_id' => $user?->getKey(),
            'description' => 'Failed login attempt: '.$identifier,
            'properties' => [
                'identifier' => $identifier,
            ],
            'ip_address' => $request?->ip(),
            'user_agent' => filled($request?->userAgent())
                ? substr((string) $request->userAgent(), 0, 1000)
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected static function safeAttributes(array $attributes): array
    {
        foreach (self::SENSITIVE_ATTRIBUTES as $key) {
            unset($attributes[$key]);
        }

        foreach ($attributes as $key => $value) {
            if (is_string($value) && strlen($value) > 2000) {
                $attributes[$key] = '[omitted: '.strlen($value).' bytes]';
            }
        }

        return $attributes;
    }
}
