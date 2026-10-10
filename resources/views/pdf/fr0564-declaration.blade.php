<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>FR0564 — {{ $period_label }}</title>
    <style>
        @page { margin: 10mm 11mm 12mm; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            line-height: 1.3;
        }
        .branding { width: 100%; margin-bottom: 5px; table-layout: fixed; }
        .branding td { border: none; vertical-align: middle; padding: 0; }
        .branding .logo-cell { width: 32mm; }
        .branding .logo-cell img { max-height: 12mm; max-width: 30mm; }
        .branding .info-cell { text-align: center; }
        .company { font-size: 11px; font-weight: 700; margin: 0 0 2px; }
        .company-meta { font-size: 7.5px; color: #555; margin: 0; }
        .top { width: 100%; margin-bottom: 4px; }
        .top td { border: none; vertical-align: top; padding: 0; }
        .legal { font-size: 6.2px; color: #64748b; line-height: 1.3; width: 62%; }
        .form-code { text-align: right; }
        .copy { color: #2563eb; font-size: 8px; font-weight: 700; margin: 0 0 3px; }
        .code-box { display: inline-block; border: 1pt solid #111; padding: 3px 7px; text-align: center; }
        .code-box .code { font-size: 12px; font-weight: 700; letter-spacing: 0.06em; }
        .code-box .ver { font-size: 7px; color: #334155; margin-top: 1px; }
        .title {
            font-size: 11.5px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin: 5px 0 7px;
        }
        table.form { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        table.form td { border: 0.6pt solid #111; vertical-align: top; padding: 3px 4px; }
        .num { width: 6mm; text-align: center; font-weight: 700; font-size: 9px; }
        .label { font-size: 7px; color: #334155; margin: 0 0 2px; }
        .value { font-size: 9.5px; font-weight: 700; }
        table.cells { border-collapse: collapse; }
        table.cells td {
            border: 0.5pt solid #111;
            width: 4.4mm;
            height: 5mm;
            text-align: center;
            vertical-align: middle;
            font-size: 8.5px;
            font-weight: 700;
            padding: 0;
        }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        table.grid th, table.grid td {
            border: 0.5pt solid #111;
            padding: 2px 2px;
            vertical-align: middle;
        }
        table.grid th {
            background: #f1f5f9;
            font-size: 6.4px;
            font-weight: 700;
            text-align: center;
            line-height: 1.2;
        }
        table.grid td.center { text-align: center; font-weight: 700; font-size: 8px; }
        .footer { margin-top: 8px; font-size: 7px; color: #64748b; text-align: center; }
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
                2004 m. kovo 12 d. įsakymu Nr. VA-33 (2019 m. vasario 6 d. įsakymo Nr. VA-14 redakcija)
            </td>
            <td class="form-code">
                <div class="copy">Nuorašas</div>
                <div class="code-box">
                    <div class="code">FR0564</div>
                    <div class="ver">Versija 04</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="title">Prekių tiekimo ir paslaugų teikimo į kitas Europos Sąjungos valstybes nares ataskaita</div>

    <table class="form">
        <tr>
            <td class="num">1</td>
            <td colspan="5">
                <div class="label">Pavadinimas</div>
                <div class="value">{{ $companyName }}</div>
            </td>
        </tr>
        <tr>
            <td class="num">2</td>
            <td>
                <div class="label">Mokesčių mokėtojo identifikacinis numeris</div>
                <table class="cells"><tr>@foreach ($companyIdCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
            <td class="num">3</td>
            <td colspan="3">
                <div class="label">PVM mokėtojo kodas</div>
                <table class="cells">
                    <tr>
                        @foreach ($companyVatCountryCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach
                        @foreach ($companyVatNumberCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td class="num">4</td>
            <td colspan="5">
                <div class="label">Telefonas, faksas arba el. paštas</div>
                <div class="value">{{ $contact_details }}</div>
            </td>
        </tr>
        <tr>
            <td class="num">5</td>
            <td>
                <div class="label">Užpildymo data</div>
                <table class="cells"><tr>@foreach ($filledDateCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
            <td class="num">7</td>
            <td>
                <div class="label">Metai</div>
                <table class="cells"><tr>@foreach ($yearCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
            <td class="num">8</td>
            <td>
                <div class="label">Ketvirtis</div>
                <table class="cells"><tr>@foreach ($quarterCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
        </tr>
        <tr>
            <td class="num">9</td>
            <td colspan="5">
                <div class="label">Mėnuo</div>
                <table class="cells"><tr>@foreach ($monthCells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th style="width: 8mm;">10<br>Eil. Nr.</th>
                <th style="width: 14mm;">11<br>Valstybės kodas</th>
                <th>12<br>ES valstybės narės PVM mokėtojo kodas</th>
                <th style="width: 28mm;">13<br>Prekių vertė</th>
                <th style="width: 28mm;">14<br>Prekių vertė — trikampė prekyba</th>
                <th style="width: 28mm;">18<br>Paslaugų vertė</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tableRows as $row)
                <tr>
                    <td class="center">{{ $row['row'] }}</td>
                    <td>
                        <table class="cells" style="margin: 0 auto;"><tr>@foreach ($row['country_cells'] as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
                    </td>
                    <td>
                        <table class="cells"><tr>@foreach ($row['vat_cells'] as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
                    </td>
                    <td>
                        <table class="cells" style="margin-left: auto;"><tr>@foreach ($row['goods_cells'] as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
                    </td>
                    <td>
                        <table class="cells" style="margin-left: auto;"><tr>@foreach ($row['triangular_cells'] as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
                    </td>
                    <td>
                        <table class="cells" style="margin-left: auto;"><tr>@foreach ($row['services_cells'] as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="form">
        <tr>
            <td class="num">15</td>
            <td>
                <div class="label">Visų šio lapo 13 laukelių suma</div>
                <table class="cells"><tr>@foreach ($field15Cells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
            <td class="num">16</td>
            <td>
                <div class="label">Šio ir visų papildomų lapų 13 laukelių suma</div>
                <table class="cells"><tr>@foreach ($field16Cells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
            <td class="num">17</td>
            <td>
                <div class="label">Papildomų lapų skaičius</div>
                <table class="cells"><tr>@foreach ($field17Cells as $cell)<td>{{ $cell !== ' ' ? $cell : '' }}</td>@endforeach</tr></table>
            </td>
        </tr>
    </table>

    <div class="footer">
        Sugeneruota sistemoje · {{ $period_label }} · {{ $generatedAt }}
        @if (filled($directorLabel))
            · {{ $directorLabel }}
        @endif
    </div>
</body>
</html>
