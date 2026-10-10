<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateSystemArchitecturePdf extends Command
{
    protected $signature = 'docs:architecture-pdf
                            {--path= : Output path (default: storage/app/docs/futura-ss-architecture.pdf)}';

    protected $description = 'Generate a PDF overview of the FuturaTextiles SS system architecture';

    public function handle(): int
    {
        $path = $this->option('path')
            ?: storage_path('app/docs/futura-ss-architecture.pdf');

        File::ensureDirectoryExists(dirname($path));

        $diagram = <<<'TXT'
[Customer Browser] --/customer--> [Customer Panel]
                                       |
                                       v
                                 [OrderResource] --> [Order / OrderItem]

[Staff Browser] ----admin----> [Admin Panel]
                                  |
         +------------------------+------------------------+
         v                        v                        v
  [Orders/Warehouse]       [Finances]              [HR / Docs / Tasks]
  Order, Cargo, Stock      Invoices page           Documents, Employees
                           BankStatements          Contracts, Leave,
                           NewInvoice              Trips, Calendar, Todos
         |                        |                        |
         +------------+-----------+-----------+------------+
                      v                       v
               [Eloquent Models]        [app/Services]
                      |                       |
                      v           +-----------+-----------+-----------+
                   [Database]     v           v           v           v
                            SharePoint    Dokobit    Bank XML/OCR   Outlook
                            Graph API     Gateway    Importers      Calendar
                                          +----- email / Rekvizitai -----+
                                                      ^
                                              [Scheduler + Jobs]
TXT;

        $pdf = Pdf::loadView('pdf.system-architecture', [
            'generatedAt' => now()->format('Y-m-d H:i'),
            'diagram' => $diagram,
            'modules' => [
                ['name' => 'Orders', 'purpose' => 'Sales orders, stock settlement vs invoices.'],
                ['name' => 'Stock', 'purpose' => 'Cargos, warehouses, imports, stock, packages, destinations, shipping settings.'],
                ['name' => 'Finances', 'purpose' => 'Invoices, bank statements, payments, counterpart contacts, PVM/i.SAF/balance.'],
                ['name' => 'Reports', 'purpose' => 'Ops/finance charts and LT tax/payroll reports (GPM, FR, PVM, packing, late payments, …).'],
                ['name' => 'Catalog', 'purpose' => 'Products, colors, collections, pricing, customer levels.'],
                ['name' => 'Contacts (CRM)', 'purpose' => 'Directory contacts board/stages — sales pipeline (not invoice counterparties).'],
                ['name' => 'Documents', 'purpose' => 'Document library, approvals, confirmations, incomplete/verify flows.'],
                ['name' => 'Tasks', 'purpose' => 'Todos, projects, kanban/boards/calendar/timeline, notes, expenses.'],
                ['name' => 'Employees & contracts', 'purpose' => 'Employees, contracts/signing, leave/overtime/trips, schedules, payroll UI.'],
                ['name' => 'System', 'purpose' => 'Users, company, currencies, SharePoint/Dokobit/Outlook settings, logs.'],
                ['name' => 'Financial options', 'purpose' => 'VAT, income/expense types, invoice series, allowances, write-off options.'],
            ],
            'integrations' => [
                ['name' => 'Microsoft SharePoint', 'entry' => 'SharepointGraphClient, uploaders, SharepointSettings.'],
                ['name' => 'Dokobit', 'entry' => 'DokobitGatewayClient, contract/document signing, sync-dokobit-signings.'],
                ['name' => 'Bank statements', 'entry' => 'BankStatements/* parsers & matchers (XML/Excel/PDF).'],
                ['name' => 'OCR / AI invoices', 'entry' => 'InvoiceOcrService, InvoiceAiMapper (OpenAI), NewInvoice prefill.'],
                ['name' => 'Rekvizitai.lt', 'entry' => 'RekvizitaiLtClient, SyncContactsFromRekvizitai.'],
                ['name' => 'Email', 'entry' => 'Laravel Mail, *Mailer services, EmailLog, EmailTestMode.'],
                ['name' => 'Outlook calendar', 'entry' => 'CalendarSyncService + Sync/Renew Microsoft calendar jobs.'],
            ],
        ])->setPaper('a4', 'portrait');

        File::put($path, $pdf->output());

        $this->info('Architecture PDF written to: '.$path);

        return self::SUCCESS;
    }
}
