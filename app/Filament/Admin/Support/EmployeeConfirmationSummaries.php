<?php

namespace App\Filament\Admin\Support;

use App\Models\DocumentConfirmation;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class EmployeeConfirmationSummaries
{
    /**
     * @return list<array{
     *     document_id: int,
     *     document_name: string,
     *     type_name: string|null,
     *     status: 'valid'|'expired'|'pending',
     *     status_label: string,
     *     confirmed_at: string|null,
     *     due_at: string|null,
     *     detail: string
     * }>
     */
    public static function forEmployee(Employee $employee, ?CarbonInterface $today = null): array
    {
        $user = $employee->relationLoaded('user')
            ? $employee->user
            : $employee->user()->first();

        if ($user === null) {
            return [];
        }

        return self::forUser($user, $today);
    }

    /**
     * @return list<array{
     *     document_id: int,
     *     document_name: string,
     *     type_name: string|null,
     *     status: 'valid'|'expired'|'pending',
     *     status_label: string,
     *     confirmed_at: string|null,
     *     due_at: string|null,
     *     detail: string
     * }>
     */
    public static function forUser(User $user, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $confirmations = DocumentConfirmation::query()
            ->where('user_id', $user->getKey())
            ->with(['document.documentType'])
            ->orderBy('document_id')
            ->orderByDesc('cycle_index')
            ->get()
            ->filter(fn (DocumentConfirmation $row): bool => ((int) ($row->document?->documentType?->periodical_signature_months ?? 0)) > 0);

        /** @var Collection<int, Collection<int, DocumentConfirmation>> $byDocument */
        $byDocument = $confirmations->groupBy('document_id');

        $rows = [];

        foreach ($byDocument as $documentId => $cycles) {
            /** @var Collection<int, DocumentConfirmation> $cycles */
            $document = $cycles->first()?->document;

            if ($document === null) {
                continue;
            }

            $open = $cycles->first(fn (DocumentConfirmation $c): bool => $c->isPending());
            $lastConfirmed = $cycles->first(fn (DocumentConfirmation $c): bool => $c->isConfirmed());

            $dueAt = $open?->due_at;
            $confirmedAt = $lastConfirmed?->confirmed_at;

            if ($open === null) {
                // No open cycle: treat last confirmation as valid with unknown next due.
                $status = $lastConfirmed !== null ? 'valid' : 'pending';
                $statusLabel = $status === 'valid' ? 'Valid' : 'Pending';
                $detail = $confirmedAt !== null
                    ? 'Confirmed '.$confirmedAt->toDateString()
                    : 'Not confirmed';
            } elseif ($lastConfirmed === null) {
                $status = $dueAt !== null && $dueAt->copy()->startOfDay()->lte($today)
                    ? 'expired'
                    : 'pending';
                $statusLabel = $status === 'expired' ? 'Expired' : 'Pending';
                $detail = $dueAt !== null
                    ? ($status === 'expired' ? 'Due '.$dueAt->toDateString() : 'Due '.$dueAt->toDateString())
                    : 'Not confirmed';
            } elseif ($dueAt !== null && $dueAt->copy()->startOfDay()->gt($today)) {
                $status = 'valid';
                $statusLabel = 'Valid';
                $detail = 'Until '.$dueAt->toDateString();
            } else {
                $status = 'expired';
                $statusLabel = 'Expired';
                $detail = $dueAt !== null
                    ? 'Due '.$dueAt->toDateString()
                    : 'Needs renew';
            }

            $rows[] = [
                'document_id' => (int) $document->getKey(),
                'document_name' => (string) $document->name,
                'type_name' => $document->documentType?->name,
                'status' => $status,
                'status_label' => $statusLabel,
                'confirmed_at' => $confirmedAt?->toDateString(),
                'due_at' => $dueAt?->toDateString(),
                'detail' => $detail,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $order = ['expired' => 0, 'pending' => 1, 'valid' => 2];

            return ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9)
                ?: strcasecmp($a['document_name'], $b['document_name']);
        });

        return $rows;
    }
}
