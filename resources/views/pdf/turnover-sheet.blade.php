<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22px 24px 28px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #1a1f2e;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5px;
            line-height: 1.35;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #111111;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .title {
            font-size: 18px;
            font-weight: 700;
            color: #111111;
            margin: 0 0 2px;
        }
        .subtitle {
            font-size: 11px;
            color: #64748b;
            margin: 0 0 4px;
        }
        .meta { color: #64748b; font-size: 8.5px; }
        .company { margin-bottom: 12px; }
        .company-name { font-size: 11px; font-weight: 700; }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 3px 4px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #f1f5f9;
            font-size: 7.5px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        th.group { text-align: center; }
        .right { text-align: right; }
        .totals td {
            font-weight: 700;
            background: #f8fafc;
        }
        .empty {
            text-align: center;
            color: #64748b;
            padding: 16px 6px;
        }
        .name { word-wrap: break-word; }
        .footer {
            margin-top: 14px;
            color: #64748b;
            font-size: 8px;
        }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">{{ $title }}</p>
        <p class="subtitle">{{ $subtitle }}</p>
        <p class="meta">{{ $periodLabel }} · {{ $supplierLabel }}</p>
    </div>

    <div class="company">
        <div class="company-name">{{ $company->company_name ?: 'Company' }}</div>
        @if (filled($company->company_id))
            <div>Company ID: {{ $company->company_id }}</div>
        @endif
        @if (filled($company->company_address))
            <div>{{ $company->company_address }}</div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2" style="width: 9%;">Code</th>
                <th rowspan="2" style="width: 27%;">Name</th>
                <th class="group" colspan="2">Opening / Pradinis likutis</th>
                <th class="group" colspan="2">Turnover / Apyvarta</th>
                <th class="group" colspan="2">Closing / Galutinis likutis</th>
            </tr>
            <tr>
                <th class="right">Debit</th>
                <th class="right">Credit</th>
                <th class="right">Debit</th>
                <th class="right">Credit</th>
                <th class="right">Debit</th>
                <th class="right">Credit</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line['code'] }}</td>
                    <td class="name">{{ $line['name'] }}</td>
                    <td class="right">{{ $line['opening_debit'] }}</td>
                    <td class="right">{{ $line['opening_credit'] }}</td>
                    <td class="right">{{ $line['turnover_debit'] }}</td>
                    <td class="right">{{ $line['turnover_credit'] }}</td>
                    <td class="right">{{ $line['closing_debit'] }}</td>
                    <td class="right">{{ $line['closing_credit'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="empty">No account turnovers for this period.</td>
                </tr>
            @endforelse
            @if ($lines->isNotEmpty())
                <tr class="totals">
                    <td colspan="2" class="right">Total</td>
                    <td class="right">{{ $openingDebitTotal }}</td>
                    <td class="right">{{ $openingCreditTotal }}</td>
                    <td class="right">{{ $turnoverDebitTotal }}</td>
                    <td class="right">{{ $turnoverCreditTotal }}</td>
                    <td class="right">{{ $closingDebitTotal }}</td>
                    <td class="right">{{ $closingCreditTotal }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Generated {{ $generatedAt }}
        · {{ $lines->count() }} account(s)
        · Opening {{ $openingBalanced ? 'balanced' : 'not balanced' }}
        · Turnover {{ $turnoverBalanced ? 'balanced' : 'not balanced' }}
        · Closing {{ $closingBalanced ? 'balanced' : 'not balanced' }}
        · Opening and closing balances are shown on the debit or credit side only (Lithuanian double-entry).
    </div>
</body>
</html>
