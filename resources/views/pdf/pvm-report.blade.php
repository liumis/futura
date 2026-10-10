<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22px 20px 28px; }
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
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .title {
            font-size: 16px;
            font-weight: 700;
            color: #111111;
            margin: 0 0 2px;
        }
        .subtitle { font-size: 10px; color: #64748b; margin: 0 0 4px; }
        .meta { color: #64748b; font-size: 8px; }
        .company { margin-bottom: 10px; }
        .company-name { font-size: 10px; font-weight: 700; }
        h2 {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #111111;
            margin: 12px 0 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 3px 4px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f1f5f9;
            font-size: 7.5px;
            text-transform: uppercase;
        }
        .right { text-align: right; }
        .totals td { font-weight: 700; background: #f8fafc; }
        .empty { text-align: center; color: #64748b; padding: 12px 6px; }
        .footer { margin-top: 12px; color: #64748b; font-size: 7.5px; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">{{ $title }}</p>
        <p class="subtitle">{{ $subtitle }}</p>
        <p class="meta">{{ $periodLabel }} · {{ $companyLabel }} · {{ $directionLabel }}</p>
    </div>

    <div class="company">
        <div class="company-name">{{ $company->company_name ?: 'Company' }}</div>
        @if (filled($company->company_id))
            <div>Company ID: {{ $company->company_id }}</div>
        @endif
    </div>

    <h2>PVM summary / PVM suvestinė</h2>
    <table>
        <thead>
            <tr>
                <th>Rate</th>
                <th class="right">Sales net</th>
                <th class="right">Output VAT</th>
                <th class="right">Purchase net</th>
                <th class="right">Input VAT</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($groups as $group)
                <tr>
                    <td>{{ $group['rate_label'] }}</td>
                    <td class="right">{{ $group['sales_base_label'] }}</td>
                    <td class="right">{{ $group['output_vat_label'] }}</td>
                    <td class="right">{{ $group['purchase_base_label'] }}</td>
                    <td class="right">{{ $group['input_vat_label'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="empty">No PVM amounts for this period.</td>
                </tr>
            @endforelse
            @if ($groups->isNotEmpty())
                <tr class="totals">
                    <td class="right">Total</td>
                    <td class="right">{{ $salesBaseLabel }}</td>
                    <td class="right">{{ $outputVatLabel }}</td>
                    <td class="right">{{ $purchaseBaseLabel }}</td>
                    <td class="right">{{ $inputVatLabel }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <p>
        <strong>{{ $isPayable ? 'PVM payable' : 'PVM refundable' }}:</strong>
        {{ $payableLabel }}
        (output − input)
        @if ($unclassifiedVat > 0.004)
            · Unclassified VAT {{ $unclassifiedVatLabel }} is listed below and is not included in payable.
        @endif
    </p>

    <h2>Invoices</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Invoice no</th>
                <th>Company</th>
                <th>Type</th>
                <th>Rate</th>
                <th class="right">Net</th>
                <th class="right">VAT</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $line['invoice_number'] }}</td>
                    <td>{{ $line['company'] }}</td>
                    <td>{{ $line['direction'] }}</td>
                    <td>{{ $line['rate'] }}</td>
                    <td class="right">{{ $line['base'] }}</td>
                    <td class="right">{{ $line['vat'] }}</td>
                    <td class="right">{{ $line['total'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="empty">No invoices for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Generated {{ $generatedAt }}
        · Sales invoices (income type) = output VAT (pardavimo PVM)
        · Purchase invoices (expense type) = input VAT (pirkimo PVM)
    </div>
</body>
</html>
