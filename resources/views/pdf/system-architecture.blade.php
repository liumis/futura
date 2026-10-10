<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>FuturaTextiles SS — System Architecture</title>
    <style>
        @page { margin: 28px 32px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1f2937;
            line-height: 1.35;
        }
        h1 { font-size: 18px; margin: 0 0 4px; color: #111827; }
        h2 {
            font-size: 12px;
            margin: 16px 0 6px;
            padding-bottom: 3px;
            border-bottom: 1px solid #d1d5db;
            color: #111827;
        }
        h3 { font-size: 10.5px; margin: 10px 0 4px; color: #374151; }
        p { margin: 0 0 6px; }
        .meta { color: #6b7280; margin-bottom: 12px; font-size: 9px; }
        .box {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 8px 10px;
            margin: 0 0 8px;
            background: #f8fafc;
        }
        .layer-title { font-weight: bold; color: #0f172a; margin-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 4px 6px;
            vertical-align: top;
            text-align: left;
        }
        th { background: #f3f4f6; font-weight: bold; }
        .muted { color: #6b7280; }
        ul { margin: 0 0 6px 16px; padding: 0; }
        li { margin: 0 0 2px; }
        .flow {
            border-left: 3px solid #64748b;
            padding: 4px 0 4px 8px;
            margin: 0 0 8px;
        }
        .diagram {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8px;
            line-height: 1.25;
            white-space: pre;
            border: 1px solid #e5e7eb;
            background: #fff;
            padding: 8px;
            margin: 0 0 8px;
        }
        .footer {
            margin-top: 14px;
            font-size: 8px;
            color: #9ca3af;
        }
        .two col { float: left; width: 49%; }
        .clear { clear: both; }
    </style>
</head>
<body>
    <h1>FuturaTextiles SS — System Architecture</h1>
    <p class="meta">Generated {{ $generatedAt }} · Laravel 12 + Filament 5 dual-panel ERP</p>

    <h2>1. Overview</h2>
    <p>
        <strong>FuturaTextiles SS</strong> is an internal ERP for textiles operations. Staff use the
        <strong>Admin</strong> Filament panel for orders, warehouse, Lithuanian finance/tax, HR &amp; payroll,
        documents, and projects. External customers use a separate <strong>Customer</strong> panel
        (<code>/customer</code>) to manage orders. Domain logic lives in Eloquent models
        (<code>app/Models</code>) and services (<code>app/Services</code>); integrations cover SharePoint,
        Dokobit e-sign, bank statement import/matching, OCR/AI invoice fill, Rekvizitai, email, and Outlook calendar.
    </p>

    <h2>2. Layered architecture</h2>
    <div class="box">
        <div class="layer-title">Presentation</div>
        <div class="muted">app/Filament/Admin/*, app/Filament/Customer/*, AdminPanelProvider, CustomerPanelProvider, Livewire pages (Invoices, BankStatements, AbsenceCalendar, …)</div>
    </div>
    <div class="box">
        <div class="layer-title">Domain</div>
        <div class="muted">app/Models/*, app/Enums/* (AdminModule, OrderStatus, BankStatementDirection, leave/overtime/trip statuses, …)</div>
    </div>
    <div class="box">
        <div class="layer-title">Services</div>
        <div class="muted">app/Services/* — matchers, PDF/report generators, payroll/tax calculators, mailers, signers, OCR</div>
    </div>
    <div class="box">
        <div class="layer-title">Integrations</div>
        <div class="muted">SharePoint Graph, Dokobit Gateway, Bank XML/Excel/PDF importers, OpenAI invoice mapper, Rekvizitai, Outlook calendar jobs</div>
    </div>
    <div class="box">
        <div class="layer-title">Data</div>
        <div class="muted">Eloquent + MySQL; DocumentBinaryStore / public disk; SharePoint file IDs; EmailLog; ActivityLog</div>
    </div>

    <h2>3. High-level diagram</h2>
    <div class="diagram">{{ $diagram }}</div>

    <h2>4. Admin modules</h2>
    <table>
        <thead>
            <tr>
                <th style="width:22%">Module</th>
                <th>Purpose</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($modules as $module)
                <tr>
                    <td><strong>{{ $module['name'] }}</strong></td>
                    <td>{{ $module['purpose'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted">Customer panel exposes only Orders (create / list / edit).</p>

    <h2>5. Auth &amp; access</h2>
    <ul>
        <li><strong>Spatie roles</strong> on User: admin, manager, accountant, employee, customer, project manager.</li>
        <li><strong>Panels:</strong> admin → staff or project manager; customer → customer role only.</li>
        <li><strong>Modules:</strong> staff see all; project managers get Tasks + optional <code>users.modules</code> (AdminModule).</li>
        <li>Resources/pages call <code>canAccessNavigationGroup()</code> / <code>canAccessModule()</code>.</li>
    </ul>

    <h2>6. Core domain relationships</h2>
    <ul>
        <li><strong>Order</strong> → OrderItems → Product/Color; Order → Invoices → Contact.</li>
        <li><strong>Invoice</strong> ↔ BankStatementTransaction (FK + pivot amounts); also links to Payment / salary / trip / Document.</li>
        <li><strong>BankStatementImport</strong> → BankStatementTransaction rows.</li>
        <li><strong>Employee</strong> → contracts, leave, overtime, business trips, schedules, monthly/one-time payments.</li>
        <li><strong>Document</strong> → DocumentType, approvers, confirmations, Dokobit signing, SharePoint metadata.</li>
        <li><strong>Todo (Tasks)</strong> → Project; Outlook calendar sync.</li>
        <li><strong>DirectoryContact</strong> (CRM board) is separate from invoice Contact counterparties.</li>
    </ul>

    <h2>7. Key data flows</h2>
    <h3>Invoice ↔ bank payment</h3>
    <div class="flow">
        Upload on Bank Statements → BankStatementImporter (XML / Excel / PDF) → BankStatementTransaction →
        InvoiceMatcher / PaymentMatcher / salary &amp; trip matchers → link (invoice_id and/or pivot) →
        invoice paid_amount / paid updated.
    </div>
    <h3>Employee contract signing</h3>
    <div class="flow">
        EmployeeContract → PDF generator → Document → EmployeeContractSigning + Dokobit →
        sync command polls status → signed file often to SharePoint. Employee becomes inactive after signed termination.
    </div>
    <h3>Document confirmation</h3>
    <div class="flow">
        Document assigns confirmation users → DocumentConfirmation (due dates) → scheduled notify → user confirms in UI
        (separate from approval / Dokobit signing).
    </div>

    <h2>8. Integrations</h2>
    <table>
        <thead>
            <tr>
                <th style="width:22%">System</th>
                <th>Entry points</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($integrations as $row)
                <tr>
                    <td><strong>{{ $row['name'] }}</strong></td>
                    <td>{{ $row['entry'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>9. Scheduled work</h2>
    <p>
        Currencies update; low-stock / draft-order / document expiration &amp; confirmation notifications;
        Dokobit signing sync (~5 min); activity log prune; Outlook subscription renew (hourly) + delta sync (~15 min).
        Plus many ad-hoc import/normalize Artisan commands under <code>app/Console/Commands</code>.
    </p>

    <h2>10. Important code map</h2>
    <table>
        <thead>
            <tr>
                <th style="width:34%">Area</th>
                <th>Path</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Admin UI</td><td>app/Filament/Admin/</td></tr>
            <tr><td>Customer UI</td><td>app/Filament/Customer/</td></tr>
            <tr><td>Domain models</td><td>app/Models/</td></tr>
            <tr><td>Business services</td><td>app/Services/</td></tr>
            <tr><td>Bank statement pipeline</td><td>app/Services/BankStatements/</td></tr>
            <tr><td>Panel providers</td><td>app/Providers/Filament/</td></tr>
            <tr><td>Module enum</td><td>app/Enums/AdminModule.php</td></tr>
            <tr><td>Scheduler</td><td>routes/console.php</td></tr>
        </tbody>
    </table>

    <p class="footer">
        FuturaTextiles SS architecture overview · regenerate with
        <code>php artisan docs:architecture-pdf</code>
    </p>
</body>
</html>
