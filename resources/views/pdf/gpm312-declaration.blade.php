<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>GPM312 — {{ $period_label }}</title>
    <style>
        @page { margin: 12mm 14mm 14mm; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            line-height: 1.35;
        }
        .branding { width: 100%; margin-bottom: 8px; table-layout: fixed; }
        .branding td { border: none; vertical-align: middle; padding: 0; }
        .branding .logo-cell { width: 32mm; }
        .branding .logo-cell img { max-height: 14mm; max-width: 30mm; }
        .branding .info-cell { text-align: center; }
        .company { font-size: 12px; font-weight: 700; margin: 0 0 2px; color: #111111; }
        .company-meta { font-size: 8px; color: #555; margin: 0; }
        .top { width: 100%; margin-bottom: 6px; }
        .top td { border: none; vertical-align: top; padding: 0; }
        .legal {
            font-size: 7px;
            color: #64748b;
            line-height: 1.3;
            width: 58%;
        }
        .form-code { text-align: right; }
        .copy { color: #2563eb; font-size: 9px; font-weight: 700; margin: 0 0 4px; }
        .code-box {
            display: inline-block;
            border: 1pt solid #111;
            padding: 4px 8px;
            text-align: center;
        }
        .code-box .code { font-size: 13px; font-weight: 700; letter-spacing: 0.08em; }
        .code-box .ver { font-size: 7.5px; color: #334155; margin-top: 2px; }
        .title {
            font-size: 14px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin: 8px 0 10px;
        }
        table.form { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.form td {
            border: 0.7pt solid #111;
            vertical-align: top;
            padding: 5px 6px;
        }
        .num {
            width: 7mm;
            text-align: center;
            font-weight: 700;
            font-size: 10px;
        }
        .label { font-size: 8px; color: #334155; margin: 0 0 4px; }
        .value { font-size: 11px; font-weight: 700; }
        table.cells { border-collapse: collapse; }
        table.cells td {
            border: 0.6pt solid #111;
            width: 5.2mm;
            height: 6.2mm;
            text-align: center;
            vertical-align: middle;
            font-size: 10px;
            font-weight: 700;
            padding: 0;
        }
        table.cells td.comma {
            border: none;
            width: 3.5mm;
            font-weight: 700;
        }
        .section {
            font-size: 8.5px;
            font-weight: 700;
            background: #f1f5f9;
            padding: 5px 6px;
            border: 0.7pt solid #111;
            border-bottom: none;
        }
        .amounts { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .amounts td {
            border: 0.7pt solid #111;
            vertical-align: top;
            padding: 5px 6px;
            width: 50%;
        }
        table.block {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .block th, .block td {
            border: 0.6pt solid #334155;
            padding: 4px 5px;
            font-size: 8px;
        }
        .block th {
            background: #f1f5f9;
            font-weight: 700;
            text-align: left;
        }
        .block td.right, .block th.right { text-align: right; }
        .block tr.total td { font-weight: 700; background: #f8fafc; }
        .legend { font-size: 7.5px; color: #475569; margin-top: 8px; }
        .legend p { margin: 0 0 2px; }
        .footer {
            margin-top: 14px;
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

    <table class="top">
        <tr>
            <td class="legal">
                Forma patvirtinta Valstybinės mokesčių inspekcijos prie Lietuvos Respublikos finansų ministerijos
                2018 m. vasario 6 d. įsakymu Nr. VA-9
            </td>
            <td class="form-code">
                <div class="copy">Nuorašas</div>
                <div class="code-box">
                    <div class="code">GPM312</div>
                    <div class="ver">Versija 01</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="title">Metinė gyventojams išmokėtų išmokų, priskiriamų A ir B klasės pajamoms, deklaracija</div>

    <table class="form">
        <tr>
            <td class="num">1</td>
            <td colspan="3">
                <div class="label">Išmokas išmokėjusio asmens identifikacinis numeris (kodas)</div>
                <table class="cells">
                    <tr>
                        @foreach ($companyIdCells as $cell)
                            <td>{{ $cell !== ' ' ? $cell : '' }}</td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td class="num">2</td>
            <td colspan="3">
                <div class="label">Išmokas išmokėjusio asmens pavadinimas / vardas, pavardė</div>
                <div class="value">{{ $companyName }}</div>
            </td>
        </tr>
        <tr>
            <td class="num">3</td>
            <td colspan="3">
                <div class="label">Mokestinis laikotarpis (metai)</div>
                <table class="cells">
                    <tr>
                        @foreach ($yearCells as $cell)
                            <td>{{ $cell !== ' ' ? $cell : '' }}</td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section">Bendros sumos</div>
    @include('pdf.partials.gpm313-amount-row', [
        'fields' => [
            ['n' => 4, 'label' => 'Išmokų suma, nuo kurios nebuvo atskaityta NPD, GPM, VSDĮ, PSDĮ (L8 + U8)', 'cells' => $field4Cells],
            ['n' => 5, 'label' => 'Išskaičiuota GPM suma (L10 + U10)', 'cells' => $field5Cells],
        ],
    ])
    @include('pdf.partials.gpm313-amount-row', [
        'fields' => [
            ['n' => 6, 'label' => 'Išmokas išmokėjusio asmens lėšomis sumokėta GPM suma (L11 + U11)', 'cells' => $field6Cells],
            ['n' => 7, 'label' => 'Užsienio valstybėse sumokėta GPM suma (L13)', 'cells' => $field7Cells],
        ],
    ])

    <div class="section">GPM312L — Nuolatiniams Lietuvos gyventojams išmokėtos išmokos</div>
    <table class="block">
        <thead>
            <tr>
                <th>L3 Vardas, pavardė</th>
                <th>L4 Klasė</th>
                <th>L5 Kodas</th>
                <th class="right">L8 Išmoka</th>
                <th class="right">L9 Tarifas %</th>
                <th class="right">L10 Išskaičiuota GPM</th>
                <th class="right">L11 GPM savo lėšomis</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line['name'] }}</td>
                    <td>{{ $line['class'] }}</td>
                    <td>{{ $line['code'] }}</td>
                    <td class="right">{{ $line['l8_label'] }}</td>
                    <td class="right">{{ $line['l9_label'] }}</td>
                    <td class="right">{{ $line['l10_label'] }}</td>
                    <td class="right">{{ $line['l11_label'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">Nėra išmokų už {{ $period_label }} m.</td>
                </tr>
            @endforelse
            @if ($lines !== [])
                <tr class="total">
                    <td colspan="3">Iš viso</td>
                    <td class="right">{{ $field4_label }}</td>
                    <td></td>
                    <td class="right">{{ $field5_label }}</td>
                    <td class="right">{{ $field6_label }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="legend">
        <p>L5 kodas 26 — pajamos iš paskirstytojo pelno (dividendai), klasė A.</p>
        <p>L5 kodas 53 — individualios veiklos pajamos (privatūs teikėjai), klasė B. GPM neišskaičiuojamas.</p>
        <p>L4 klasė A — GPM išskaičiuoja išmokas išmokėjęs asmuo. Klasė B — GPM apskaičiuoja gavėjas.</p>
        <p>NPD – neapmokestinamasis pajamų dydis · GPM – gyventojų pajamų mokestis</p>
    </div>

    <div class="footer">
        Sugeneruota sistemoje · {{ $period_label }} · {{ $generatedAt }}
        @if (filled($directorLabel))
            · {{ $directorLabel }}
        @endif
    </div>
</body>
</html>
