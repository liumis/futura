<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employment contract</title>
    <style>
        @page { margin: 36px 40px; }
        body {
            margin: 0;
            color: #1a1f2e;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.5;
        }
        .title {
            font-size: 18px;
            font-weight: 700;
            color: #2b3a67;
            margin: 0 0 6px;
        }
        .meta { color: #64748b; margin-bottom: 18px; }
        .section { margin-bottom: 16px; }
        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #2b3a67;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            width: 34%;
            background: #f1f5f9;
            font-weight: 600;
        }
        .footer {
            margin-top: 28px;
            color: #64748b;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <p class="title">Employment contract</p>
    <p class="meta">
        Generated {{ now()->format('Y-m-d H:i:s') }}
        · Status: {{ $contract->status?->label() ?? '—' }}
    </p>

    <div class="section">
        <div class="section-title">Employer</div>
        <div>{{ $company->company_name ?: 'Company' }}</div>
        @if (filled($company->company_id))
            <div>Company ID: {{ $company->company_id }}</div>
        @endif
        @if (filled($company->company_address))
            <div>{{ $company->company_address }}</div>
        @endif
        @if (filled($company->company_email))
            <div>{{ $company->company_email }}</div>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Employee</div>
        <table>
            <tr>
                <th>Full name</th>
                <td>{{ $employee?->fullName() ?? '—' }}</td>
            </tr>
            <tr>
                <th>Position</th>
                <td>{{ $employee?->position ?: '—' }}</td>
            </tr>
            <tr>
                <th>Email</th>
                <td>{{ $employee?->email ?: '—' }}</td>
            </tr>
            <tr>
                <th>Phone</th>
                <td>{{ $employee?->phone ?: '—' }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Contract terms</div>
        <table>
            <tr>
                <th>Sign date</th>
                <td>{{ optional($contract->sign_date)->format('Y-m-d') ?? '—' }}</td>
            </tr>
            <tr>
                <th>Effective from</th>
                <td>{{ optional($contract->effective_date_from)->format('Y-m-d') ?? '—' }}</td>
            </tr>
            <tr>
                <th>Valid to</th>
                <td>{{ optional($contract->valid_to)->format('Y-m-d') ?? '—' }}</td>
            </tr>
            <tr>
                <th>Base salary</th>
                <td>{{ $baseSalary }}</td>
            </tr>
            <tr>
                <th>Default bonus</th>
                <td>{{ $defaultBonus ?? '—' }}</td>
            </tr>
            <tr>
                <th>State percentage</th>
                <td>{{ $contract->state_percentage !== null ? rtrim(rtrim(number_format((float) $contract->state_percentage, 2, '.', ''), '0'), '.').'%' : '—' }}</td>
            </tr>
        </table>
    </div>

    <p class="footer">
        This document is prepared for electronic signing via Dokobit.
        Signatures of the selected parties will be appended after successful signing.
    </p>
</body>
</html>
