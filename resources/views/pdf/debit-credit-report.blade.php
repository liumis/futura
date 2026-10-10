<!DOCTYPE html>
<html lang="en">
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
            margin-bottom: 18px;
        }
        .title {
            font-size: 20px;
            font-weight: 700;
            color: #111111;
            margin: 0 0 4px;
        }
        .meta { color: #64748b; font-size: 10px; }
        .company { margin-bottom: 18px; }
        .company-name { font-size: 13px; font-weight: 700; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f1f5f9;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .right { text-align: right; }
        .totals td {
            font-weight: 700;
            background: #f8fafc;
        }
        .empty {
            text-align: center;
            color: #64748b;
            padding: 18px 6px;
        }
        .footer {
            margin-top: 24px;
            color: #64748b;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">{{ $title }}</p>
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
                <th>Date</th>
                <th>Invoice no</th>
                <th>Supplier</th>
                <th>Code</th>
                <th>Name</th>
                <th class="right">Debit</th>
                <th class="right">Credit</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $line['invoice_number'] }}</td>
                    <td>{{ $line['supplier'] }}</td>
                    <td>{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="right">{{ $line['debit'] }}</td>
                    <td class="right">{{ $line['credit'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="empty">No finance lines for this period.</td>
                </tr>
            @endforelse
            @if ($lines->isNotEmpty())
                <tr class="totals">
                    <td colspan="5" class="right">Total</td>
                    <td class="right">{{ $debitTotal }}</td>
                    <td class="right">{{ $creditTotal }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Generated {{ $generatedAt }}
        · {{ $lines->count() }} line(s)
        · {{ $balanced ? 'Balanced' : 'Debit and credit differ' }}
    </div>
</body>
</html>
