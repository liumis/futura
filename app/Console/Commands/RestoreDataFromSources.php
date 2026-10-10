<?php

namespace App\Console\Commands;

use App\Models\BankStatementImport;
use App\Models\BusinessTripRequest;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\SharepointSetting;
use App\Models\User;
use App\Services\BankStatementDocumentGenerator;
use App\Services\BankStatements\BankStatementImporter;
use App\Services\BusinessTripDocumentService;
use App\Services\Fr0564ReportDocumentGenerator;
use App\Services\Fr0600ReportDocumentGenerator;
use App\Services\Gpm312ReportDocumentGenerator;
use App\Services\InvoiceOcrService;
use App\Services\PvmReportDocumentGenerator;
use App\Services\SharepointBankStatementUploader;
use App\Services\SharepointGraphClient;
use App\Services\SharepointInvoiceUploader;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RestoreDataFromSources extends Command
{
    protected $signature = 'app:restore-data-from-sources
                            {--skip-bank : Skip bank statement refill}
                            {--skip-invoices : Skip SharePoint invoice scan}
                            {--skip-reports : Skip GPM/PVM/FR report recreation}
                            {--skip-trips : Skip business trip document linking}
                            {--dry-run : Show actions without writing}';

    protected $description = 'Restore bank lines, invoices, and tax/trip reports from SharePoint and local source files after a DB restore';

    /** @var list<string> */
    private array $invoiceOrphans = [];

    public function handle(BankStatementImporter $importer): int
    {
        $dry = (bool) $this->option('dry-run');
        $actor = User::query()->orderBy('id')->first();

        if (! $this->option('skip-bank')) {
            $this->restoreBankStatements($importer, $dry);
        }

        if (! $this->option('skip-invoices')) {
            $this->restoreInvoicesFromSharepoint($dry, $actor);
        }

        if (! $this->option('skip-reports')) {
            $this->restoreReports($dry, $actor);
        }

        if (! $this->option('skip-trips')) {
            $this->restoreBusinessTripDocuments($dry, $actor);
        }

        $this->newLine();
        $this->info('=== Invoices on SharePoint but not in system ===');
        if ($this->invoiceOrphans === []) {
            $this->info('(none)');
        } else {
            foreach ($this->invoiceOrphans as $line) {
                $this->line($line);
            }
            $this->warn('Total orphans: '.count($this->invoiceOrphans));
        }

        $this->newLine();
        $this->info('Done. BST='.DB::table('bank_statement_transactions')->count()
            .' invoices='.DB::table('invoices')->count()
            .' docs='.DB::table('documents')->count()
            .' trips='.DB::table('business_trip_requests')->count());

        return self::SUCCESS;
    }

    private function restoreBankStatements(BankStatementImporter $importer, bool $dry): void
    {
        $this->info('--- Bank statements ---');
        $tmpDir = storage_path('app/restore-bank');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $sources = [];

        foreach (BankStatementImport::query()->orderBy('id')->get() as $import) {
            /** @var BankStatementImport $import */
            $local = null;
            if (filled($import->sharepoint_item_id) && SharepointSetting::instance()->isReady()) {
                try {
                    $binary = SharepointGraphClient::make()->downloadItemContent((string) $import->sharepoint_item_id);
                    $ext = pathinfo((string) $import->original_filename, PATHINFO_EXTENSION) ?: 'bin';
                    $local = $tmpDir.'/import-'.$import->getKey().'.'.$ext;
                    file_put_contents($local, $binary);
                    $this->line("Downloaded import #{$import->getKey()} from SharePoint (".strlen($binary).' bytes)');
                } catch (Throwable $e) {
                    $this->warn("SP download failed for import #{$import->getKey()}: ".$e->getMessage());
                }
            }

            if ($local === null && filled($import->sharepoint_path) && SharepointSetting::instance()->isReady()) {
                try {
                    $client = SharepointGraphClient::make();
                    $meta = $client->getItemByPath((string) $import->sharepoint_path);
                    if ($meta !== null) {
                        $binary = $client->downloadItemContent($meta['id']);
                        $ext = pathinfo((string) $import->original_filename, PATHINFO_EXTENSION) ?: 'bin';
                        $local = $tmpDir.'/import-'.$import->getKey().'.'.$ext;
                        file_put_contents($local, $binary);
                        if (! filled($import->sharepoint_item_id)) {
                            $import->forceFill([
                                'sharepoint_item_id' => $meta['id'],
                                'sharepoint_web_url' => $meta['webUrl'],
                            ])->save();
                        }
                        $this->line("Downloaded import #{$import->getKey()} via path");
                    }
                } catch (Throwable $e) {
                    $this->warn("SP path download failed for import #{$import->getKey()}: ".$e->getMessage());
                }
            }

            if ($local !== null) {
                $sources[] = [$local, (string) $import->original_filename, $import];
            }
        }

        // Local Downloads / fixtures the user shared.
        $extraGlobs = [
            'C:/Users/liumi/Downloads/27101 - 5504 2026.04.01-2026.07.31.xls',
            'C:/Users/liumi/Downloads/27102 - 7970 2026.04.01-2026.07.31.xls',
            'C:/Users/liumi/Downloads/27103 - 9371 2026.04.01-2026.07.31.xls',
            'C:/Users/liumi/Downloads/statement.xml',
            'C:/Users/liumi/Downloads/2026.04-07 men 27102-7970.pdf',
            'C:/Users/liumi/Downloads/2026.04-07 men 27103-9371.pdf',
        ];

        // Also pull any bank-statements folder files from SharePoint not already imported.
        if (SharepointSetting::instance()->isReady()) {
            try {
                $this->collectSharepointBankFiles($sources, $tmpDir);
            } catch (Throwable $e) {
                $this->warn('SharePoint bank folder scan failed: '.$e->getMessage());
            }
        }

        foreach ($extraGlobs as $path) {
            if (is_file($path)) {
                $sources[] = [$path, basename($path), null];
            }
        }

        $seenFiles = [];
        foreach ($sources as [$path, $name, $import]) {
            $hash = @hash_file('sha256', $path) ?: $path;
            if (isset($seenFiles[$hash])) {
                continue;
            }
            $seenFiles[$hash] = true;

            $this->line(($dry ? '[dry] ' : '')."Fill missing from {$name}".($import ? " (import #{$import->getKey()})" : ''));
            if ($dry) {
                continue;
            }

            try {
                $result = $importer->importMissingFromFile($path, $name, $import);
                $this->info("  +{$result['imported']} skipped={$result['skipped']} total={$result['total']}");

                $imp = $result['import'];
                if (! $imp->hasSharepointLink() && SharepointSetting::instance()->isReady() && is_file($path)) {
                    try {
                        SharepointBankStatementUploader::ingestLocalFile($imp, (string) file_get_contents($path), null);
                        $this->line("  uploaded to SharePoint: {$imp->sharepoint_path}");
                    } catch (Throwable $e) {
                        $this->warn('  SP upload: '.$e->getMessage());
                    }
                }

                BankStatementDocumentGenerator::syncForImport($imp->fresh(), $imp->uploadedUser);
            } catch (Throwable $e) {
                $this->error("  FAILED {$name}: ".$e->getMessage());
            }
        }

        if (! $dry) {
            $this->call('bank-statements:sync-documents');
            $this->call('bank-statements:normalize-accounts');
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: ?BankStatementImport}>  $sources
     */
    private function collectSharepointBankFiles(array &$sources, string $tmpDir): void
    {
        $client = SharepointGraphClient::make();
        $root = SharepointBankStatementUploader::FOLDER_NAME;
        $years = $client->listFolderChildren($root);
        foreach ($years as $year) {
            if (! $year['isFolder']) {
                continue;
            }
            $months = $client->listFolderChildren($root.'/'.$year['name']);
            foreach ($months as $month) {
                if (! $month['isFolder']) {
                    continue;
                }
                $files = $client->listFolderChildren($root.'/'.$year['name'].'/'.$month['name']);
                foreach ($files as $file) {
                    if ($file['isFolder']) {
                        continue;
                    }
                    $ext = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (! in_array($ext, ['xml', 'xls', 'xlsx', 'xlsm', 'pdf'], true)) {
                        continue;
                    }
                    $existing = BankStatementImport::query()
                        ->where('sharepoint_item_id', $file['id'])
                        ->orWhere('sharepoint_path', 'like', '%/'.$file['name'])
                        ->first();
                    $local = $tmpDir.'/sp-'.$file['id'].'.'.$ext;
                    if (! is_file($local)) {
                        file_put_contents($local, $client->downloadItemContent($file['id']));
                    }
                    $sources[] = [$local, $file['name'], $existing];
                    $this->line("SP bank file: {$year['name']}/{$month['name']}/{$file['name']}");
                }
            }
        }
    }

    private function restoreInvoicesFromSharepoint(bool $dry, ?User $actor): void
    {
        $this->info('--- SharePoint invoices ---');
        if (! SharepointSetting::instance()->isReady()) {
            $this->warn('SharePoint not ready — skip invoice scan');

            return;
        }

        $client = SharepointGraphClient::make();
        $folder = SharepointInvoiceUploader::FOLDER_NAME;
        $files = [];

        try {
            $years = $client->listFolderChildren($folder);
        } catch (Throwable $e) {
            $this->error('Cannot list invoices folder: '.$e->getMessage());

            return;
        }

        foreach ($years as $year) {
            if (! $year['isFolder']) {
                if ($this->looksLikeInvoiceFile($year['name'])) {
                    $files[] = ['path' => $folder.'/'.$year['name'], 'meta' => $year];
                }

                continue;
            }
            try {
                $months = $client->listFolderChildren($folder.'/'.$year['name']);
            } catch (Throwable) {
                continue;
            }
            foreach ($months as $month) {
                if (! $month['isFolder']) {
                    if ($this->looksLikeInvoiceFile($month['name'])) {
                        $files[] = ['path' => $folder.'/'.$year['name'].'/'.$month['name'], 'meta' => $month];
                    }

                    continue;
                }
                try {
                    $items = $client->listFolderChildren($folder.'/'.$year['name'].'/'.$month['name']);
                } catch (Throwable) {
                    continue;
                }
                foreach ($items as $item) {
                    if ($item['isFolder'] || ! $this->looksLikeInvoiceFile($item['name'])) {
                        continue;
                    }
                    $files[] = [
                        'path' => $folder.'/'.$year['name'].'/'.$month['name'].'/'.$item['name'],
                        'meta' => $item,
                    ];
                }
            }
        }

        $this->info('Found '.count($files).' invoice files on SharePoint');
        $tmpDir = storage_path('app/restore-invoices');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $created = 0;
        $linked = 0;
        $updated = 0;

        foreach ($files as $file) {
            $meta = $file['meta'];
            $name = $meta['name'];
            $itemId = $meta['id'];
            $path = $file['path'];

            $existing = Invoice::query()
                ->where('sharepoint_item_id', $itemId)
                ->orWhere('sharepoint_path', $path)
                ->orWhere('file_name', $name)
                ->first();

            if ($existing !== null) {
                $dirty = false;
                if (! filled($existing->sharepoint_item_id)) {
                    $existing->sharepoint_item_id = $itemId;
                    $dirty = true;
                }
                if (! filled($existing->sharepoint_path)) {
                    $existing->sharepoint_path = $path;
                    $dirty = true;
                }
                if (! filled($existing->sharepoint_web_url) && filled($meta['webUrl'] ?? null)) {
                    $existing->sharepoint_web_url = $meta['webUrl'];
                    $dirty = true;
                }
                if ($dirty && ! $dry) {
                    $existing->save();
                    $updated++;
                }
                $linked++;

                continue;
            }

            // Try match by invoice number inferred from filename.
            $guessedNumber = $this->guessInvoiceNumber($name);
            if ($guessedNumber !== null) {
                $byNumber = Invoice::query()
                    ->where('invoice_number', $guessedNumber)
                    ->orWhere('invoice_number', 'like', '%'.$guessedNumber.'%')
                    ->first();
                if ($byNumber !== null) {
                    if (! $dry) {
                        $byNumber->forceFill([
                            'sharepoint_item_id' => $itemId,
                            'sharepoint_path' => $path,
                            'sharepoint_web_url' => $meta['webUrl'] ?? null,
                            'file_name' => $name,
                        ])->save();
                    }
                    $linked++;
                    $updated++;

                    continue;
                }
            }

            // Create from OCR when possible.
            if ($dry) {
                $this->invoiceOrphans[] = $path;
                $this->line("[dry] would create invoice from {$path}");

                continue;
            }

            try {
                $binary = $client->downloadItemContent($itemId);
                $local = $tmpDir.'/'.$name;
                file_put_contents($local, $binary);
                $ocr = [];
                try {
                    $ocr = app(InvoiceOcrService::class)->extract($local);
                } catch (Throwable $e) {
                    $this->warn("OCR failed for {$name}: ".$e->getMessage());
                }

                $number = trim((string) ($ocr['invoice_number'] ?? $guessedNumber ?? ''));
                if ($number !== '') {
                    $byNumber = Invoice::query()->where('invoice_number', $number)->first();
                    if ($byNumber !== null) {
                        $byNumber->forceFill([
                            'sharepoint_item_id' => $itemId,
                            'sharepoint_path' => $path,
                            'sharepoint_web_url' => $meta['webUrl'] ?? null,
                            'file_name' => $name,
                        ])->save();
                        @unlink($local);
                        $linked++;
                        $updated++;

                        continue;
                    }
                }

                if ($number === '' && empty($ocr)) {
                    $this->invoiceOrphans[] = $path;
                    $this->warn("Cannot create invoice (no OCR/number): {$path}");
                    @unlink($local);

                    continue;
                }

                $contactId = $this->resolveContactIdFromOcr($ocr);
                $invoice = Invoice::query()->create([
                    'invoice_number' => $number !== '' ? $number : ('SP-'.Str::upper(Str::random(8))),
                    'invoice_date' => $ocr['invoice_date'] ?? $this->guessDateFromPath($path),
                    'contact_id' => $contactId,
                    'provider_company_name' => $ocr['provider_company_name'] ?? null,
                    'provider_company_id' => $ocr['provider_company_id'] ?? null,
                    'provider_company_vat' => $ocr['provider_company_vat'] ?? null,
                    'sum_without_vat' => $ocr['sum_without_vat'] ?? null,
                    'vat' => $ocr['vat'] ?? null,
                    'sum_inc_vat' => $ocr['sum_inc_vat'] ?? null,
                    'currency' => $ocr['currency'] ?? 'EUR',
                    'sharepoint_item_id' => $itemId,
                    'sharepoint_path' => $path,
                    'sharepoint_web_url' => $meta['webUrl'] ?? null,
                    'file_name' => $name,
                    'file_mime' => 'application/pdf',
                    'user_id' => $actor?->getKey(),
                ]);
                @unlink($local);
                $created++;
                $this->info("Created invoice #{$invoice->getKey()} {$invoice->invoice_number} ← {$path}");
            } catch (Throwable $e) {
                $this->invoiceOrphans[] = $path;
                $this->error("Invoice restore failed {$path}: ".$e->getMessage());
            }
        }

        $this->info("Invoices linked/updated={$linked}/{$updated}, created={$created}, orphans=".count($this->invoiceOrphans));
    }

    private function restoreReports(bool $dry, ?User $actor): void
    {
        $this->info('--- Reports (GPM / PVM / FR) ---');
        if ($dry) {
            $this->line('[dry] would generate payroll period docs + GPM312 + FR0600/FR0564 + PVM months');

            return;
        }

        try {
            $this->call('app:generate-payroll-period', [
                '--from' => '2026-04',
                '--to' => '2026-08',
            ]);
        } catch (Throwable $e) {
            $this->warn('Payroll period generation: '.$e->getMessage());
        }

        // GPM312 for 2026
        try {
            $exists = Document::query()
                ->whereHas('documentType', fn ($q) => $q->where('name', Gpm312ReportDocumentGenerator::DOCUMENT_TYPE_NAME))
                ->whereYear('document_date', 2026)
                ->exists();
            if (! $exists) {
                Gpm312ReportDocumentGenerator::create(2026, $actor);
                $this->info('Created GPM312 2026');
            } else {
                $this->line('GPM312 2026 already exists');
            }
        } catch (Throwable $e) {
            $this->warn('GPM312: '.$e->getMessage());
        }

        foreach (range(4, 8) as $month) {
            foreach ([
                Fr0600ReportDocumentGenerator::class => Fr0600ReportDocumentGenerator::DOCUMENT_TYPE_NAME,
                Fr0564ReportDocumentGenerator::class => Fr0564ReportDocumentGenerator::DOCUMENT_TYPE_NAME,
            ] as $class => $typeName) {
                try {
                    $date = Carbon::create(2026, $month, 1)->endOfMonth()->toDateString();
                    $exists = Document::query()
                        ->whereHas('documentType', fn ($q) => $q->where('name', $typeName))
                        ->whereDate('document_date', $date)
                        ->exists();
                    if ($exists) {
                        $this->line("{$typeName} 2026-{$month} exists");

                        continue;
                    }
                    $class::create(2026, $month, $actor);
                    $this->info("Created {$typeName} 2026-{$month}");
                } catch (Throwable $e) {
                    $this->warn("{$typeName} 2026-{$month}: ".$e->getMessage());
                }
            }

            try {
                $from = Carbon::create(2026, $month, 1)->startOfDay();
                $to = $from->copy()->endOfMonth();
                $invoices = Invoice::query()
                    ->with(['vatRate', 'contact', 'incomeType', 'expenseType'])
                    ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
                    ->orderBy('invoice_date')
                    ->get();
                $typeName = PvmReportDocumentGenerator::DOCUMENT_TYPE_NAME;
                $exists = Document::query()
                    ->whereHas('documentType', fn ($q) => $q->where('name', $typeName))
                    ->where('name', 'like', '%'.$from->format('Y-m').'%')
                    ->exists();
                if ($exists) {
                    $this->line("PVM {$from->format('Y-m')} exists");

                    continue;
                }
                if ($invoices->isEmpty()) {
                    $this->line("PVM {$from->format('Y-m')}: no invoices");

                    continue;
                }
                PvmReportDocumentGenerator::create($invoices, [
                    'period_label' => $from->format('Y-m'),
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                ], $actor);
                $this->info("Created PVM {$from->format('Y-m')}");
            } catch (Throwable $e) {
                $this->warn("PVM 2026-{$month}: ".$e->getMessage());
            }
        }
    }

    private function restoreBusinessTripDocuments(bool $dry, ?User $actor): void
    {
        $this->info('--- Business trip documents ---');
        $trips = BusinessTripRequest::query()->with('documents')->orderBy('id')->get();
        $this->line('Trips in DB: '.$trips->count());

        if (! SharepointSetting::instance()->isReady()) {
            $this->warn('SharePoint not ready — skip trip SP link');
        } else {
            try {
                $client = SharepointGraphClient::make();
                $type = BusinessTripDocumentService::ensureDocumentTypeExists();
                // Link existing Komandiruotė documents that match trip date ranges.
                $docs = Document::query()
                    ->where('document_type_id', $type->getKey())
                    ->where(fn ($q) => $q->whereNotNull('sharepoint_item_id')->orWhereNotNull('sharepoint_path'))
                    ->get();
                foreach ($trips as $trip) {
                    if ($trip->documents->isNotEmpty()) {
                        continue;
                    }
                    $matched = $docs->first(function (Document $doc) use ($trip) {
                        $name = mb_strtolower((string) $doc->name);
                        $emp = mb_strtolower((string) ($trip->employee?->fullName() ?? ''));

                        return ($emp !== '' && str_contains($name, $emp))
                            || ($trip->date_from && str_contains($name, $trip->date_from->format('Y-m-d')));
                    });
                    if ($matched !== null && ! $dry) {
                        $trip->documents()->syncWithoutDetaching([$matched->getKey()]);
                        $this->info("Linked trip #{$trip->getKey()} → document #{$matched->getKey()}");
                    }
                }
            } catch (Throwable $e) {
                $this->warn('Trip SP link: '.$e->getMessage());
            }
        }

        // Attach local Downloads order PDFs when trip has no documents.
        $downloadPatterns = [
            'C:/Users/liumi/Downloads/Nr. *.pdf',
            'C:/Users/liumi/Downloads/Nr_1. *.pdf',
            'C:/Users/liumi/Downloads/Business trip expenses *.pdf',
        ];
        $localFiles = [];
        foreach ($downloadPatterns as $pattern) {
            foreach (glob($pattern) ?: [] as $f) {
                $localFiles[] = $f;
            }
        }

        foreach ($trips as $trip) {
            $trip->load('documents');
            if ($trip->documents->isNotEmpty()) {
                continue;
            }
            $from = optional($trip->date_from)->format('Y.m.d');
            $to = optional($trip->date_to)->format('Y.m.d');
            $match = null;
            foreach ($localFiles as $f) {
                $base = basename($f);
                if (($from && str_contains($base, $from)) || ($to && str_contains($base, $to))) {
                    $match = $f;
                    break;
                }
            }
            if ($match === null) {
                $this->warn("No local PDF for trip #{$trip->getKey()} {$from}-{$to}");

                continue;
            }
            $this->line(($dry ? '[dry] ' : '')."Attach {$match} to trip #{$trip->getKey()}");
            if ($dry || $actor === null) {
                continue;
            }
            try {
                $public = 'restore-trips/'.basename($match);
                Storage::disk('public')->put($public, (string) file_get_contents($match));
                BusinessTripDocumentService::attachUploads($trip, [$public], $actor);
                $this->info("  attached");
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
            }
        }
    }

    private function looksLikeInvoiceFile(string $name): bool
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);
    }

    private function guessInvoiceNumber(string $fileName): ?string
    {
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        // Patterns: FTE0123, INV201894420, 521074, SF-123
        if (preg_match('/\b(FTE\d+|INV\d+|[A-Z]{1,5}[-_]?\d{3,})\b/i', $base, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/(?<![A-Za-z])(\d{4,})\b/', $base, $m)) {
            return $m[1];
        }

        return null;
    }

    private function guessDateFromPath(string $path): string
    {
        if (preg_match('/(20\d{2})-(\d{2})/', $path, $m)) {
            return sprintf('%s-%s-01', $m[1], $m[2]);
        }

        return now()->toDateString();
    }

    /**
     * @param  array<string, mixed>  $ocr
     */
    private function resolveContactIdFromOcr(array $ocr): ?int
    {
        $name = trim((string) ($ocr['provider_company_name'] ?? ''));
        $vat = trim((string) ($ocr['provider_company_vat'] ?? ''));
        $code = trim((string) ($ocr['provider_company_id'] ?? ''));

        if ($vat !== '') {
            $c = Contact::query()->where('company_vat', $vat)->first();
            if ($c) {
                return (int) $c->getKey();
            }
        }
        if ($code !== '') {
            $c = Contact::query()->where('company_id', $code)->first();
            if ($c) {
                return (int) $c->getKey();
            }
        }
        if ($name !== '') {
            $c = Contact::query()->where('company_name', $name)->first();
            if ($c) {
                return (int) $c->getKey();
            }
        }

        return null;
    }
}
