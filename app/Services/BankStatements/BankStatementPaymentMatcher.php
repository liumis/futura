<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementTransaction;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class BankStatementPaymentMatcher
{
    public const DATE_WINDOW_BEFORE_DAYS = 45;

    public const DATE_WINDOW_AFTER_DAYS = 14;

    /**
     * @param  Collection<int, BankStatementTransaction>  $transactions
     * @return Collection<int, Payment> keyed by transaction id
     */
    public static function suggestFor(Collection $transactions): Collection
    {
        $unlinked = $transactions
            ->filter(fn (BankStatementTransaction $row): bool => ! $row->isResolved())
            ->values();

        if ($unlinked->isEmpty()) {
            return collect();
        }

        $payments = Payment::query()->with('contact')->get();
        if ($payments->isEmpty()) {
            return collect();
        }

        $linkedOneTimeIds = Payment::query()
            ->whereIn('id', $payments->modelKeys())
            ->where('schedule', 'one_time')
            ->whereHas('bankStatementTransactions')
            ->pluck('id')
            ->all();
        $linkedOneTimeIds = array_fill_keys($linkedOneTimeIds, true);

        $pairs = [];

        foreach ($unlinked as $transaction) {
            $ranked = [];

            foreach ($payments as $payment) {
                if ($payment->isOneTime() && isset($linkedOneTimeIds[$payment->getKey()])) {
                    continue;
                }

                $score = self::score($transaction, $payment);
                if ($score === null) {
                    continue;
                }

                $ranked[] = [
                    'payment' => $payment,
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
                'payment' => $best['payment'],
                'score' => $best['score'],
            ];
        }

        usort($pairs, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $suggestions = collect();
        $usedOneTime = [];

        foreach ($pairs as $pair) {
            /** @var Payment $payment */
            $payment = $pair['payment'];
            $paymentId = (int) $payment->getKey();

            if ($payment->isOneTime() && (isset($usedOneTime[$paymentId]) || $suggestions->has($pair['transaction_id']))) {
                continue;
            }

            if ($suggestions->has($pair['transaction_id'])) {
                continue;
            }

            $suggestions->put($pair['transaction_id'], $payment);
            if ($payment->isOneTime()) {
                $usedOneTime[$paymentId] = true;
            }
        }

        return $suggestions;
    }

    public static function suggestionFor(BankStatementTransaction $transaction): ?Payment
    {
        return self::suggestFor(collect([$transaction]))->get($transaction->getKey());
    }

    public static function link(BankStatementTransaction $transaction, Payment $payment): void
    {
        if ($transaction->invoice_id) {
            throw new RuntimeException('This payment is already linked to an invoice.');
        }

        if ($transaction->payment_id) {
            throw new RuntimeException('This payment is already linked to a contract.');
        }

        if ($payment->isOneTime() && $payment->bankStatementTransactions()->exists()) {
            throw new RuntimeException('This contract is already linked to a bank payment.');
        }

        $transaction->update([
            'payment_id' => $payment->getKey(),
            'invoice_id' => null,
            'employee_monthly_payment_id' => null,
            'document_id' => null,
            'business_trip_request_id' => null,
        ]);

        if ($payment->isOneTime() && ! $payment->paid) {
            $payment->update(['paid' => true]);
        }
    }

    public static function unlink(BankStatementTransaction $transaction): void
    {
        if (! $transaction->payment_id) {
            return;
        }

        $payment = $transaction->payment ?? Payment::query()->find($transaction->payment_id);

        $transaction->update([
            'payment_id' => null,
        ]);

        if ($payment instanceof Payment && $payment->isOneTime() && ! $payment->bankStatementTransactions()->whereKeyNot($transaction->getKey())->exists()) {
            $payment->update(['paid' => false]);
        }
    }

    public static function pickerLabel(Payment $payment): string
    {
        $payment->loadMissing('contact');

        $schedule = $payment->isPeriodical()
            ? ($payment->frequency?->label() ?: 'Periodical')
            : optional($payment->due_date)->format('Y-m-d');

        return implode(' · ', array_filter([
            $payment->name,
            $schedule,
            $payment->contact?->company_name,
            Money::format((float) $payment->amount),
        ], fn (mixed $value): bool => filled($value)));
    }

    /**
     * @return array<int, string>
     */
    public static function searchPayments(string $search, int $limit = 40): array
    {
        $search = trim($search);
        $query = Payment::query()->with('contact')->orderBy('name')->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($builder) use ($like, $search): void {
                $builder
                    ->where('name', 'like', $like)
                    ->orWhere('id', $search)
                    ->orWhereHas('contact', function ($contactQuery) use ($like): void {
                        $contactQuery->where('company_name', 'like', $like);
                    });
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Payment $payment): array => [
                $payment->getKey() => self::pickerLabel($payment),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function pickerOptionsFor(BankStatementTransaction $transaction): array
    {
        $amount = round((float) $transaction->amount, 2);

        $options = Payment::query()
            ->with('contact')
            ->whereRaw('ABS(amount - ?) < 0.005', [$amount])
            ->orderBy('name')
            ->limit(40)
            ->get();

        $suggested = self::suggestionFor($transaction);
        if ($suggested !== null && ! $options->contains(fn (Payment $payment): bool => (int) $payment->getKey() === (int) $suggested->getKey())) {
            $options->prepend($suggested);
        }

        return $options
            ->mapWithKeys(fn (Payment $payment): array => [
                $payment->getKey() => self::pickerLabel($payment),
            ])
            ->all();
    }

    public static function closestOccurrence(BankStatementTransaction $transaction, Payment $payment): ?Carbon
    {
        $booking = $transaction->booking_date;
        if ($booking === null) {
            return $payment->due_date;
        }

        $from = $booking->copy()->subDays(self::DATE_WINDOW_BEFORE_DAYS);
        $until = $booking->copy()->addDays(self::DATE_WINDOW_AFTER_DAYS);
        $dates = $payment->occurrences($from, $until);

        if ($dates === []) {
            return $payment->isOneTime() ? $payment->due_date : $payment->nextDueDate($booking);
        }

        $closest = $dates[0];
        $best = (int) abs($booking->diffInDays($closest));

        foreach ($dates as $date) {
            $distance = (int) abs($booking->diffInDays($date));
            if ($distance < $best) {
                $closest = $date;
                $best = $distance;
            }
        }

        return $closest;
    }

    private static function score(BankStatementTransaction $transaction, Payment $payment): ?int
    {
        $amount = round((float) $transaction->amount, 2);
        $paymentAmount = round((float) $payment->amount, 2);
        $amountMatches = abs($amount - $paymentAmount) < 0.005;
        $nameInText = self::nameAppears($payment, self::haystack($transaction));
        $contactMatches = self::contactMatches($transaction, $payment);
        $days = self::dateDistanceDays($transaction, $payment);

        if (! $amountMatches && ! $nameInText) {
            return null;
        }

        $score = 0;

        if ($amountMatches) {
            $score += 50;
        }

        if ($nameInText) {
            $score += 40;
        }

        if ($contactMatches) {
            $score += 20;
        }

        if ($days !== null) {
            $score += match (true) {
                $days <= 3 => 15,
                $days <= 7 => 10,
                $days <= 14 => 8,
                $days <= 45 => 3,
                default => 0,
            };
        }

        $hasSupport = $nameInText || $contactMatches || ($days !== null && $days <= 7);
        if ($amountMatches && ! $hasSupport) {
            return null;
        }

        if (! $amountMatches && (! $nameInText || mb_strlen(trim((string) $payment->name)) < 5)) {
            return null;
        }

        return $score >= 60 ? $score : null;
    }

    private static function haystack(BankStatementTransaction $transaction): string
    {
        return trim(implode(' ', array_filter([
            $transaction->description,
            $transaction->comment,
            $transaction->end_to_end_id,
            $transaction->account_servicer_ref,
        ], fn (mixed $value): bool => filled($value))));
    }

    private static function nameAppears(Payment $payment, string $haystack): bool
    {
        $name = trim((string) ($payment->name ?? ''));
        if ($name === '' || mb_strlen($name) < 3 || $haystack === '') {
            return false;
        }

        return Str::contains(Str::lower($haystack), Str::lower($name));
    }

    private static function contactMatches(BankStatementTransaction $transaction, Payment $payment): bool
    {
        $counterpart = trim((string) ($transaction->counterpart_name ?? ''));
        $company = trim((string) ($payment->contact?->company_name ?? ''));

        if ($counterpart === '' || $company === '') {
            return false;
        }

        $left = Str::lower($counterpart);
        $right = Str::lower($company);

        return $left === $right
            || Str::contains($left, $right)
            || Str::contains($right, $left);
    }

    private static function dateDistanceDays(BankStatementTransaction $transaction, Payment $payment): ?int
    {
        $occurrence = self::closestOccurrence($transaction, $payment);
        if ($transaction->booking_date === null || $occurrence === null) {
            return null;
        }

        return (int) abs($transaction->booking_date->diffInDays($occurrence));
    }
}
