<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementTransaction;
use App\Models\CompanySetting;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class BankStatementMissingDocumentsPdfGenerator
{
    /**
     * @param  Collection<int, BankStatementTransaction>|iterable<int, BankStatementTransaction>  $transactions
     */
    public static function generate(iterable $transactions): string
    {
        return Pdf::loadView('pdf.bank-statement-missing-documents', self::build($transactions))
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * @param  Collection<int, BankStatementTransaction>|iterable<int, BankStatementTransaction>  $transactions
     * @return array{
     *     title: string,
     *     subtitle: string,
     *     generated_at: string,
     *     company_name: string,
     *     rows: list<array<string, string>>,
     *     count: int,
     *     income_total: string,
     *     outcome_total: string
     * }
     */
    public static function build(iterable $transactions): array
    {
        $rows = Collection::make($transactions)
            ->map(function (BankStatementTransaction $transaction): array {
                $direction = $transaction->direction?->label() ?? '—';
                $income = $transaction->direction?->isIncome()
                    ? Money::format((float) $transaction->amount)
                    : '';
                $outcome = ! ($transaction->direction?->isIncome() ?? false)
                    ? Money::format((float) $transaction->amount)
                    : '';

                return [
                    'date' => $transaction->booking_date?->format('Y-m-d') ?? '—',
                    'from' => $transaction->partyLine($transaction->fromParty()),
                    'to' => $transaction->partyLine($transaction->toParty()),
                    'description' => (string) ($transaction->description ?: '—'),
                    'direction' => $direction,
                    'income' => $income,
                    'outcome' => $outcome,
                ];
            })
            ->values()
            ->all();

        $incomeTotal = Collection::make($transactions)
            ->filter(fn (BankStatementTransaction $row): bool => (bool) $row->direction?->isIncome())
            ->sum(fn (BankStatementTransaction $row): float => (float) $row->amount);

        $outcomeTotal = Collection::make($transactions)
            ->reject(fn (BankStatementTransaction $row): bool => (bool) $row->direction?->isIncome())
            ->sum(fn (BankStatementTransaction $row): float => (float) $row->amount);

        $company = CompanySetting::query()->first();

        return [
            'title' => 'Bank statements — missing documents',
            'subtitle' => 'Lines that still need an invoice or contract',
            'generated_at' => now()->format('Y-m-d H:i'),
            'company_name' => (string) ($company?->company_name ?: config('app.name')),
            'rows' => $rows,
            'count' => count($rows),
            'income_total' => Money::format((float) $incomeTotal),
            'outcome_total' => Money::format((float) $outcomeTotal),
        ];
    }
}
