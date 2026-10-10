<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ExpenseType;
use App\Models\Invoice;
use App\Models\SharepointSetting;
use App\Models\User;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use App\Services\ContactMerger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

class PurchaseRegisterImporter
{
    /**
     * @param  list<string>|null  $pdfSearchDirs
     * @return array{
     *     rows: int,
     *     created: int,
     *     skipped_existing: int,
     *     with_pdf: int,
     *     sharepoint: int,
     *     bank_linked: int,
     *     missing_pdf: list<string>,
     *     errors: list<string>
     * }
     */
    public function import(string $registerPath, ?array $pdfSearchDirs = null, bool $linkBank = true): array
    {
        $rows = $this->loadRows($registerPath);
        $pdfSearchDirs ??= ['C:\\Users\\liumi\\Downloads'];
        $pdfIndex = $this->buildPdfIndex($pdfSearchDirs);

        $uploadedBy = User::query()->orderBy('id')->value('id');
        if ($uploadedBy === null) {
            throw new InvalidArgumentException('No users exist to set as uploaded_by.');
        }

        $stats = [
            'rows' => count($rows),
            'created' => 0,
            'skipped_existing' => 0,
            'with_pdf' => 0,
            'sharepoint' => 0,
            'bank_linked' => 0,
            'missing_pdf' => [],
            'errors' => [],
        ];

        foreach ($rows as $row) {
            $existing = Invoice::query()
                ->where('invoice_number', $row['invoice_number'])
                ->orWhere('invoice_number', $this->altNumber($row['invoice_number']))
                ->first();

            if ($existing !== null) {
                $stats['skipped_existing']++;

                continue;
            }

            $pdfPath = $this->matchPdf($row['invoice_number'], $pdfIndex);
            if ($pdfPath === null && count($stats['missing_pdf']) < 40) {
                $stats['missing_pdf'][] = $row['invoice_number'].' '.$row['supplier_name'];
            }

            try {
                $invoice = DB::transaction(function () use ($row, $uploadedBy, $pdfPath, &$stats): Invoice {
                    $contact = $this->resolveContact($row);
                    $isCredit = $row['sum_inc_vat'] < 0;
                    $net = abs($row['sum_without_vat']);
                    $vat = abs($row['vat']);
                    $gross = abs($row['sum_inc_vat']);

                    if ($isCredit) {
                        $net = -1 * $net;
                        $vat = -1 * $vat;
                        $gross = -1 * $gross;
                    }

                    $invoice = Invoice::query()->create([
                        'contact_id' => $contact->id,
                        'invoice_number' => $row['invoice_number'],
                        'invoice_date' => $row['invoice_date'],
                        'sum_without_vat' => $net,
                        'vat' => $vat,
                        'sum_inc_vat' => $gross,
                        'payment_term' => Invoice::DEFAULT_PAYMENT_TERM_DAYS,
                        'paid_amount' => 0,
                        'paid' => false,
                        'income_type_id' => null,
                        'expense_type_id' => $this->expenseTypeIdFor($row, $isCredit),
                        'is_own_account' => false,
                        'upload_date' => $row['invoice_date'],
                        'uploaded_by' => $uploadedBy,
                        'pdf_path' => '',
                        'file_name' => $pdfPath ? basename($pdfPath) : null,
                        'file_mime' => $pdfPath ? 'application/pdf' : null,
                        'file_content' => null,
                    ]);

                    InvoiceDefaultFinanceLines::apply($invoice);
                    $contact->rememberDefaultCategoryFromInvoice($invoice);

                    if ($pdfPath !== null) {
                        $stats['with_pdf']++;
                    }

                    return $invoice;
                });
            } catch (Throwable $exception) {
                $stats['errors'][] = $row['invoice_number'].': '.$exception->getMessage();

                continue;
            }

            if ($pdfPath !== null && SharepointSetting::instance()->isReady()) {
                try {
                    SharepointInvoiceUploader::ingestBinary(
                        $invoice,
                        (string) file_get_contents($pdfPath),
                        basename($pdfPath),
                    );
                    $stats['sharepoint']++;
                } catch (Throwable $exception) {
                    $stats['errors'][] = $row['invoice_number'].': SharePoint '.$exception->getMessage();
                }
            }

            $stats['created']++;
        }

        if ($linkBank) {
            $stats['bank_linked'] = BankStatementInvoiceMatcher::autoLinkUnlinked();
        }

        return $stats;
    }

    /**
     * @return list<array{
     *     invoice_number: string,
     *     invoice_date: string,
     *     supplier_name: string,
     *     supplier_code: ?string,
     *     supplier_vat: ?string,
     *     sum_without_vat: float,
     *     vat: float,
     *     sum_inc_vat: float
     * }>
     */
    public function loadRows(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException('Purchase register not found: '.$path);
        }

        $text = (new PdfParser)->parseFile($path)->getText();
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $rows = [];
        $pattern = '/(-?\d+[.,]\d{2})\s+(-?\d+[.,]\d{2})(\d{4}-\d{2}-\d{2})\s+(-?\d+[.,]\d{2})([A-Za-z0-9][A-Za-z0-9\-]*)\s+([^\n]+)/u';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $m) {
                $net = $this->parseAmount($m[1]);
                $gross = $this->parseAmount($m[2]);
                $date = $m[3];
                $vat = $this->parseAmount($m[4]);
                $doc = $this->normalizeDocNumber($m[5]);
                $rest = trim($m[6]);

                // Skip totals / page debris
                if ($doc === '' || str_starts_with(mb_strtolower($rest), 'viso')) {
                    continue;
                }

                [$name, $code, $vatCode] = $this->parseSupplierTail($rest);

                if (abs(($net + $vat) - $gross) > 0.05 && abs((abs($net) + abs($vat)) - abs($gross)) <= 0.05) {
                    // keep signed as parsed
                }

                $rows[] = [
                    'invoice_number' => $doc,
                    'invoice_date' => $date,
                    'supplier_name' => $name,
                    'supplier_code' => $code,
                    'supplier_vat' => $vatCode,
                    'sum_without_vat' => $net,
                    'vat' => $vat,
                    'sum_inc_vat' => $gross,
                ];
            }
        }

        // De-dupe by invoice number (PDF has page repeats of header only)
        $unique = [];
        foreach ($rows as $row) {
            $unique[$row['invoice_number']] = $row;
        }

        $rows = array_values($unique);
        usort($rows, fn (array $a, array $b): int => [$a['invoice_date'], $a['invoice_number']] <=> [$b['invoice_date'], $b['invoice_number']]);

        return $rows;
    }

    /**
     * @param  list<string>  $dirs
     * @return array<string, string> normalized => path
     */
    private function buildPdfIndex(array $dirs): array
    {
        $index = [];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob(rtrim($dir, '\\/').DIRECTORY_SEPARATOR.'*.pdf') ?: [] as $path) {
                $base = pathinfo($path, PATHINFO_FILENAME);
                $keys = $this->pdfKeys($base);
                foreach ($keys as $key) {
                    if ($key === '') {
                        continue;
                    }
                    // Prefer newer / "correct" / longer names last overwrite is fine
                    $index[$key] = $path;
                }
            }
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private function pdfKeys(string $filename): array
    {
        $keys = [];
        $compact = strtoupper(preg_replace('/[^A-Z0-9]/', '', $filename) ?? '');
        $keys[] = $compact;

        if (preg_match('/(\d{6,})/', $filename, $m)) {
            $keys[] = $m[1];
        }

        // Invoice-TFHHO2R8-0001 → TFHHO2R80001
        if (preg_match('/Invoice[- ]([A-Z0-9\-]+)/i', $filename, $m)) {
            $keys[] = strtoupper(preg_replace('/[^A-Z0-9]/', '', $m[1]) ?? '');
        }

        // CreditNote-0SDS25KK-0004-CN-01 → 0SDS25KK0004CN01
        if (preg_match('/CreditNote[- ]([A-Z0-9\-]+)/i', $filename, $m)) {
            $keys[] = strtoupper(preg_replace('/[^A-Z0-9]/', '', $m[1]) ?? '');
        }

        // Refund-3769-0766 → 37690766
        if (preg_match('/Refund[- ](\d+)[- ](\d+)/i', $filename, $m)) {
            $keys[] = $m[1].$m[2];
            $keys[] = $m[1].str_pad($m[2], 4, '0', STR_PAD_LEFT);
        }

        // LNKD_INVOICE_789305899038
        if (preg_match('/LNKD_INVOICE_(\d+)/i', $filename, $m)) {
            $keys[] = $m[1];
        }

        // Invoice 380700 Hans Reinke
        if (preg_match('/Invoice\s+(\d+)/i', $filename, $m)) {
            $keys[] = $m[1];
        }

        // SPO00000140 / SP532761 / EFVS2609851 / GLO0371479
        if (preg_match('/\b([A-Z]{2,}\d+[A-Z0-9]*)\b/i', $filename, $m)) {
            $keys[] = strtoupper(preg_replace('/[^A-Z0-9]/', '', $m[1]) ?? '');
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param  array<string, string>  $index
     */
    private function matchPdf(string $invoiceNumber, array $index): ?string
    {
        $needle = strtoupper(preg_replace('/[^A-Z0-9]/', '', $invoiceNumber) ?? '');
        if ($needle === '') {
            return null;
        }

        if (isset($index[$needle])) {
            return $index[$needle];
        }

        // Strict containment only when both sides are reasonably specific.
        if (strlen($needle) < 7) {
            return null;
        }

        $best = null;
        $bestLen = PHP_INT_MAX;
        foreach ($index as $key => $path) {
            if ($key === $needle) {
                return $path;
            }
            // Key must contain the full invoice number (not the other way around —
            // that falsely maps short numeric docs onto unrelated filenames).
            if (str_contains($key, $needle) && strlen($key) < $bestLen) {
                $best = $path;
                $bestLen = strlen($key);
            }
        }

        return $best;
    }

    /**
     * @param  array{supplier_name: string, supplier_code: ?string, supplier_vat: ?string}  $row
     */
    private function resolveContact(array $row): Contact
    {
        $name = LithuanianCompanyNameNormalizer::normalize((string) $row['supplier_name']);
        if ($name === '') {
            $name = trim((string) $row['supplier_name']);
        }
        $match = ContactMerger::matchExisting($name);

        if ($match === null && filled($row['supplier_vat'])) {
            $vat = strtoupper(preg_replace('/\s+/', '', (string) $row['supplier_vat']) ?? '');
            $match = Contact::query()
                ->whereRaw("UPPER(REPLACE(COALESCE(company_vat,''), ' ', '')) = ?", [$vat])
                ->first();
        }

        if ($match === null && filled($row['supplier_code'])) {
            $match = Contact::query()->where('company_id', $row['supplier_code'])->first();
        }

        $payload = array_filter([
            'company_name' => $name,
            'company_id' => $row['supplier_code'] ?: null,
            'company_vat' => $row['supplier_vat'] ?: null,
            'company_address' => '—',
        ], static fn ($v) => $v !== null && $v !== '');

        if ($match !== null) {
            foreach (['company_vat', 'company_id'] as $field) {
                if (filled($payload[$field] ?? null) && blank($match->{$field})) {
                    $match->{$field} = $payload[$field];
                }
            }
            if (filled($payload['company_name'] ?? null)) {
                $match->company_name = $payload['company_name'];
            }
            $match->save();

            return $match->fresh() ?? $match;
        }

        return Contact::query()->create([
            'company_name' => $name,
            'company_id' => $row['supplier_code'] ?: ('SUP-'.strtoupper(substr(md5(mb_strtolower($name)), 0, 10))),
            'company_vat' => $row['supplier_vat'],
            'company_address' => '—',
        ]);
    }

    /**
     * @param  array{supplier_name: string, invoice_number: string}  $row
     */
    private function expenseTypeIdFor(array $row, bool $isCredit): ?int
    {
        if ($isCredit) {
            $id = Invoice::creditNoteExpenseTypeId();
            if ($id !== null) {
                return $id;
            }

            return ExpenseType::query()->firstOrCreate(
                ['name' => Invoice::CREDIT_NOTE_EXPENSE_TYPE],
            )->id;
        }

        $name = mb_strtolower($row['supplier_name']);
        $doc = strtoupper($row['invoice_number']);

        return match (true) {
            str_contains($name, 'dsv') => ExpenseType::query()->where('name', 'Transportas')->value('id'),
            str_contains($name, 'linkedin') => ExpenseType::query()->where('name', 'Marketingas')->value('id'),
            str_contains($name, 'anthropic') => ExpenseType::query()->where('name', 'IT')->value('id'),
            str_contains($name, 'finans') && str_contains($name, 'valdymo') => ExpenseType::query()->where('name', 'IT')->value('id'),
            str_starts_with($doc, 'EFVS') => ExpenseType::query()->where('name', 'IT')->value('id'),
            str_contains($name, 'mayur') => Invoice::goodsExpenseTypeId(),
            str_contains($name, 'vinilas') => Invoice::goodsExpenseTypeId(),
            str_contains($name, 'reinke') => ExpenseType::query()->where('name', 'Paslaugos')->value('id'),
            str_contains($name, 'technolog') => ExpenseType::query()->where('name', 'Transportas')->value('id'),
            str_contains($name, 'glotera') => ExpenseType::query()->where('name', 'Paslaugos')->value('id'),
            str_contains($name, 'litideal') => ExpenseType::query()->where('name', 'Administracija')->value('id'),
            default => Invoice::goodsExpenseTypeId() ?? ExpenseType::query()->where('name', 'Kita')->value('id'),
        };
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string} name, company code, vat
     */
    private function parseSupplierTail(string $rest): array
    {
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/u', $rest) ?: [],
        )));

        $nameParts = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            // Stop at address / bank-ish lines
            if (preg_match('/^\d+[\/\-\s]|^(Stasyl|Ringuv|Brandstuck|Village|Laisv|A\.\s*Juoz|Veiver|5 WILTON|548 Market|PMB|Dublin|Hamburg|Jaipur|Vilnius|Kaunas|Šiauliai)/iu', $line) === 1) {
                break;
            }
            if (preg_match('/^(LT|DE|IE)\d/i', $line) === 1) {
                break;
            }
            if (preg_match('/^\d{8,11}$/', $line) === 1) {
                break;
            }
            $nameParts[] = trim($line, " \t\"'");
            // Enough for "UŽDAROJI AKCINĖ BENDROVĖ GLOTERA"
            if (count($nameParts) >= 4) {
                break;
            }
        }

        $name = trim(preg_replace('/\s+/u', ' ', implode(' ', $nameParts)) ?? '');
        $name = trim($name, " \t\"'");

        // Prefer quoted brand if present
        if (preg_match('/"([^"]+)"/u', $rest, $qm) === 1) {
            $quoted = trim($qm[1]);
            if ($quoted !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($quoted))) {
                $name = trim($name.' "'.$quoted.'"');
            }
            if (preg_match('/UŽDAROJI AKCINĖ BENDROVĖ/iu', $name) === 1) {
                $name = LithuanianCompanyNameNormalizer::normalize('UAB "'.$quoted.'"');
            }
        }

        $code = null;
        $vat = null;
        if (preg_match('/\b(\d{8,11})\b/', $rest, $m)) {
            $code = $m[1];
        }
        if (preg_match('/\b((?:LT|DE|IE|PL|LV|EE)[A-Z0-9]+)\b/u', $rest, $m)) {
            $vat = strtoupper($m[1]);
        }

        if ($name === '') {
            $name = 'Unknown supplier';
        }

        return [$name, $code, $vat];
    }

    private function normalizeDocNumber(string $value): string
    {
        $value = trim($value);
        // Keep alphanumeric + hyphen for readability of Anthropic-style numbers
        $value = preg_replace('/\s+/', '', $value) ?? $value;

        // TFHHO2R80001 → TFHHO2R8-0001 style optional; store compact as in register
        return strtoupper($value);
    }

    private function altNumber(string $number): string
    {
        if (str_contains($number, '-')) {
            return str_replace('-', '', $number);
        }

        // Insert dashes for Anthropic-like: TFHHO2R80001 → TFHHO2R8-0001
        if (preg_match('/^([A-Z]+)(\d+)$/', $number, $m) === 1 && strlen($m[2]) >= 4) {
            return $m[1].'-'.substr($m[2], 0, -4).'-'.substr($m[2], -4);
        }

        return $number;
    }

    private function parseAmount(string $value): float
    {
        $value = str_replace([' ', "\xc2\xa0"], '', $value);
        $value = str_replace(',', '.', $value);

        return round((float) $value, 2);
    }
}
