<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>GPM313 — {{ $period_label }}</title>
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
            font-size: 15px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.04em;
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
            width: 33.33%;
        }
        .legend { font-size: 7.5px; color: #475569; margin-top: 10px; }
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
                2017 m. gruodžio 20 d. įsakymu Nr. VA-121
            </td>
            <td class="form-code">
                <div class="copy">Nuorašas</div>
                <div class="code-box">
                    <div class="code">GPM313</div>
                    <div class="ver">Versija 01</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="title">Mėnesinė pajamų mokesčio deklaracija</div>

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
            <td>
                <div class="label">Metai</div>
                <table class="cells">
                    <tr>
                        @foreach ($yearCells as $cell)
                            <td>{{ $cell !== ' ' ? $cell : '' }}</td>
                        @endforeach
                    </tr>
                </table>
            </td>
            <td class="num">4</td>
            <td>
                <div class="label">Mėnuo</div>
                <table class="cells">
                    <tr>
                        @foreach ($monthCells as $cell)
                            <td>{{ $cell !== ' ' ? $cell : '' }}</td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section">
        Duomenys apie su darbo santykiais ir jų esmę atitinkančiais santykiais susijusias A klasės išmokas
    </div>
    @include('pdf.partials.gpm313-amount-row', [
        'fields' => [
            ['n' => 5, 'label' => 'Išmokų suma, nuo kurios nebuvo atskaityta NPD, GPM, VSDĮ, PSDĮ', 'cells' => $field5Cells],
            ['n' => 6, 'label' => 'Išskaičiuota ir / arba išmokas išmokėjusio asmens lėšomis sumokėta GPM suma nuo išmokų, išmokėtų iki mėnesio 15 d.', 'cells' => $field6Cells],
            ['n' => 7, 'label' => 'Išskaičiuota ir / arba išmokas išmokėjusio asmens lėšomis sumokėta GPM suma nuo išmokų, išmokėtų nuo 16 d. iki mėnesio pabaigos', 'cells' => $field7Cells],
        ],
    ])

    <div class="section">
        Duomenys apie su darbo santykiais ir jų esmę atitinkančiais santykiais nesusijusias A klasės išmokas
    </div>
    @include('pdf.partials.gpm313-amount-row', [
        'fields' => [
            ['n' => 8, 'label' => 'Išmokų suma, nuo kurios nebuvo atskaityta GPM ir VSDĮ', 'cells' => $field8Cells],
            ['n' => 9, 'label' => 'Išskaičiuota ir / arba išmokas išmokėjusio asmens lėšomis sumokėta GPM suma nuo išmokų, išmokėtų iki mėnesio 15 d.', 'cells' => $field9Cells],
            ['n' => 10, 'label' => 'Išskaičiuota ir / arba išmokas išmokėjusio asmens lėšomis sumokėta GPM suma nuo išmokų, išmokėtų nuo 16 d. iki mėnesio pabaigos', 'cells' => $field10Cells],
        ],
    ])

    <div class="section">
        Duomenys apie B klasės išmokas, nuo kurių išskaičiuotas ir / arba išmokas išmokėjusio asmens lėšomis sumokėtas GPM
    </div>
    @include('pdf.partials.gpm313-amount-row', [
        'fields' => [
            ['n' => 11, 'label' => 'Išmokų suma, nuo kurios nebuvo atskaitytas GPM', 'cells' => $field11Cells],
            ['n' => 12, 'label' => 'Iš viso išskaičiuota ir / arba išmokas išmokėjusio asmens lėšomis sumokėta GPM suma nuo B klasės išmokų per mėnesį', 'cells' => $field12Cells],
            ['n' => null, 'label' => '', 'cells' => []],
        ],
    ])

    <div class="legend">
        <p>NPD – neapmokestinamasis pajamų dydis</p>
        <p>GPM – gyventojų pajamų mokestis</p>
        <p>VSDĮ – valstybinio socialinio draudimo įmokos</p>
        <p>PSDĮ – privalomojo sveikatos draudimo įmokos</p>
    </div>

    <div class="footer">
        Sugeneruota sistemoje · {{ $period_label }} · {{ $generatedAt }}
        @if (filled($directorLabel))
            · {{ $directorLabel }}
        @endif
    </div>
</body>
</html>
