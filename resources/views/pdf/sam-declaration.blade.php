<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>SAM — {{ $period_label }}</title>
    <style>
        @page { margin: 14mm 16mm 16mm; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.35;
        }
        .branding { width: 100%; margin-bottom: 10px; table-layout: fixed; }
        .branding td { border: none; vertical-align: middle; padding: 0; }
        .branding .logo-cell { width: 32mm; }
        .branding .logo-cell img { max-height: 14mm; max-width: 30mm; }
        .branding .info-cell { text-align: center; }
        .company { font-size: 13px; font-weight: 700; margin: 0 0 2px; }
        .company-meta { font-size: 8.5px; color: #555; margin: 0; }
        .title {
            font-size: 15px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 10px 0 4px;
        }
        .subtitle {
            text-align: center;
            font-size: 10px;
            color: #334155;
            margin: 0 0 12px;
        }
        .meta {
            width: 100%;
            margin-bottom: 10px;
            border-collapse: collapse;
        }
        .meta td {
            border: 0.6pt solid #111;
            padding: 6px 8px;
            vertical-align: top;
        }
        .meta .label { font-size: 8px; color: #64748b; margin: 0 0 2px; }
        .meta .value { font-size: 11px; font-weight: 700; }
        table.sheet {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .sheet th, .sheet td {
            border: 0.6pt solid #222;
            vertical-align: middle;
            padding: 5px 6px;
        }
        .sheet th {
            font-size: 8px;
            font-weight: 700;
            background: #f3f4f6;
            text-align: center;
        }
        .sheet td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .sheet td.name { text-align: left; }
        .sheet tr.totals td {
            font-weight: 700;
            background: #f9fafb;
        }
        .note {
            margin-top: 10px;
            font-size: 8px;
            color: #64748b;
        }
        .signatures {
            margin-top: 28px;
            width: 100%;
        }
        .signatures td {
            border: none;
            width: 50%;
            font-size: 9px;
            padding-top: 8px;
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
                <div class="company">{{ $companyName }}@if (filled($companyId)), įm. k. {{ $companyId }}@endif</div>
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
        </tr>
    </table>

    <div class="title">SAM — Sodros įmokų suvestinė</div>
    <div class="subtitle">Mokėjimo kodas {{ $payment_code }} · {{ $period_label }}</div>

    <table class="meta">
        <tr>
            <td style="width: 34%;">
                <div class="label">Darbuotojų VSD + PSD</div>
                <div class="value">{{ $sodra_employee_label }} €</div>
            </td>
            <td style="width: 33%;">
                <div class="label">Darbdavio VSD</div>
                <div class="value">{{ $sodra_employer_label }} €</div>
            </td>
            <td style="width: 33%;">
                <div class="label">Iš viso Sodrai</div>
                <div class="value">{{ $sodra_total_label }} €</div>
            </td>
        </tr>
    </table>

    <table class="sheet">
        <thead>
            <tr>
                <th style="width: 8%;">Nr.</th>
                <th style="width: 36%;">Darbuotojas</th>
                <th style="width: 14%;">Bruto</th>
                <th style="width: 14%;">Darb. Sodra</th>
                <th style="width: 14%;">Darbdavio</th>
                <th style="width: 14%;">Iš viso</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $index => $row)
                <tr>
                    <td style="text-align:center;">{{ $index + 1 }}</td>
                    <td class="name">{{ $row['employee_name'] }}</td>
                    <td class="num">{{ $row['gross_label'] }}</td>
                    <td class="num">{{ $row['sodra_employee_label'] }}</td>
                    <td class="num">{{ $row['sodra_employer_label'] }}</td>
                    <td class="num">{{ $row['sodra_total_label'] }}</td>
                </tr>
            @endforeach
            <tr class="totals">
                <td colspan="2">Iš viso</td>
                <td class="num">{{ $gross_total_label }}</td>
                <td class="num">{{ $sodra_employee_label }}</td>
                <td class="num">{{ $sodra_employer_label }}</td>
                <td class="num">{{ $sodra_total_label }}</td>
            </tr>
        </tbody>
    </table>

    <div class="note">
        Vidaus suvestinė pagal mėnesio DU priskaitymus. Banko pavedimas Sodrai (kodas {{ $payment_code }})
        siejamas su šiuo dokumentu. Sugeneruota {{ $generatedAt }}.
    </div>

    <table class="signatures">
        <tr>
            <td>Parengė: ______________________</td>
            <td>
                @if (filled($directorLabel))
                    Vadovas: {{ $directorLabel }}
                @else
                    Vadovas: ______________________
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
