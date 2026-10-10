<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 32px 36px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #1a1f2e;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.45;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #111111;
            padding-bottom: 12px;
            margin-bottom: 16px;
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
            margin: 0 0 6px;
        }
        .meta { color: #64748b; font-size: 9.5px; }
        .company { margin-bottom: 16px; }
        .company-name { font-size: 12px; font-weight: 700; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border-bottom: 1px solid #e2e8f0;
            padding: 6px 4px;
            vertical-align: top;
        }
        th {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            text-align: left;
        }
        .right { text-align: right; }
        .lt {
            display: block;
            color: #64748b;
            font-size: 8px;
            font-weight: 400;
        }
        .subtotal td, .total td {
            font-weight: 700;
            border-top: 1px solid #94a3b8;
        }
        .total td {
            border-bottom: 2px solid #111111;
            font-size: 11px;
        }
        .footer {
            margin-top: 18px;
            color: #64748b;
            font-size: 8.5px;
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
                <th>Line</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr class="{{ $line['style'] }}">
                    <td>
                        {{ $line['label'] }}
                        <span class="lt">{{ $line['label_lt'] }}</span>
                    </td>
                    <td class="right">{{ $line['amount_label'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generated {{ $generatedAt }}
        · Revenue {{ $revenueLabel }}
        · Expenses {{ $expensesLabel }}
        · {{ $isProfit ? 'Net profit' : 'Net loss' }} {{ $netLabel }}
        · Income (class 5) = credit − debit; expenses (class 6) = debit − credit.
    </div>
</body>
</html>
