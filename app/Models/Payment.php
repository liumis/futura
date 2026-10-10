<?php

namespace App\Models;

use App\Enums\PaymentFrequency;
use App\Enums\PaymentSchedule;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'contact_id',
        'amount',
        'schedule',
        'frequency',
        'due_date',
        'start_date',
        'end_date',
        'paid',
        'documents',
        'notes',
        'uploaded_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount' => 0,
        'paid' => false,
        'schedule' => 'one_time',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'schedule' => PaymentSchedule::class,
            'frequency' => PaymentFrequency::class,
            'due_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'paid' => 'boolean',
            'documents' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function uploadedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function bankStatementTransactions(): HasMany
    {
        return $this->hasMany(BankStatementTransaction::class);
    }

    public function isPeriodical(): bool
    {
        return $this->schedule === PaymentSchedule::Periodical;
    }

    public function isOneTime(): bool
    {
        return $this->schedule === PaymentSchedule::OneTime;
    }

    /**
     * @return list<string>
     */
    public function documentPaths(): array
    {
        if (! is_array($this->documents)) {
            return [];
        }

        return array_values(array_filter(
            $this->documents,
            fn ($path): bool => is_string($path) && $path !== '',
        ));
    }

    public function documentCount(): int
    {
        return count($this->documentPaths());
    }

    public function firstDocumentUrl(): ?string
    {
        $path = $this->documentPaths()[0] ?? null;
        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function nextDueDate(?Carbon $from = null): ?Carbon
    {
        if ($this->isOneTime()) {
            return $this->due_date;
        }

        if ($this->start_date === null || $this->frequency === null) {
            return null;
        }

        $from = ($from ?? now())->copy()->startOfDay();
        $cursor = $this->start_date->copy()->startOfDay();
        $end = $this->end_date?->copy()->startOfDay();
        $guard = 0;

        while ($cursor->lt($from) && $guard < 600) {
            $cursor = $this->frequency->advance($cursor);
            $guard++;
        }

        if ($end !== null && $cursor->gt($end)) {
            return null;
        }

        return $cursor;
    }

    /**
     * @return list<Carbon>
     */
    public function occurrences(?Carbon $from = null, ?Carbon $until = null, int $limit = 240): array
    {
        $fromDay = $from?->copy()->startOfDay();
        $untilDay = $until?->copy()->startOfDay();

        if ($this->isOneTime()) {
            if ($this->due_date === null) {
                return [];
            }

            $due = $this->due_date->copy()->startOfDay();
            if ($fromDay !== null && $due->lt($fromDay)) {
                return [];
            }
            if ($untilDay !== null && $due->gt($untilDay)) {
                return [];
            }

            return [$due];
        }

        if ($this->start_date === null || $this->frequency === null) {
            return [];
        }

        $cursor = $this->start_date->copy()->startOfDay();
        $end = $this->end_date?->copy()->startOfDay();
        $dates = [];
        $guard = 0;

        while ($guard < $limit) {
            if ($end !== null && $cursor->gt($end)) {
                break;
            }
            if ($untilDay !== null && $cursor->gt($untilDay)) {
                break;
            }
            if ($fromDay === null || $cursor->gte($fromDay)) {
                $dates[] = $cursor->copy();
            }

            $cursor = $this->frequency->advance($cursor);
            $guard++;
        }

        return $dates;
    }

    public function isOverdue(mixed $asOf = null): bool
    {
        if (! $this->isOneTime() || (bool) $this->paid || $this->due_date === null) {
            return false;
        }

        $asOfDate = $asOf instanceof Carbon
            ? $asOf->copy()->startOfDay()
            : now()->copy()->startOfDay();

        return $this->due_date->lt($asOfDate);
    }
}
