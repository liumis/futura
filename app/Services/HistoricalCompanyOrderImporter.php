<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveRequestStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestType;
use App\Models\SharepointSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import historical company orders (įsakymai): store originals on SharePoint,
 * create confirmed leave requests, and sync employment termination metadata.
 */
class HistoricalCompanyOrderImporter
{
    public const DOCUMENT_TYPE_NAME = 'Įsakymas';

    /**
     * @return list<array{
     *     number: int,
     *     date: string,
     *     title: string,
     *     file_match: string,
     *     kind: string,
     *     leaves?: list<array{employee: string, type: string, from: string, to: string}>,
     *     termination?: array{employee: string, date: string, reason: string}
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'number' => 1,
                'date' => '2026-03-25',
                'title' => 'Dėl inventorizavimo komisijos sudarymo ir metinės inventorizacijos atlikimo',
                'file_match' => 'Nr. 1*inventor*',
                'kind' => 'archive',
            ],
            [
                'number' => 2,
                'date' => '2026-05-04',
                'title' => 'Dėl Loretos Arman atleidimo iš darbo',
                'file_match' => 'Nr. 2*atleid*',
                'kind' => 'termination',
                'termination' => [
                    'employee' => 'Loreta Arman',
                    'date' => '2026-05-08',
                    'reason' => 'Pagal LR Darbo kodekso 55 straipsnio 1 dalį, pačios prašymu (įsakymas Nr. 2, 2026-05-04).',
                ],
            ],
            [
                'number' => 3,
                'date' => '2026-05-11',
                'title' => 'Dėl nuolat veikiančios komisijos',
                'file_match' => 'Nr. 3*komis*',
                'kind' => 'archive',
            ],
            [
                'number' => 4,
                'date' => '2026-06-01',
                'title' => 'Dėl kasmetinių atostogų',
                'file_match' => 'Nr. 4*atostog*',
                'kind' => 'leave',
                'leaves' => [
                    [
                        'employee' => 'Deividas Arman',
                        'type' => 'Kasmetinės atostogos',
                        'from' => '2026-06-02',
                        'to' => '2026-06-21',
                    ],
                ],
            ],
            [
                'number' => 5,
                'date' => '2026-08-06',
                'title' => 'Dėl kasmetinių atostogų',
                'file_match' => 'Nr. 5*atostog*',
                'kind' => 'leave',
                'leaves' => [
                    [
                        'employee' => 'Deividas Arman',
                        'type' => 'Kasmetinės atostogos',
                        'from' => '2026-08-07',
                        'to' => '2026-08-14',
                    ],
                    [
                        'employee' => 'Kristina Arman',
                        'type' => 'Kasmetinės atostogos',
                        'from' => '2026-08-07',
                        'to' => '2026-08-14',
                    ],
                    [
                        'employee' => 'Žaneta Jocienė',
                        'type' => 'Kasmetinės atostogos',
                        'from' => '2026-08-10',
                        'to' => '2026-08-13',
                    ],
                ],
            ],
            [
                'number' => 6,
                'date' => '2026-08-06',
                'title' => 'Dėl papildomos poilsio dienos suteikimo',
                'file_match' => 'Nr. 6*poilsio*',
                'kind' => 'leave',
                'leaves' => [
                    [
                        'employee' => 'Žaneta Jocienė',
                        'type' => 'Tėvadienis / Mamadienis',
                        'from' => '2026-08-14',
                        'to' => '2026-08-14',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{
     *     number: int,
     *     document_id: int|null,
     *     path: string,
     *     leaves: list<array{leave_request_id: int, employee: string, from: string, to: string, type: string}>,
     *     termination: array{employee_id: int, contract_id: int, date: string}|null
     * }>
     */
    public static function import(?string $directory = null, bool $storeDocuments = true): array
    {
        $directory ??= rtrim((string) getenv('USERPROFILE'), '\\/').DIRECTORY_SEPARATOR.'Downloads';
        if (! is_dir($directory)) {
            throw new RuntimeException('Directory not found: '.$directory);
        }

        if ($storeDocuments && ! SharepointSetting::instance()->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured.');
        }

        $actor = self::actor();
        $results = [];

        foreach (self::definitions() as $definition) {
            $path = self::resolveFile($directory, $definition['file_match']);
            if ($path === null) {
                throw new RuntimeException('File not found for order Nr. '.$definition['number'].' ('.$definition['file_match'].') in '.$directory);
            }

            $document = null;
            if ($storeDocuments) {
                $document = self::storeOrderDocument($path, $definition, $actor);
            }

            $leaveRows = [];
            $termination = null;

            DB::transaction(function () use ($definition, $document, $actor, &$leaveRows, &$termination): void {
                if (($definition['kind'] ?? '') === 'leave') {
                    foreach ($definition['leaves'] ?? [] as $leaveDef) {
                        $leaveRows[] = self::upsertLeaveRequest($leaveDef, $definition, $document, $actor);
                    }
                }

                if (($definition['kind'] ?? '') === 'termination') {
                    $termination = self::syncTermination($definition['termination'], $definition, $document, $actor);
                }
            });

            $results[] = [
                'number' => $definition['number'],
                'document_id' => $document?->getKey(),
                'path' => $path,
                'leaves' => $leaveRows,
                'termination' => $termination,
            ];
        }

        return $results;
    }

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * @param  array{number: int, date: string, title: string}  $definition
     */
    protected static function storeOrderDocument(string $path, array $definition, User $actor): Document
    {
        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Could not read order file: '.$path);
        }

        $type = self::ensureDocumentTypeExists();
        $baseName = basename($path);
        $documentName = sprintf('Įsakymas Nr. %d — %s', $definition['number'], $definition['title']);

        $existing = Document::query()
            ->where('document_type_id', $type->getKey())
            ->where('name', $documentName)
            ->first();

        $document = $existing ?? Document::query()->create([
            'document_date' => $definition['date'],
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $actor->getKey(),
            'flag_approved' => true,
            'user_approved_id' => $actor->getKey(),
            'approval_date' => now(),
            'content_hash' => hash('sha256', $binary),
        ]);

        if ($existing) {
            $document->forceFill([
                'document_date' => $definition['date'],
                'content_hash' => hash('sha256', $binary),
                'flag_approved' => true,
                'user_approved_id' => $actor->getKey(),
                'approval_date' => $document->approval_date ?? now(),
            ])->save();
        }

        DocumentBinaryStore::storeBinary($document->fresh(['documentType']), $binary, $baseName);

        return $document->fresh(['documentType']);
    }

    /**
     * @param  array{employee: string, type: string, from: string, to: string}  $leaveDef
     * @param  array{number: int, date: string}  $definition
     * @return array{leave_request_id: int, employee: string, from: string, to: string, type: string}
     */
    protected static function upsertLeaveRequest(
        array $leaveDef,
        array $definition,
        ?Document $document,
        User $actor,
    ): array {
        $employee = self::findEmployee($leaveDef['employee']);
        $type = LeaveRequestType::query()->where('name', $leaveDef['type'])->first();
        if ($type === null) {
            throw new RuntimeException('Leave request type not found: '.$leaveDef['type']);
        }

        $leave = LeaveRequest::query()->firstOrNew([
            'employee_id' => $employee->getKey(),
            'leave_request_type_id' => $type->getKey(),
            'date_from' => $leaveDef['from'],
            'date_to' => $leaveDef['to'],
        ]);

        $leave->fill([
            'comment' => trim(($leave->comment ? $leave->comment."\n" : '').'Importuota iš įsakymo Nr. '.$definition['number'].'.'),
            'status' => LeaveRequestStatus::Confirmed,
            'confirmed_by' => $actor->getKey(),
            'confirmed_at' => Carbon::parse($definition['date'])->startOfDay(),
            'order_document_id' => $document?->getKey() ?? $leave->order_document_id,
        ]);
        $leave->save();

        return [
            'leave_request_id' => (int) $leave->getKey(),
            'employee' => $employee->fullName(),
            'from' => $leaveDef['from'],
            'to' => $leaveDef['to'],
            'type' => $leaveDef['type'],
        ];
    }

    /**
     * @param  array{employee: string, date: string, reason: string}  $termination
     * @param  array{number: int, date: string}  $definition
     * @return array{employee_id: int, contract_id: int, date: string}
     */
    protected static function syncTermination(
        array $termination,
        array $definition,
        ?Document $document,
        User $actor,
    ): array {
        $employee = self::findEmployee($termination['employee']);
        $contract = EmployeeContract::query()
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('id')
            ->first();

        if ($contract === null) {
            throw new RuntimeException('No contract found for '.$employee->fullName());
        }

        $contract->forceFill([
            'termination_date' => $termination['date'],
            'termination_reason' => $termination['reason'],
            'valid_to' => $termination['date'],
            'status' => \App\Enums\EmployeeContractStatus::Inactive,
        ])->save();

        // Keep an existing signed scan if present; also point at the Word įsakymas when none set.
        if ($document !== null && blank($contract->termination_document_id)) {
            $contract->forceFill([
                'termination_document_id' => $document->getKey(),
            ])->save();
        }

        $employee->forceFill([
            'status' => EmployeeStatus::Inactive,
            'contract_end_date' => $termination['date'],
        ])->save();

        return [
            'employee_id' => (int) $employee->getKey(),
            'contract_id' => (int) $contract->getKey(),
            'date' => $termination['date'],
        ];
    }

    protected static function findEmployee(string $name): Employee
    {
        $needle = self::normalizeName($name);
        foreach (Employee::query()->orderBy('id')->get() as $employee) {
            if (self::normalizeName($employee->fullName()) === $needle) {
                return $employee;
            }
        }

        throw new RuntimeException('Employee not found: '.$name);
    }

    protected static function normalizeName(string $name): string
    {
        $name = Str::lower(Str::ascii(trim($name)));
        $name = preg_replace('/[^a-z\s]/', '', $name) ?? '';

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    public static function resolveFile(string $directory, string $pattern): ?string
    {
        $matches = glob($directory.DIRECTORY_SEPARATOR.$pattern) ?: [];
        $matches = array_values(array_filter($matches, 'is_file'));
        if ($matches === []) {
            // Case-insensitive fallback for Windows locales / Unicode filenames.
            $files = scandir($directory) ?: [];
            $regex = '/^'.implode('.*', array_map(
                fn (string $part): string => preg_quote($part, '/'),
                preg_split('/\*+/', $pattern) ?: [$pattern],
            )).'$/iu';
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                if (preg_match($regex, $file) === 1) {
                    $full = $directory.DIRECTORY_SEPARATOR.$file;
                    if (is_file($full)) {
                        $matches[] = $full;
                    }
                }
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, fn (string $a, string $b): int => filesize($b) <=> filesize($a));

        return $matches[0];
    }

    protected static function actor(): User
    {
        $user = auth()->user();
        if ($user instanceof User) {
            return $user;
        }

        $user = User::query()->where('email', 'info@futuratextiles.eu')->first()
            ?? User::query()->orderBy('id')->first();

        if (! $user instanceof User) {
            throw new RuntimeException('No user available to attribute imported documents.');
        }

        return $user;
    }
}
