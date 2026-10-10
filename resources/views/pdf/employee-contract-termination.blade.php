<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employment contract termination</title>
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
            color: #111111;
            margin: 0 0 6px;
        }
        .meta { color: #64748b; margin-bottom: 18px; }
        .section { margin-bottom: 16px; }
        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #111111;
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
            font-weight: 700;
        }
        .reason {
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 28px;
            color: #64748b;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <p class="title">Employment contract termination agreement</p>
    <p class="meta">
        Generated {{ now()->format('Y-m-d H:i:s') }}
        · Contract #{{ $contract->getKey() }}
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
        <div class="section-title">Terminated contract</div>
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
                <th>Valid to (previous)</th>
                <td>{{ optional($contract->valid_to)->format('Y-m-d') ?? 'open-ended' }}</td>
            </tr>
            <tr>
                <th>Termination date</th>
                <td>{{ optional($contract->termination_date)->format('Y-m-d') ?? '—' }}</td>
            </tr>
            <tr>
                <th>Reason</th>
                <td class="reason">{{ $contract->termination_reason ?: '—' }}</td>
            </tr>
        </table>
    </div>

    <p class="footer">
        This document is prepared for electronic signing via Dokobit.
        After all selected parties have signed, the employment contract ends on the termination date
        and the employee is marked inactive.
    </p>
</body>
</html>
