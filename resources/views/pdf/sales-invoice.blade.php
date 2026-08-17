<!DOCTYPE html>

<html lang="{{ $locale ?? 'en' }}">

<head>

    <meta charset="utf-8">

    <title>{{ ($t['invoice_title'] ?? 'INVOICE') }} {{ $invoiceNumber }}</title>

    <style>

        @page {

            margin: 28px 32px 36px;

        }



        * {

            box-sizing: border-box;

        }



        body {

            margin: 0;

            padding: 0;

            color: #1a1f2e;

            font-family: DejaVu Sans, sans-serif;

            font-size: 11px;

            line-height: 1.45;

        }



        .header {

            width: 100%;

            border-bottom: 2px solid {{ $brandColor }};

            padding-bottom: 14px;

            margin-bottom: 22px;

        }



        .header-table {

            width: 100%;

            border-collapse: collapse;

        }



        .header-table td {

            vertical-align: top;

            padding: 0;

        }



        .logo {

            max-width: 190px;

            max-height: 54px;

        }



        .invoice-title {

            text-align: right;

        }



        .invoice-title h1 {

            margin: 0 0 6px;

            font-size: 26px;

            font-weight: 700;

            color: {{ $brandColor }};

            letter-spacing: 0.5px;

        }



        .invoice-meta {

            font-size: 11px;

            color: #4a5568;

        }



        .invoice-meta strong {

            color: #1a1f2e;

        }



        .parties {

            width: 100%;

            border-collapse: collapse;

            margin-bottom: 22px;

        }



        .parties td {

            width: 50%;

            vertical-align: top;

            padding: 0 12px 0 0;

        }



        .party-box {

            border: 1px solid #e2e8f0;

            border-radius: 6px;

            padding: 12px 14px;

            min-height: 118px;

        }



        .party-label {

            font-size: 9px;

            font-weight: 700;

            letter-spacing: 0.8px;

            text-transform: uppercase;

            color: {{ $brandColor }};

            margin-bottom: 8px;

        }



        .party-name {

            font-size: 13px;

            font-weight: 700;

            margin-bottom: 6px;

            color: #1a1f2e;

        }



        .party-line {

            margin: 0 0 3px;

            color: #4a5568;

        }



        .items-table {

            width: 100%;

            border-collapse: collapse;

            margin-bottom: 16px;

        }



        .items-table thead th {

            background: {{ $brandColor }};

            color: #ffffff;

            font-size: 9px;

            font-weight: 700;

            letter-spacing: 0.4px;

            text-transform: uppercase;

            padding: 8px 7px;

            text-align: left;

        }



        .items-table thead th.num {

            text-align: right;

        }



        .items-table tbody td {

            border-bottom: 1px solid #e8ecf1;

            padding: 8px 7px;

            vertical-align: top;

            color: #334155;

        }



        .items-table tbody td.num {

            text-align: right;

            white-space: nowrap;

        }



        .items-table tbody tr:nth-child(even) td {

            background: #fafbfc;

        }



        .summary-wrap {

            width: 100%;

            border-collapse: collapse;

        }



        .summary-wrap td {

            vertical-align: top;

            padding: 0;

        }



        .notes {

            width: 58%;

            padding-right: 16px;

            color: #64748b;

            font-size: 10px;

        }



        .totals {

            width: 42%;

        }



        .totals-table {

            width: 100%;

            border-collapse: collapse;

            border: 1px solid #e2e8f0;

            border-radius: 6px;

        }



        .totals-table td {

            padding: 7px 10px;

            border-bottom: 1px solid #edf2f7;

        }



        .totals-table tr:last-child td {

            border-bottom: none;

        }



        .totals-table td.label {

            color: #64748b;

        }



        .totals-table td.value {

            text-align: right;

            font-weight: 600;

            color: #1a1f2e;

            white-space: nowrap;

        }



        .totals-table tr.grand td {

            background: {{ $brandColor }};

            color: #ffffff;

            font-size: 12px;

            font-weight: 700;

            padding: 10px;

        }



        .totals-table tr.grand td.label {

            color: #ffffff;

        }



        .footer {

            position: fixed;

            left: 32px;

            right: 32px;

            bottom: 18px;

            border-top: 1px solid #e2e8f0;

            padding-top: 8px;

            font-size: 9px;

            color: #94a3b8;

            text-align: center;

        }



        .package-info {

            margin-top: 18px;

            padding-top: 12px;

            border-top: 1px solid #e2e8f0;

            font-size: 10px;

            color: #334155;

            line-height: 1.55;

        }



        .package-info p {

            margin: 0 0 4px;

        }



        .vat-disclaimer {

            margin-top: 8px;

            color: #64748b;

            font-size: 9px;

        }

    </style>

</head>

<body>

    <div class="header">

        <table class="header-table">

            <tr>

                <td style="width: 55%;">

                    @if ($logoDataUri)

                        <img src="{{ $logoDataUri }}" alt="Futura Textiles" class="logo">

                    @else

                        <div style="font-size: 18px; font-weight: 700; color: {{ $brandColor }};">Futura Textiles</div>

                    @endif

                </td>

                <td class="invoice-title" style="width: 45%;">

                    <h1>{{ $t['invoice_title'] ?? 'INVOICE' }}</h1>

                    <div class="invoice-meta">

                        <div><strong>{{ $t['invoice_no'] ?? 'Invoice no:' }}</strong> {{ $invoiceNumber }}</div>

                        <div><strong>{{ $t['invoice_date'] ?? 'Invoice date:' }}</strong> {{ $invoiceDate }}</div>

                        <div><strong>{{ $t['order_no'] ?? 'Order no:' }}</strong> #{{ $orderNumber }}</div>

                        @if (filled($trackingNumber))

                            <div><strong>{{ $t['tracking'] ?? 'Tracking:' }}</strong> {{ $trackingNumber }}</div>

                        @endif

                    </div>

                </td>

            </tr>

        </table>

    </div>



    <table class="parties">

        <tr>

            <td>

                <div class="party-box">

                    <div class="party-label">{{ $t['from'] ?? 'From' }}</div>

                    <div class="party-name">{{ $issuer['name'] ?? '—' }}</div>

                    @if (filled($issuer['companyId']))

                        <p class="party-line">{{ $t['company_id'] ?? 'Company ID:' }} {{ $issuer['companyId'] }}</p>

                    @endif

                    @if (filled($issuer['vat']))

                        <p class="party-line">{{ $t['vat'] ?? 'VAT:' }} {{ $issuer['vat'] }}</p>

                    @endif

                    @if (filled($issuer['address']))

                        <p class="party-line">{{ $issuer['address'] }}</p>

                    @endif

                    @if (filled($issuer['country']))

                        <p class="party-line">{{ $issuer['country'] }}</p>

                    @endif

                    @if (filled($issuer['email']))

                        <p class="party-line">{{ $issuer['email'] }}</p>

                    @endif

                    @if (filled($issuer['phone']))

                        <p class="party-line">{{ $issuer['phone'] }}</p>

                    @endif

                </div>

            </td>

            <td>

                <div class="party-box">

                    <div class="party-label">{{ $t['bill_to'] ?? 'Bill to' }}</div>

                    <div class="party-name">{{ $customer['name'] ?? '—' }}</div>

                    @if (filled($customer['companyId']))

                        <p class="party-line">{{ $t['company_id'] ?? 'Company ID:' }} {{ $customer['companyId'] }}</p>

                    @endif

                    @if (filled($customer['vat']))

                        <p class="party-line">{{ $t['vat'] ?? 'VAT:' }} {{ $customer['vat'] }}</p>

                    @endif

                    @if (filled($customer['address']))

                        <p class="party-line">{{ $customer['address'] }}</p>

                    @endif

                    @if (filled($customer['country']))

                        <p class="party-line">{{ $customer['country'] }}</p>

                    @endif

                    @if (filled($customer['email']))

                        <p class="party-line">{{ $customer['email'] }}</p>

                    @endif

                    @if (filled($customer['phone']))

                        <p class="party-line">{{ $customer['phone'] }}</p>

                    @endif

                </div>

            </td>

        </tr>

    </table>



    <table class="items-table">

        <thead>

            <tr>

                <th style="width: 18%;">{{ $t['collection'] ?? 'Collection' }}</th>

                <th style="width: 20%;">{{ $t['color'] ?? 'Color' }}</th>

                <th style="width: 14%;">{{ $t['product_code'] ?? 'Product code' }}</th>

                <th class="num" style="width: 8%;">{{ $t['size_m'] ?? 'Size (m)' }}</th>

                <th class="num" style="width: 8%;">{{ $t['qty'] ?? 'Qty' }}</th>

                <th class="num" style="width: 14%;">{{ $t['unit_price'] ?? 'Unit price' }}</th>

                <th class="num" style="width: 14%;">{{ $t['line_total'] ?? 'Line total' }}</th>

            </tr>

        </thead>

        <tbody>

            @forelse ($lines as $line)

                <tr>

                    <td>{{ $line['collection'] }}</td>

                    <td>{{ $line['color'] }}</td>

                    <td>{{ $line['product_code'] }}</td>

                    <td class="num">{{ $line['size'] }}</td>

                    <td class="num">{{ number_format($line['amount']) }}</td>

                    <td class="num">{{ \App\Support\Money::format($line['unit_price']) }}</td>

                    <td class="num">{{ \App\Support\Money::format($line['line_total']) }}</td>

                </tr>

            @empty

                <tr>

                    <td colspan="7" style="text-align: center; color: #94a3b8; padding: 18px;">{{ $t['no_line_items'] ?? 'No line items' }}</td>

                </tr>

            @endforelse

        </tbody>

    </table>



    <table class="summary-wrap">

        <tr>

            <td class="notes">

                @if (filled($vatClassificator ?? null))

                    <div><strong>{{ $t['pvm_vat'] ?? 'PVM/VAT' }}:</strong> {{ $vatClassificator }}</div>

                @elseif ($vatRate > 0)

                    <div>{{ $t['vat_rate_applied'] ?? 'VAT rate applied:' }} {{ number_format($vatRate, 2) }}%</div>

                @endif

                <div style="margin-top: 6px;">{{ $t['thank_you'] ?? 'Thank you for your business.' }}</div>

            </td>

            <td class="totals">

                <table class="totals-table">

                    <tr>

                        <td class="label">{{ $t['subtotal'] ?? 'Subtotal' }}</td>

                        <td class="value">{{ \App\Support\Money::format($subtotal) }}</td>

                    </tr>

                    <tr>

                        <td class="label">{{ $t['shipping'] ?? 'Shipping' }}</td>

                        <td class="value">{{ \App\Support\Money::format($shipping) }}</td>

                    </tr>

                    <tr>

                        <td class="label">{{ $t['amount_excl_vat'] ?? 'Amount excl. VAT' }}</td>

                        <td class="value">{{ \App\Support\Money::format($sumWithoutVat) }}</td>

                    </tr>

                    <tr>

                        <td class="label">{{ $t['vat_amount'] ?? 'VAT' }}</td>

                        <td class="value">{{ \App\Support\Money::format($vat) }}</td>

                    </tr>

                    <tr class="grand">

                        <td class="label">{{ $t['total_due'] ?? 'Total due' }}</td>

                        <td class="value">{{ \App\Support\Money::format($sumIncVat) }}</td>

                    </tr>

                </table>

            </td>

        </tr>

    </table>



    @if (filled($packageTrackingLine ?? null) || filled($packageWeightsLine ?? null) || filled($vatLegalLines ?? []))

        <div class="package-info">

            @if (filled($packageTrackingLine ?? null))

                <p>{{ $packageTrackingLine }}</p>

            @endif

            @if (filled($packageWeightsLine ?? null))

                <p>{{ $packageWeightsLine }}</p>

            @endif

            @if (filled($vatLegalLines ?? []))

                <div class="vat-disclaimer">

                    @foreach ($vatLegalLines as $line)

                        <p>{{ $line }}</p>

                    @endforeach

                </div>

            @endif

        </div>

    @endif



    <div class="footer">

        {{ $issuer['name'] ?? 'Futura Textiles' }} · {{ $t['invoice_title'] ?? 'Invoice' }} {{ $invoiceNumber }} · {{ $t['footer_generated'] ?? 'Generated' }} {{ now()->format('Y-m-d H:i:s') }}

    </div>

</body>

</html>

