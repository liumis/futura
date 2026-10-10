<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $audienceLabel }}</title>
    <style>
        @page { margin: 22px 20px 28px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #1a1f2e;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            line-height: 1.3;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #111111;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .title {
            font-size: 16px;
            font-weight: 700;
            color: #111111;
            margin: 0 0 2px;
        }
        .subtitle {
            font-size: 10px;
            color: #64748b;
            margin: 0 0 4px;
        }
        .meta { color: #64748b; font-size: 8px; }
        .company { margin-bottom: 10px; }
        .company-name { font-size: 10px; font-weight: 700; }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 3px 3px;
            text-align: left;
            vertical-align: top;
            word-wrap: break-word;
        }
        th {
            background: #f1f5f9;
            font-size: 7px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .right { text-align: right; }
        .totals td {
            font-weight: 700;
            background: #f8fafc;
        }
        .empty {
            text-align: center;
            color: #64748b;
            padding: 14px 6px;
        }
        .footer {
            margin-top: 12px;
            color: #64748b;
            font-size: 7.5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">{{ $title }}</p>
        <p class="subtitle">{{ $audienceLabel }}</p>
        <p class="meta">{{ $periodLabel }} · {{ $packageLabel }}</p>
    </div>

    <div class="company">
        <div class="company-name">{{ $company->company_name ?: 'Company' }}</div>
        @if (filled($company->company_id))
            <div>Company ID: {{ $company->company_id }}</div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 6%;">Order</th>
                <th style="width: 8%;">Date</th>
                <th style="width: 16%;">Customer</th>
                <th style="width: 7%;">Type</th>
                <th style="width: 9%;">Package</th>
                <th style="width: 8%;">Tracking</th>
                <th class="right" style="width: 5%;">Items</th>
                <th class="right" style="width: 6%;">Pkgs</th>
                <th class="right" style="width: 6%;">Pallets</th>
                <th class="right" style="width: 7%;">Netto</th>
                <th class="right" style="width: 7%;">Brutto</th>
                <th class="right" style="width: 6%;">Plastic</th>
                <th class="right" style="width: 6%;">Card. I</th>
                <th class="right" style="width: 6%;">Card. II</th>
                <th class="right" style="width: 6%;">Wood</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line['id'] }}</td>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $line['customer'] }}</td>
                    <td>{{ $line['audience'] }}</td>
                    <td>{{ $line['package'] }}</td>
                    <td>{{ $line['tracking'] }}</td>
                    <td class="right">{{ $line['items'] }}</td>
                    <td class="right">{{ $line['packages'] }}</td>
                    <td class="right">{{ $line['palletes'] }}</td>
                    <td class="right">{{ $line['netto'] }}</td>
                    <td class="right">{{ $line['brutto'] }}</td>
                    <td class="right">{{ $line['plastic'] }}</td>
                    <td class="right">{{ $line['cardboard_i'] }}</td>
                    <td class="right">{{ $line['cardboard_ii'] }}</td>
                    <td class="right">{{ $line['wood'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="15" class="empty">No packing rows for this report.</td>
                </tr>
            @endforelse
            @if ($lines->isNotEmpty())
                <tr class="totals">
                    <td colspan="6" class="right">Total</td>
                    <td class="right">{{ $itemsTotal }}</td>
                    <td class="right">{{ $packagesTotal }}</td>
                    <td class="right">{{ $palletesTotal }}</td>
                    <td class="right">{{ $nettoTotal }}</td>
                    <td class="right">{{ $bruttoTotal }}</td>
                    <td class="right">{{ $plasticTotal }}</td>
                    <td class="right">{{ $cardboardITotal }}</td>
                    <td class="right">{{ $cardboardIITotal }}</td>
                    <td class="right">{{ $woodTotal }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Generated {{ $generatedAt }} · {{ $ordersCount }} order(s) · {{ $audienceLabel }}
    </div>
</body>
</html>
