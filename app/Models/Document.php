<?php

namespace App\Models;

use App\Enums\DocumentSigningStatus;
use App\Enums\EmployeeContractSigningStatus;
use App\Services\DocumentBinaryStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use SoftDeletes;
    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_date',
        'name',
        'document_type_id',
        'project_id',
        'file_path',
        'user_uploaded_id',
        'flag_approved',
        'user_approved_id',
        'approval_date',
        'confirmed_ip',
        'confirmed_user_agent',
        'content_hash',
        'pdf_hash',
        'approved_file_path',
        'sharepoint_web_url',
        'sharepoint_item_id',
        'sharepoint_path',
        'sharepoint_files',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_date' => 'datetime',
            'flag_approved' => 'boolean',
            'approval_date' => 'datetime',
            'sharepoint_files' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uploaded_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_approved_id');
    }

    public function paymentReport(): HasOne
    {
        return $this->hasOne(EmployeePaymentReport::class);
    }

    public function dividendPaymentReport(): HasOne
    {
        return $this->hasOne(DividendPaymentReport::class);
    }

    public function contractSigning(): HasOne
    {
        return $this->hasOne(EmployeeContractSigning::class);
    }

    public function documentSigning(): HasOne
    {
        return $this->hasOne(DocumentSigning::class)->latestOfMany();
    }

    public function employeeContract(): HasOne
    {
        return $this->hasOne(EmployeeContract::class);
    }

    public function approvers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_approvers')
            ->using(DocumentApprover::class)
            ->withPivot(['approved_at', 'is_auto_approved'])
            ->withTimestamps();
    }

    public function pendingApprovers(): BelongsToMany
    {
        return $this->approvers()->wherePivotNull('approved_at');
    }

    public function activeDokobitSigning(): EmployeeContractSigning|DocumentSigning|null
    {
        if ($this->contractSigning !== null && $this->contractSigning->isPending()) {
            return $this->contractSigning;
        }

        if ($this->documentSigning !== null && $this->documentSigning->isPending()) {
            return $this->documentSigning;
        }

        return null;
    }

    public function isApproved(): bool
    {
        return (bool) $this->flag_approved;
    }

    public function isLocked(): bool
    {
        return $this->isApproved();
    }

    public function hasCompletedSignature(): bool
    {
        if ($this->documentSigning?->isCompleted()) {
            return true;
        }

        if ($this->contractSigning?->isCompleted()) {
            return true;
        }

        return false;
    }

    public function scopeSigned(Builder $query): Builder
    {
        $documentsTable = $query->getModel()->getTable();

        return $query->where(function (Builder $signed) use ($documentsTable): void {
            $signed
                ->whereExists(function ($sub) use ($documentsTable): void {
                    $sub->selectRaw('1')
                        ->from('document_signings')
                        ->whereColumn('document_signings.document_id', $documentsTable.'.id')
                        ->where('document_signings.status', DocumentSigningStatus::Completed->value);
                })
                ->orWhereExists(function ($sub) use ($documentsTable): void {
                    $sub->selectRaw('1')
                        ->from('employee_contract_signings')
                        ->whereColumn('employee_contract_signings.document_id', $documentsTable.'.id')
                        ->where('employee_contract_signings.status', EmployeeContractSigningStatus::Completed->value);
                });
        });
    }

    public function scopeNotSigned(Builder $query): Builder
    {
        $documentsTable = $query->getModel()->getTable();

        return $query
            ->whereNotExists(function ($sub) use ($documentsTable): void {
                $sub->selectRaw('1')
                    ->from('document_signings')
                    ->whereColumn('document_signings.document_id', $documentsTable.'.id')
                    ->where('document_signings.status', DocumentSigningStatus::Completed->value);
            })
            ->whereNotExists(function ($sub) use ($documentsTable): void {
                $sub->selectRaw('1')
                    ->from('employee_contract_signings')
                    ->whereColumn('employee_contract_signings.document_id', $documentsTable.'.id')
                    ->where('employee_contract_signings.status', EmployeeContractSigningStatus::Completed->value);
            });
    }

    public function scopeWithFiles(Builder $query): Builder
    {
        return $query->where(function (Builder $files): void {
            $files
                ->where(function (Builder $json): void {
                    $json->whereNotNull('sharepoint_files')
                        ->where('sharepoint_files', '!=', '[]')
                        ->where('sharepoint_files', '!=', 'null');
                })
                ->orWhere(function (Builder $item): void {
                    $item->whereNotNull('sharepoint_item_id')
                        ->where('sharepoint_item_id', '!=', '');
                })
                ->orWhere(function (Builder $path): void {
                    $path->whereNotNull('file_path')
                        ->where('file_path', '!=', '');
                })
                ->orWhere(function (Builder $approved): void {
                    $approved->whereNotNull('approved_file_path')
                        ->where('approved_file_path', '!=', '');
                });
        });
    }

    public function scopeWithoutFiles(Builder $query): Builder
    {
        return $query->where(function (Builder $files): void {
            $files
                ->where(function (Builder $json): void {
                    $json->whereNull('sharepoint_files')
                        ->orWhere('sharepoint_files', '=', '[]')
                        ->orWhere('sharepoint_files', '=', 'null')
                        ->orWhere('sharepoint_files', '=', '');
                })
                ->where(function (Builder $item): void {
                    $item->whereNull('sharepoint_item_id')
                        ->orWhere('sharepoint_item_id', '=', '');
                })
                ->where(function (Builder $path): void {
                    $path->whereNull('file_path')
                        ->orWhere('file_path', '=', '');
                })
                ->where(function (Builder $approved): void {
                    $approved->whereNull('approved_file_path')
                        ->orWhere('approved_file_path', '=', '');
                });
        });
    }

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query
            ->where('flag_approved', false)
            ->where(function (Builder $pending): void {
                $pending
                    ->whereHas('approvers', fn (Builder $approvers): Builder => $approvers->wherePivotNull('approved_at'))
                    ->orWhereHas('paymentReport.pendingApprovers')
                    ->orWhereHas('dividendPaymentReport.pendingApprovers');
            });
    }

    public function scopeNotPendingApproval(Builder $query): Builder
    {
        return $query->where(function (Builder $notPending): void {
            $notPending
                ->where('flag_approved', true)
                ->orWhere(function (Builder $idle): void {
                    $idle
                        ->whereDoesntHave('approvers', fn (Builder $approvers): Builder => $approvers->wherePivotNull('approved_at'))
                        ->whereDoesntHave('paymentReport.pendingApprovers')
                        ->whereDoesntHave('dividendPaymentReport.pendingApprovers');
                });
        });
    }

    public function scopePendingSigning(Builder $query): Builder
    {
        $documentsTable = $query->getModel()->getTable();

        return $query
            ->where('flag_approved', false)
            ->where(function (Builder $pending) use ($documentsTable): void {
                $pending
                    ->whereExists(function ($sub) use ($documentsTable): void {
                        $sub->selectRaw('1')
                            ->from('document_signings')
                            ->whereColumn('document_signings.document_id', $documentsTable.'.id')
                            ->where('document_signings.status', DocumentSigningStatus::Pending->value);
                    })
                    ->orWhereExists(function ($sub) use ($documentsTable): void {
                        $sub->selectRaw('1')
                            ->from('employee_contract_signings')
                            ->whereColumn('employee_contract_signings.document_id', $documentsTable.'.id')
                            ->where('employee_contract_signings.status', EmployeeContractSigningStatus::Pending->value);
                    });
            });
    }

    public function scopeNotPendingSigning(Builder $query): Builder
    {
        $documentsTable = $query->getModel()->getTable();

        return $query
            ->whereNotExists(function ($sub) use ($documentsTable): void {
                $sub->selectRaw('1')
                    ->from('document_signings')
                    ->whereColumn('document_signings.document_id', $documentsTable.'.id')
                    ->where('document_signings.status', DocumentSigningStatus::Pending->value);
            })
            ->whereNotExists(function ($sub) use ($documentsTable): void {
                $sub->selectRaw('1')
                    ->from('employee_contract_signings')
                    ->whereColumn('employee_contract_signings.document_id', $documentsTable.'.id')
                    ->where('employee_contract_signings.status', EmployeeContractSigningStatus::Pending->value);
            });
    }

    public function canAttachFiles(): bool
    {
        if ($this->trashed() || $this->isApproved() || $this->hasCompletedSignature()) {
            return false;
        }

        if ($this->awaitsDokobitSignature()) {
            return false;
        }

        return true;
    }

    public function hasDocumentApprovers(): bool
    {
        if ($this->relationLoaded('approvers')) {
            return $this->approvers->isNotEmpty();
        }

        return $this->approvers()->exists();
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->where('flag_approved', false);
    }

    public function statusLabel(): string
    {
        if ($this->isApproved()) {
            return 'Approved';
        }

        $pendingApprovers = $this->pendingApproverLabels();
        $dokobit = $this->activeDokobitSigning();

        if ($pendingApprovers !== [] && $dokobit !== null) {
            return 'Awaiting approvals & signatures';
        }

        if ($dokobit !== null) {
            return $dokobit->status?->label() ?? 'Awaiting signatures';
        }

        return $pendingApprovers !== []
            ? 'Awaiting confirmation'
            : 'Awaiting approval / signature';
    }

    /**
     * @return list<string>
     */
    public function pendingApproverLabels(): array
    {
        if ($this->isApproved()) {
            return [];
        }

        $report = $this->paymentReport ?? $this->dividendPaymentReport;
        if ($report !== null) {
            $approvers = $report->relationLoaded('pendingApprovers')
                ? $report->pendingApprovers
                : $report->pendingApprovers()->get();

            return $approvers
                ->map(function (User $user): string {
                    $name = trim($user->fullName());

                    return $name !== '' ? $name : (string) ($user->email ?? 'User #'.$user->getKey());
                })
                ->values()
                ->all();
        }

        $labels = [];
        $hasApprovers = $this->hasDocumentApprovers();
        $signing = $this->activeDokobitSigning();

        if ($hasApprovers) {
            $approvers = $this->relationLoaded('approvers')
                ? $this->approvers->filter(fn (User $user): bool => blank($user->pivot?->approved_at))
                : $this->pendingApprovers()->get();

            foreach ($approvers as $user) {
                $name = trim($user->fullName());
                $label = $name !== '' ? $name : (string) ($user->email ?? 'User #'.$user->getKey());
                $labels[] = $signing !== null ? $label.' (approval)' : $label;
            }
        }

        if ($signing !== null) {
            if (! $signing->relationLoaded('signers')) {
                $signing->load('signers');
            }

            foreach ($signing->signers->filter(fn ($s) => blank($s->signed_at)) as $signer) {
                if ($hasApprovers) {
                    $suffix = $signer->is_external ? ' (Dokobit · external)' : ' (Dokobit)';
                    $labels[] = $signer->displayName().$suffix;
                } elseif ($signer->is_external) {
                    $labels[] = $signer->displayName().' (external)';
                } else {
                    $labels[] = $signer->displayName();
                }
            }
        }

        return array_values(array_unique($labels));
    }

    public function missingApprovalSummary(): string
    {
        if ($this->isApproved()) {
            return '—';
        }

        $labels = $this->pendingApproverLabels();

        if ($labels !== []) {
            return implode(', ', $labels);
        }

        if ($this->activeDokobitSigning() !== null) {
            return 'Dokobit signing';
        }

        return 'Manual approval';
    }

    public function currentUserHasPendingApproval(?int $userId = null): bool
    {
        $userId ??= auth()->id();

        if ($userId === null || $this->isApproved()) {
            return false;
        }

        $report = $this->paymentReport ?? $this->dividendPaymentReport;
        if ($report !== null) {
            return $report->userHasPendingApproval((int) $userId);
        }

        return $this->userHasPendingApproval((int) $userId);
    }

    public function currentUserHasApproved(?int $userId = null): bool
    {
        $userId ??= auth()->id();

        if ($userId === null) {
            return false;
        }

        if ($this->isApproved() && (int) ($this->user_approved_id ?? 0) === (int) $userId) {
            return true;
        }

        $report = $this->paymentReport ?? $this->dividendPaymentReport;
        if ($report !== null) {
            return $report->approvers()
                ->where('users.id', $userId)
                ->wherePivotNotNull('approved_at')
                ->exists();
        }

        if ($this->relationLoaded('approvers')) {
            return $this->approvers->contains(
                fn (User $user): bool => (int) $user->getKey() === (int) $userId
                    && filled($user->pivot?->approved_at),
            );
        }

        return $this->approvers()
            ->where('users.id', $userId)
            ->wherePivotNotNull('approved_at')
            ->exists();
    }

    public function currentUserCanApprove(?int $userId = null): bool
    {
        $userId ??= auth()->id();

        if ($userId === null || $this->currentUserHasApproved($userId)) {
            return false;
        }

        if ($this->currentUserHasPendingApproval($userId)) {
            return true;
        }

        if ($this->isLocked()) {
            return false;
        }

        $report = $this->paymentReport ?? $this->dividendPaymentReport;
        if ($report !== null) {
            return false;
        }

        return true;
    }

    public function currentUserCanSign(?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($this->trashed() || $this->isApproved() || $this->hasCompletedSignature()) {
            return false;
        }

        if ($this->paymentReport !== null || $this->dividendPaymentReport !== null) {
            return false;
        }

        if ($this->awaitsDokobitSignature()) {
            $documentSigning = $this->documentSigning;
            if ($documentSigning?->isPending()) {
                $signer = $documentSigning->signerForUser($user);

                return $signer !== null && ! $signer->is_external && ! $signer->hasSigned();
            }

            $contractSigning = $this->contractSigning;
            if ($contractSigning?->isPending()) {
                $signer = $contractSigning->signerForUser($user);

                return $signer !== null && ! $signer->hasSigned();
            }

            return false;
        }

        return $this->hasStoredFile();
    }

    public function userHasPendingApproval(int $userId): bool
    {
        return $this->approvers()
            ->where('users.id', $userId)
            ->wherePivotNull('approved_at')
            ->exists();
    }

    /**
     * Labels for the Approved column (done + still waiting internal approvals).
     *
     * @return list<string>
     */
    public function approvalColumnLabels(): array
    {
        if ($this->isApproved()) {
            $approver = $this->approvedBy;
            if ($approver !== null) {
                $name = trim($approver->fullName());

                return [$name !== '' ? $name : (string) ($approver->email ?? '—')];
            }

            $done = $this->completedApproverLabels();

            return $done !== [] ? $done : ['—'];
        }

        $labels = [
            ...$this->completedApproverLabels(),
            ...$this->pendingInternalApproverLabels(),
        ];

        return $labels !== [] ? array_values(array_unique($labels)) : [];
    }

    /**
     * @return list<string>
     */
    public function completedApproverLabels(): array
    {
        $report = $this->paymentReport ?? $this->dividendPaymentReport;
        if ($report !== null) {
            $approvers = $report->relationLoaded('approvers')
                ? $report->approvers->filter(fn (User $user): bool => filled($user->pivot?->approved_at))
                : $report->approvers()->wherePivotNotNull('approved_at')->get();

            return $approvers
                ->map(function (User $user): string {
                    $name = trim($user->fullName());

                    return $name !== '' ? $name : (string) ($user->email ?? 'User #'.$user->getKey());
                })
                ->values()
                ->all();
        }

        if (! $this->hasDocumentApprovers()) {
            return [];
        }

        $approvers = $this->relationLoaded('approvers')
            ? $this->approvers->filter(fn (User $user): bool => filled($user->pivot?->approved_at))
            : $this->approvers()->wherePivotNotNull('approved_at')->get();

        return $approvers
            ->map(function (User $user): string {
                $name = trim($user->fullName());

                return $name !== '' ? $name : (string) ($user->email ?? 'User #'.$user->getKey());
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function pendingInternalApproverLabels(): array
    {
        if ($this->isApproved()) {
            return [];
        }

        $report = $this->paymentReport ?? $this->dividendPaymentReport;
        if ($report !== null) {
            $approvers = $report->relationLoaded('pendingApprovers')
                ? $report->pendingApprovers
                : $report->pendingApprovers()->get();

            return $approvers
                ->map(function (User $user): string {
                    $name = trim($user->fullName());

                    return $name !== '' ? $name : (string) ($user->email ?? 'User #'.$user->getKey());
                })
                ->values()
                ->all();
        }

        if (! $this->hasDocumentApprovers()) {
            return [];
        }

        $approvers = $this->relationLoaded('approvers')
            ? $this->approvers->filter(fn (User $user): bool => blank($user->pivot?->approved_at))
            : $this->pendingApprovers()->get();

        return $approvers
            ->map(function (User $user): string {
                $name = trim($user->fullName());

                return $name !== '' ? $name : (string) ($user->email ?? 'User #'.$user->getKey());
            })
            ->values()
            ->all();
    }

    /**
     * Labels for the Signed column (signed + still waiting Dokobit signers).
     *
     * @return list<string>
     */
    public function signedColumnLabels(): array
    {
        $signing = $this->documentSigning ?? $this->contractSigning;

        if ($signing === null) {
            return [];
        }

        if (! $signing->relationLoaded('signers')) {
            $signing->load('signers');
        }

        $labels = [];

        foreach ($signing->signers as $signer) {
            $name = $signer->displayName();
            if ($signer->is_external) {
                $name .= ' (external)';
            }
            if (! $signer->hasSigned()) {
                $name .= ' (pending)';
            }
            $labels[] = $name;
        }

        return array_values(array_unique($labels));
    }

    public function awaitsDokobitSignature(): bool
    {
        if ($this->isApproved()) {
            return false;
        }

        return ($this->contractSigning !== null && $this->contractSigning->isPending())
            || ($this->documentSigning !== null && $this->documentSigning->isPending());
    }

    public function canStartApprovalOrSigningWorkflow(): bool
    {
        if ($this->awaitsDokobitSignature()) {
            return false;
        }

        return $this->paymentReport === null && $this->dividendPaymentReport === null;
    }

    public function canManageApprovalOrSigningWorkflow(): bool
    {
        return $this->paymentReport === null && $this->dividendPaymentReport === null;
    }

    public function displayFilePath(): ?string
    {
        if ($this->isApproved() && filled($this->approved_file_path)) {
            return $this->approved_file_path;
        }

        return $this->file_path;
    }

    public function attachedFilesCount(): int
    {
        return count($this->attachedFileLinks());
    }

    /**
     * SharePoint (or legacy) file links for table/UI display.
     *
     * @return list<array{name: string, url: ?string}>
     */
    public function attachedFileLinks(): array
    {
        $links = [];
        $fallbackUrl = $this->displayFileUrl();

        if (is_array($this->sharepoint_files) && $this->sharepoint_files !== []) {
            foreach ($this->sharepoint_files as $index => $file) {
                if (! is_array($file)) {
                    continue;
                }

                $name = filled($file['name'] ?? null)
                    ? (string) $file['name']
                    : (filled($file['path'] ?? null)
                        ? basename(str_replace('\\', '/', (string) $file['path']))
                        : 'file');

                $url = filled($file['web_url'] ?? null) ? (string) $file['web_url'] : null;

                if ($url === null
                    && $index === 0
                    && filled($this->sharepoint_web_url)
                    && (
                        blank($file['item_id'] ?? null)
                        || (string) ($file['item_id'] ?? '') === (string) ($this->sharepoint_item_id ?? '')
                    )
                ) {
                    $url = (string) $this->sharepoint_web_url;
                }

                if ($url === null && $fallbackUrl !== null) {
                    $url = $fallbackUrl;
                }

                $links[] = [
                    'name' => $name,
                    'url' => $url,
                ];
            }
        }

        if ($links === [] && $this->hasSharepointLink()) {
            $name = filled($this->sharepoint_path)
                ? basename(str_replace('\\', '/', (string) $this->sharepoint_path))
                : (filled($this->name) ? (string) $this->name : 'file');

            $links[] = [
                'name' => $name,
                'url' => $fallbackUrl,
            ];
        }

        if ($links === [] && DocumentBinaryStore::hasFile($this)) {
            $links[] = [
                'name' => DocumentBinaryStore::downloadFileName($this),
                'url' => $fallbackUrl,
            ];
        }

        return $links;
    }

    public function hasSharepointLink(): bool
    {
        return filled($this->sharepoint_web_url) || filled($this->sharepoint_item_id);
    }

    public function hasStoredFile(): bool
    {
        return DocumentBinaryStore::hasFile($this);
    }

    public function displayFileUrl(): ?string
    {
        if (filled($this->sharepoint_web_url)) {
            return (string) $this->sharepoint_web_url;
        }

        if (is_array($this->sharepoint_files)) {
            foreach ($this->sharepoint_files as $file) {
                if (is_array($file) && filled($file['web_url'] ?? null)) {
                    return (string) $file['web_url'];
                }
            }
        }

        $path = $this->displayFilePath() ?: $this->file_path;
        if (filled($path) && \Illuminate\Support\Facades\Storage::disk('public')->exists((string) $path)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url((string) $path);
        }

        if ($this->hasStoredFile() || $this->hasSharepointLink()) {
            return route('documents.file', $this);
        }

        return null;
    }
}
