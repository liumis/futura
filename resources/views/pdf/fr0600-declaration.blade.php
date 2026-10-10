<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>FR0600 — {{ $period_label }}</title>
    <style>
        @page { margin: 10mm 12mm 12mm; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5px;
            line-height: 1.3;
        }
        .branding { width: 100%; margin-bottom: 6px; table-layout: fixed; }
        .branding td { border: none; vertical-align: middle; padding: 0; }
        .branding .logo-cell { width: 32mm; }
        .branding .logo-cell img { max-height: 12mm; max-width: 30mm; }
        .branding .info-cell { text-align: center; }
        .company { font-size: 11px; font-weight: 700; margin: 0 0 2px; }
        .company-meta { font-size: 7.5px; color: #555; margin: 0; }
        .top { width: 100%; margin-bottom: 4px; }
        .top td { border: none; vertical-align: top; padding: 0; }
        .legal { font-size: 6.5px; color: #64748b; line-height: 1.3; width: 62%; }
        .form-code { text-align: right; }
        .copy { color: #2563eb; font-size: 8px; font-weight: 700; margin: 0 0 3px; }
        .code-box { display: inline-block; border: 1pt solid #111; padding: 3px 7px; text-align: center; }
        .code-box .code { font-size: 12px; font-weight: 700; letter-spacing: 0.06em; }
        .code-box .ver { font-size: 7px; color: #334155; margin-top: 1px; }
        .title {
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin: 6px 0 8px;
        }
        table.form { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.form td { border: 0.6pt solid #111; vertical-align: top; padding: 4px 5px; }
        .num { width: 6mm; text-align: center; font-weight: 700; font-size: 9px; }
        .label { font-size: 7.5px; color: #334155; margin: 0 0 3px; }
        .value { font-size: 10px; font-weight: 700; }
        table.cells { border-collapse: collapse; }
        table.cells td {
            border: 0.55pt solid #111;
            width: 4.6mm;
            height: 5.4mm;
            text-align: center;
            vertical-align: middle;
            font-size: 9px;
            font-weight: 700;
            padding: 0;
        }
        .section {
            font-size: 8px;
            font-weight: 700;
            background: #f1f5f9;
            padding: 4px 5px;
            border: 0.6pt solid #111;
            border-bottom: none;
        }
        table.block { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .block th, .block td { border: 0.55pt solid #334155; padding: 3px 4px; font-size: 7.5px; }
        .block th { background: #f1f5f9; font-weight: 700; text-align: left; }
        .block td.right, .block th.right { text-align: right; }
        .block tr.total td { font-weight: 700; background: #f8fafc; font-size: 8.5px; }
        .footer { margin-top: 10px; font-size: 7px; color: #64748b; text-align: center; }
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
            <td style="width: 26mm;"></td>
        </tr>
    </table>

    <table class="top">
        <tr>
            <td class="legal">
                Forma patvirtinta Valstybinės mokesčių inspekcijos prie Lietuvos Respublikos finansų ministerijos
                2004 m. kovo 1 d. įsakymu Nr. VA-29
            </td>
            <td class="form-code">
                <div class="copy">Nuorašas</div>
                <div class="code-box">
                    <div class="code">FR0600</div>
                    <div class="ver">Versija 03</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="title">Pridėtinės vertės mokesčio deklaracija</div>

    <table class="form">
        <tr>
            <td class="num">1</td>
            <td>
                <div class="label">Identifikacinis numeris (kodas)</div>
                <table class="cells"><tr>@foreach ($companyIdCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
            <td class="num">3</td>
            <td>
                <div class="label">PVM mokėtojo kodas</div>
                <table class="cells"><tr>@foreach ($companyVatCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
        </tr>
        <tr>
            <td class="num">2</td>
            <td colspan="3">
                <div class="label">Pavadinimas / vardas, pavardė</div>
                <div class="value">{{ $companyName }}</div>
            </td>
        </tr>
        <tr>
            <td class="num">4</td>
            <td>
                <div class="label">Metai</div>
                <table class="cells"><tr>@foreach ($yearCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
            <td class="num">5</td>
            <td>
                <div class="label">Mėnuo</div>
                <table class="cells"><tr>@foreach ($monthCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
        </tr>
    </table>

    @php
        $sections = [
            'I' => 'I. Prekių tiekimo ir paslaugų teikimo sandoriai (apmokestinamoji vertė)',
            'II' => 'II. Prekių ir paslaugų įsigijimo sandoriai (apmokestinamoji vertė)',
            'III' => 'III. Pirkimo ir importo PVM',
            'IV' => 'IV. PVM atskaitos dalis (procentais)',
            'V' => 'V. Pardavimo PVM, PVM atskaita, mokėtinas (grąžintinas) PVM',
        ];
        $bySection = collect($fieldMeta)->groupBy('section');
    @endphp

    @foreach ($sections as $sectionKey => $sectionTitle)
        <div class="section">{{ $sectionTitle }}</div>
        <table class="block">
            <thead>
                <tr>
                    <th style="width: 12mm;">Laukelis</th>
                    <th>Pavadinimas</th>
                    <th class="right" style="width: 28mm;">Suma</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bySection[$sectionKey] ?? [] as $meta)
                    <tr @class(['total' => $meta['key'] === '36'])>
                        <td>{{ strtoupper($meta['key']) }}</td>
                        <td>{{ $meta['label'] }}</td>
                        <td class="right">{{ $labels[$meta['key']] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <div class="footer">
        Sugeneruota sistemoje · {{ $period_label }} · {{ $generatedAt }}
        @if (filled($directorLabel))
            · {{ $directorLabel }}
        @endif
    </div>
</body>
</html>
