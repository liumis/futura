<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>Darbo užmokesčio priskaitymo žiniaraštis</title>
    <style>
        @page { margin: 5mm 5mm 5mm; size: A4 landscape; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 6px;
            line-height: 1.15;
        }
        .branding { width: 100%; margin-bottom: 3px; table-layout: fixed; }
        .branding td { border: none; vertical-align: middle; padding: 0; }
        .branding .logo-cell { width: 28mm; }
        .branding .info-cell { text-align: center; }
        .branding .logo-cell img { max-height: 11mm; max-width: 26mm; }
        .company { font-size: 7px; font-weight: 700; margin: 0 0 1px; }
        .company-meta { font-size: 5.5px; color: #555; margin: 0; }
        .title {
            font-size: 9px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin: 1px 0 2px;
        }
        .doc-no {
            text-align: center;
            font-size: 6.5px;
            font-weight: 700;
            margin-bottom: 2px;
        }
        .period-line {
            text-align: center;
            font-size: 6px;
            margin-bottom: 3px;
        }
        table.sheet {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .sheet th, .sheet td {
            border: 0.35pt solid #222;
            vertical-align: middle;
            text-align: center;
            padding: 1px 1px;
            word-wrap: break-word;
        }
        .sheet th {
            font-size: 3.6px;
            font-weight: 700;
            background: #f3f4f6;
            line-height: 1.05;
        }
        .sheet thead th { padding: 2px 1px; }
        .sheet td.cell-text {
            font-size: 5px;
            text-align: left;
            vertical-align: middle;
            padding: 1px 2px;
        }
        .sheet td.cell-name { min-width: 18mm; }
        .sheet td.cell-num { font-size: 5px; }
        .sheet tr.totals td {
            font-weight: 700;
            background: #f9fafb;
        }
        .signatures {
            margin-top: 6px;
            width: 100%;
        }
        .signatures td {
            border: none;
            font-size: 6px;
            padding-top: 8px;
            width: 50%;
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
            <td style="width: 24mm; text-align: right; font-size: 6px; vertical-align: bottom;">
                {{ $year }} m. {{ $monthNameGenitive }} mėn.
            </td>
        </tr>
    </table>

    <div class="title">Darbo užmokesčio priskaitymo žiniaraštis</div>
    <div class="doc-no">Nr. {{ $documentNumber }}</div>
    <div class="period-line">{{ $year }} m. {{ $monthNameGenitive }} mėn.</div>

    <table class="sheet">
        <thead>
            <tr>
                <th rowspan="2">Eil.<br>Nr.</th>
                <th rowspan="2">Dirbta<br>dienų</th>
                <th rowspan="2">Dirbta<br>valandų</th>
                <th rowspan="2">Darbas poilsio ir<br>švenčių dienomis<br>val.</th>
                <th rowspan="2">Neatvykimai<br>į darbą val.</th>
                <th rowspan="2">Atlyginimas<br>ant popieriaus</th>
                <th rowspan="2">Pagrindinis<br>neapmokestinamas<br>pajamų dydis<br>(NPD)</th>
                <th rowspan="2">Vardas,<br>pavardė</th>
                <th rowspan="2">Pareigos</th>
                <th colspan="7">Priskaitymai</th>
                <th colspan="6">Išskaitymai</th>
                <th rowspan="2">Išmokėti</th>
            </tr>
            <tr>
                <th>Nedarbingumo<br>pažymėjimo<br>apmokėjimas<br>iš įmonės lėšų<br>(2d.d.)</th>
                <th>Priskaityta<br>už darbo<br>dienas</th>
                <th>Darbas<br>šventinę/<br>poilsio dieną</th>
                <th>Premija</th>
                <th>Priskaičiuota<br>už suteiktas<br>poilsio dienas<br>(vidutinis DU)</th>
                <th>Nepanaudotų<br>atostogų<br>kompensacija/<br>Atostogos</th>
                <th>Iš viso</th>
                <th>Valstybinis<br>socialinis<br>draudimas<br>(VSD)<br>2,49 ir 1,77</th>
                <th>Gyventojų<br>pajamų<br>mokestis<br>(GPM)<br>{{ $gpmRateLabel }}</th>
                <th>Valstybinis<br>socialinis<br>draudimas<br>(VSD)<br>{{ $vsdEmployeeRateLabel }}</th>
                <th>Privalomas<br>sveikatos<br>draudimas<br>(PSD)<br>{{ $psdRateLabel }}</th>
                <th>II-pakopos<br>pensijai<br>{{ $secondPillarRateLabel }}</th>
                <th>Iš viso</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="cell-num">{{ $row['row_number'] }}</td>
                    <td class="cell-num">{{ $row['worked_days'] }}</td>
                    <td class="cell-num">{{ $row['worked_hours'] }}</td>
                    <td class="cell-num">{{ $row['rest_holiday_hours'] }}</td>
                    <td class="cell-num">{{ $row['absence_hours'] }}</td>
                    <td class="cell-num">{{ $row['paper_salary'] }}</td>
                    <td class="cell-num">{{ $row['npd'] }}</td>
                    <td class="cell-text cell-name">
                        <span>{{ $row['name'] }}</span><br>
                        <span>{{ $row['surname'] }}</span>
                    </td>
                    <td class="cell-text">{{ $row['position'] }}</td>
                    <td class="cell-num">{{ $row['sick_pay_company'] }}</td>
                    <td class="cell-num">{{ $row['accrued_work_days'] }}</td>
                    <td class="cell-num">{{ $row['holiday_work_pay'] }}</td>
                    <td class="cell-num">{{ $row['bonus'] }}</td>
                    <td class="cell-num">{{ $row['rest_days_avg_pay'] }}</td>
                    <td class="cell-num">{{ $row['vacation_compensation'] }}</td>
                    <td class="cell-num">{{ $row['accruals_total'] }}</td>
                    <td class="cell-num">{{ $row['employer_vsd_249'] ?: $row['employer_vsd_177'] }}</td>
                    <td class="cell-num">{{ $row['gpm'] }}</td>
                    <td class="cell-num">{{ $row['vsd_employee'] }}</td>
                    <td class="cell-num">{{ $row['psd'] }}</td>
                    <td class="cell-num">{{ $row['second_pillar'] }}</td>
                    <td class="cell-num">{{ $row['deductions_total'] }}</td>
                    <td class="cell-num">{{ $row['net'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="23">Nėra duomenų.</td>
                </tr>
            @endforelse
            @if (count($rows) > 0)
                <tr class="totals">
                    <td class="cell-num">Iš viso</td>
                    <td class="cell-num">{{ $totals['worked_days'] }}</td>
                    <td class="cell-num">{{ $totals['worked_hours'] }}</td>
                    <td class="cell-num"></td>
                    <td class="cell-num">{{ $totals['absence_hours'] }}</td>
                    <td class="cell-num">{{ $totals['paper_salary'] }}</td>
                    <td class="cell-num">{{ $totals['npd'] }}</td>
                    <td colspan="2"></td>
                    <td class="cell-num">{{ $totals['sick_pay_company'] }}</td>
                    <td class="cell-num">{{ $totals['accrued_work_days'] }}</td>
                    <td class="cell-num"></td>
                    <td class="cell-num">{{ $totals['bonus'] }}</td>
                    <td class="cell-num"></td>
                    <td class="cell-num"></td>
                    <td class="cell-num">{{ $totals['accruals_total'] }}</td>
                    <td class="cell-num">{{ $totals['employer_vsd_249'] ?: $totals['employer_vsd_177'] }}</td>
                    <td class="cell-num">{{ $totals['gpm'] }}</td>
                    <td class="cell-num">{{ $totals['vsd_employee'] }}</td>
                    <td class="cell-num">{{ $totals['psd'] }}</td>
                    <td class="cell-num">{{ $totals['second_pillar'] }}</td>
                    <td class="cell-num">{{ $totals['deductions_total'] }}</td>
                    <td class="cell-num">{{ $totals['net'] }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>Direktorius<br><br>_________________________</td>
            <td style="text-align: right;">Vyr. buhalterė<br><br>_________________________</td>
        </tr>
    </table>
</body>
</html>
