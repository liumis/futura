<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t['title'] }} {{ $document->document_number }}</title>
    <style>
        @page { margin: 28px 32px 36px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #1a1f2e;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.45;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #2b3a67;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        .title {
            font-size: 20px;
            font-weight: 700;
            color: #2b3a67;
            margin: 0 0 4px;
        }
        .meta {
            color: #64748b;
            font-size: 10px;
        }
        .company {
            margin-bottom: 18px;
        }
        .company-name {
            font-size: 13px;
            font-weight: 700;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f8fafc;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        td.num, th.num { text-align: right; }
        .total-row td {
            font-weight: 700;
            background: #f8fafc;
        }
        .footer {
            margin-top: 24px;
            font-size: 10px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 class="title">{{ $t['title'] }}</h1>
        <div class="meta">
            {{ $t['document_no'] }} {{ $document->document_number }}
            &nbsp;|&nbsp;
            {{ $t['date'] }} {{ $document->document_date?->format('Y-m-d') }}
            @if ($document->user)
                &nbsp;|&nbsp;
                {{ $t['prepared_by'] }} {{ $document->user->name ?? $document->user->email }}
            @endif
        </div>
    </div>

    <div class="company">
        <div class="company-name">{{ $company->company_name ?: config('app.name') }}</div>
        @if (filled($company->company_id))
            <div>{{ $t['company_id'] }} {{ $company->company_id }}</div>
        @endif
        @if (filled($company->company_address))
            <div>{{ $company->company_address }}</div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 28px;">#</th>
                <th>{{ $t['product_code'] }}</th>
                <th>{{ $t['collection'] }}</th>
                <th>{{ $t['color'] }}</th>
                <th class="num">{{ $t['quantity'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line['product_code'] ?? '—' }}</td>
                    <td>{{ $line['collection'] ?? '—' }}</td>
                    <td>{{ $line['color'] ?? '—' }}</td>
                    <td class="num">{{ \App\Services\WriteOffDocumentPdfGenerator::formatQuantity($line['quantity']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="4">{{ $t['total_written_off'] }}</td>
                <td class="num">{{ \App\Services\WriteOffDocumentPdfGenerator::formatQuantity($totalQuantity) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        {{ $t['footer_generated'] }} {{ now()->format('Y-m-d H:i:s') }} · {{ $lines->count() }} {{ $t['footer_lines'] }}
    </div>
</body>
</html>
