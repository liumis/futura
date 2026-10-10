<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>Atsiskaitymo lapelis</title>
    <style>
        @page { margin: 14mm 14mm 14mm; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.35;
        }
        .branding { width: 100%; margin-bottom: 8px; table-layout: fixed; }
        .branding td { border: none; vertical-align: middle; padding: 0; }
        .branding .logo-cell { width: 32mm; }
        .branding .logo-cell img { max-height: 14mm; max-width: 30mm; }
        .branding .info-cell { text-align: center; }
        .company { font-size: 12px; font-weight: 700; margin: 0 0 2px; color: #111111; }
        .company-meta { font-size: 8px; color: #555; margin: 0; }
        .title {
            font-size: 16px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #111111;
            margin: 10px 0 12px;
        }
        .meta {
            width: 100%;
            margin-bottom: 12px;
            border-collapse: collapse;
        }
        .meta td {
            border: none;
            vertical-align: top;
            padding: 0;
            font-size: 10px;
        }
        .meta .right { text-align: right; }
        .meta .employee-name { font-size: 13px; font-weight: 700; color: #111; }
        .meta .period { font-size: 11px; font-weight: 700; margin-top: 2px; }
        .salary-line { margin: 0 0 4px; }
        .salary-line strong { font-weight: 700; }

        .cols { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .cols > tbody > tr > td {
            border: none;
            vertical-align: top;
            padding: 0;
            width: 50%;
        }
        .cols .left { padding-right: 4mm; }
        .cols .right { padding-left: 4mm; }

        table.block {
            width: 100%;
            border-collapse: collapse;
        }
        .block th, .block td {
            border: 0.6pt solid #334155;
            padding: 5px 6px;
            font-size: 9.5px;
        }
        .block th {
            background: #f1f5f9;
            font-weight: 700;
            color: #1e293b;
            text-align: left;
        }
        .block th.amount, .block td.amount {
            text-align: right;
            width: 28%;
            white-space: nowrap;
        }
        .block tr.total td {
            font-weight: 700;
            background: #f8fafc;
        }

        .payout {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
        }
        .payout td {
            border: none;
            vertical-align: middle;
            padding: 3px 0;
        }
        .payout .label {
            font-weight: 700;
            width: 28%;
            font-size: 11px;
        }
        .payout .box {
            border: 1.2pt solid #111111;
            padding: 6px 10px;
            font-size: 13px;
            font-weight: 700;
            text-align: right;
            width: 32%;
            color: #111;
        }
        .payout .spacer { width: 8%; }
        .footer {
            margin-top: 18px;
            font-size: 7.5px;
            color: #64748b;
            text-align: center;
        }
    </style>
</head>
<body>
    <table class="branding">
        <tr>
            <td class="logo-cell">
                @if (!empty($logoDataUri))
                    <img src="{{ $logoDataUri }}" alt="logo">
                @endif
            </td>
            <td class="info-cell">
                <div class="company">{{ $companyName }}@if (filled($companyId)),<br>įm. k. {{ $companyId }}@endif</div>
                @if (filled($companyAddress))
                    <div class="company-meta">{{ $companyAddress }}</div>
                @endif
                @if (filled($companyEmail) || filled($companyPhone))
                    <div class="company-meta">
                        @if (filled($companyEmail)){{ $companyEmail }}@endif
                        @if (filled($companyEmail) && filled($companyPhone)) · @endif
                        @if (filled($companyPhone)){{ $companyPhone }}@endif
                    </div>
                @endif
            </td>
            <td style="width: 28mm;"></td>
        </tr>
    </table>

    <div class="title">Atsiskaitymo lapelis</div>

    <table class="meta">
        <tr>
            <td>
                <div class="salary-line"><strong>Etatinis atlyginimas</strong> {{ number_format($baseSalary, 2, ',', ' ') }} Eur.</div>
                <div class="salary-line"><strong>Pritaikytas NPD+PNPD</strong> {{ number_format($npd, 2, ',', ' ') }}</div>
            </td>
            <td class="right">
                <div class="employee-name">{{ $employeeName }}</div>
                <div class="period">{{ $periodLabel }}</div>
            </td>
        </tr>
    </table>

    <table class="cols">
        <tr>
            <td class="left">
                <table class="block">
                    <thead>
                        <tr>
                            <th>Priskaitymai</th>
                            <th class="amount">Suma, Eur.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($earnings as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td class="amount">{{ number_format($row['amount'], 2, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td>Viso:</td>
                            <td class="amount">{{ number_format($earningsTotal, 2, ',', ' ') }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
            <td class="right">
                <table class="block">
                    <thead>
                        <tr>
                            <th>Atskaitymai</th>
                            <th class="amount">Suma, Eur.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deductions as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td class="amount">{{ number_format($row['amount'], 2, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td>Viso:</td>
                            <td class="amount">{{ number_format($deductionsTotal, 2, ',', ' ') }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <table class="payout">
        <tr>
            <td class="label">Išmokėti:</td>
            <td class="box">{{ number_format($netAmount, 2, ',', ' ') }}</td>
            <td class="spacer"></td>
            <td class="label">{{ $sodraEmployerLabel }}:</td>
            <td class="box">{{ number_format($sodraEmployer, 2, ',', ' ') }}</td>
        </tr>
    </table>

    <div class="footer">
        Sugeneruota sistemoje · Payment #{{ $paymentId }} · {{ $generatedAt }}
    </div>
</body>
</html>
