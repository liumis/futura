<?php

namespace App\Services\BankStatements;

use App\Enums\BusinessTripRequestStatus;
use App\Models\BankStatementTransaction;
use App\Models\BusinessTripRequest;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class BankStatementBusinessTripMatcher
{
    public const DATE_WINDOW_BEFORE_DAYS = 7;

    public const DATE_WINDOW_AFTER_DAYS = 60;

    public static function isBusinessTripTransfer(BankStatementTransaction $transaction): bool
    {
        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->comment,
        ]))));

        return str_contains($haystack, 'dienpinigiai')
            || str_contains($haystack, 'komandiruot');
    }

    /**
     * @param  Collection<int, BankStatementTransaction>  $transactions
     * @return Collection<int, BusinessTripRequest> keyed by transaction id
     */
    public static function suggestFor(Collection $transactions): Collection
    {
        $unlinked = $transactions
            ->filter(fn (BankStatementTransaction $row): bool => ! $row->isResolved())
            ->values();

        if ($unlinked->isEmpty()) {
            return collect();
        }

        $trips = BusinessTripRequest::query()
            ->with(['employee', 'bankStatementTransactions'])
            ->where('status', '!=', BusinessTripRequestStatus::Canceled->value)
            ->orderByDesc('date_to')
            ->limit(500)
            ->get();

        if ($trips->isEmpty()) {
            return collect();
        }

        $pairs = [];

        foreach ($unlinked as $transaction) {
            $ranked = [];

            foreach ($trips as $trip) {
                $score = self::score($transaction, $trip);
                if ($score === null) {
                    continue;
                }

                $ranked[] = [
                    'trip' => $trip,
                    'score' => $score,
                ];
            }

            usort($ranked, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

            if ($ranked === []) {
                continue;
            }

            $best = $ranked[0];
            $runnerUp = $ranked[1] ?? null;
            if ($runnerUp !== null && $runnerUp['score'] >= $best['score']) {
                continue;
            }

            $pairs[] = [
                'transaction_id' => (int) $transaction->getKey(),
                'trip' => $best['trip'],
                'score' => $best['score'],
            ];
        }

        usort($pairs, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $suggestions = collect();
        $usedTrips = [];

        foreach ($pairs as $pair) {
            $tripId = (int) $pair['trip']->getKey();
            if ($suggestions->has($pair['transaction_id'])) {
                continue;
            }

            // One suggestion pass: do not assign the same remaining balance twice.
            if (isset($usedTrips[$tripId])) {
                continue;
            }

            $suggestions->put($pair['transaction_id'], $pair['trip']);
            $usedTrips[$tripId] = true;
        }

        return $suggestions;
    }

    public static function suggestionFor(BankStatementTransaction $transaction): ?BusinessTripRequest
    {
        return self::suggestFor(collect([$transaction]))->get($transaction->getKey());
    }

    public static function link(BankStatementTransaction $transaction, BusinessTripRequest $trip): void
    {
        if ($transaction->isResolved() && (int) $transaction->business_trip_request_id !== (int) $trip->getKey()) {
            if ($transaction->invoice_id || $transaction->invoices()->exists()) {
                throw new RuntimeException('This payment is already linked to an invoice.');
            }
            if ($transaction->payment_id) {
                throw new RuntimeException('This payment is already linked to a contract.');
            }
            if ($transaction->employee_monthly_payment_id) {
                throw new RuntimeException('This payment is already linked to a salary payment.');
            }
            if ($transaction->document_id) {
                throw new RuntimeException('This payment is already linked to a document.');
            }
            if ($transaction->business_trip_request_id) {
                throw new RuntimeException('This payment is already linked to a business trip.');
            }
        }

        if ($trip->status === BusinessTripRequestStatus::Canceled) {
            throw new RuntimeException('Canceled business trips cannot be linked.');
        }

        if ($transaction->invoice_id || $transaction->invoices()->exists()) {
            BankStatementInvoiceMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($transaction->payment_id) {
            BankStatementPaymentMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($transaction->employee_monthly_payment_id) {
            BankStatementSalaryMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($transaction->document_id) {
            BankStatementDocumentMatcher::unlink($transaction);
            $transaction->refresh();
        }

        $transaction->forceFill([
            'business_trip_request_id' => $trip->getKey(),
            'invoice_id' => null,
            'payment_id' => null,
            'employee_monthly_payment_id' => null,
            'document_id' => null,
        ])->save();

        $trip->unsetRelation('bankStatementTransactions');
        $trip->syncPaymentStatusFromBankLinks();
    }

    public static function unlink(BankStatementTransaction $transaction): void
    {
        if (! $transaction->business_trip_request_id) {
            return;
        }

        $trip = $transaction->businessTripRequest
            ?? BusinessTripRequest::query()->find($transaction->business_trip_request_id);

        $transaction->update([
            'business_trip_request_id' => null,
        ]);

        if ($trip instanceof BusinessTripRequest) {
            $trip->unsetRelation('bankStatementTransactions');
            $trip->syncPaymentStatusFromBankLinks();
        }
    }

    public static function pickerLabel(BusinessTripRequest $trip): string
    {
        $trip->loadMissing(['employee', 'bankStatementTransactions']);
        $summary = $trip->settlementSummary();

        return implode(' · ', array_filter([
            $trip->employee?->fullName(),
            $trip->country_name,
            optional($trip->date_from)->format('Y-m-d')
                .(optional($trip->date_to)->format('Y-m-d') !== optional($trip->date_from)->format('Y-m-d')
                    ? '–'.optional($trip->date_to)->format('Y-m-d')
                    : ''),
            Money::format($summary['expected_total']),
            $summary['label'],
            $summary['detail'],
        ], fn (mixed $value): bool => filled($value)));
    }

    /**
     * @return array<int, string>
     */
    public static function searchTrips(string $search, int $limit = 40): array
    {
        $search = trim($search);
        $query = BusinessTripRequest::query()
            ->with('employee')
            ->where('status', '!=', BusinessTripRequestStatus::Canceled->value)
            ->orderByDesc('date_to')
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($builder) use ($like, $search): void {
                $builder
                    ->where('country_name', 'like', $like)
                    ->orWhere('purpose', 'like', $like)
                    ->orWhere('id', $search)
                    ->orWhereHas('employee', function ($employeeQuery) use ($like): void {
                        $employeeQuery
                            ->where('name', 'like', $like)
                            ->orWhere('surname', 'like', $like);
                    });
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (BusinessTripRequest $trip): array => [
                $trip->getKey() => self::pickerLabel($trip),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function pickerOptionsFor(?BankStatementTransaction $transaction = null, int $limit = 40): array
    {
        if ($transaction instanceof BankStatementTransaction) {
            $suggested = self::suggestionFor($transaction);
            if ($suggested instanceof BusinessTripRequest) {
                return [
                    $suggested->getKey() => self::pickerLabel($suggested),
                ];
            }
        }

        return self::searchTrips('', $limit);
    }

    private static function score(BankStatementTransaction $transaction, BusinessTripRequest $trip): ?int
    {
        $bankAmount = round(abs((float) $transaction->amount), 2);
        $tripAmount = $trip->expectedTotal();
        if ($bankAmount < 0.005 || $tripAmount < 0.005) {
            return null;
        }

        $linkedOther = self::linkedAmountExcluding($trip, $transaction);
        $remaining = round($tripAmount - $linkedOther, 2);
        if ($remaining < 0.005) {
            return null;
        }

        if (abs($bankAmount - $remaining) >= 0.02) {
            return null;
        }

        $booking = $transaction->booking_date instanceof Carbon
            ? $transaction->booking_date->copy()
            : Carbon::parse((string) $transaction->booking_date);

        $from = $trip->date_from?->copy();
        $to = $trip->date_to?->copy();
        if ($from === null || $to === null) {
            return null;
        }

        $windowStart = $from->copy()->subDays(self::DATE_WINDOW_BEFORE_DAYS);
        $windowEnd = $to->copy()->addDays(self::DATE_WINDOW_AFTER_DAYS);
        if ($booking->lt($windowStart) || $booking->gt($windowEnd)) {
            return null;
        }

        $score = 70;
        if ($linkedOther > 0.009) {
            $score += 5;
        }

        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->comment,
        ]))));

        if (str_contains($haystack, 'dienpinigiai') || str_contains($haystack, 'komandiruot')) {
            $score += 15;
        }

        $country = mb_strtolower(trim((string) $trip->country_name));
        if ($country !== '' && str_contains($haystack, $country)) {
            $score += 10;
        }

        $employeeName = mb_strtolower(trim((string) ($trip->employee?->fullName() ?? '')));
        if ($employeeName !== '') {
            $parts = preg_split('/\s+/u', $employeeName) ?: [];
            $hits = 0;
            foreach ($parts as $part) {
                if (mb_strlen($part) >= 3 && str_contains($haystack, $part)) {
                    $hits++;
                }
            }
            if ($hits > 0) {
                $score += min(15, $hits * 8);
            }
        }

        $daysFromEnd = abs($booking->diffInDays($to, false));
        if ($daysFromEnd <= 14) {
            $score += 5;
        }

        return $score >= 70 ? $score : null;
    }

    private static function linkedAmountExcluding(BusinessTripRequest $trip, BankStatementTransaction $transaction): float
    {
        $trip->loadMissing('bankStatementTransactions');

        return round((float) $trip->bankStatementTransactions
            ->reject(fn (BankStatementTransaction $row): bool => (int) $row->getKey() === (int) $transaction->getKey())
            ->sum(fn (BankStatementTransaction $row): float => abs((float) $row->amount)), 2);
    }
}
