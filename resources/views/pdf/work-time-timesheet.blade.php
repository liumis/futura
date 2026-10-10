<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>Mėnesinio darbo laiko apskaitos žiniaraštis</title>
    <style>
        @page { margin: 6mm 6mm 6mm; size: A4 landscape; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 7px;
            line-height: 1.2;
        }
        .branding {
            width: 100%;
            margin-bottom: 4px;
            table-layout: fixed;
        }
        .branding td {
            border: none;
            vertical-align: middle;
            padding: 0;
        }
        .branding .logo-cell {
            width: 30mm;
        }
        .branding .info-cell {
            width: 30mm;
        }
        .branding .logo-cell img {
            max-height: 12mm;
            max-width: 28mm;
        }
        .branding .info-cell {
            text-align: center;
        }
        .company {
            font-size: 8px;
            font-weight: 700;
            margin: 0 0 1px;
        }
        .company-meta {
            font-size: 6px;
            color: #555;
            margin: 0;
        }
        .title {
            font-size: 10px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 2px 0 4px;
        }
        .period {
            width: 100%;
            margin-bottom: 4px;
        }
        .period td {
            border: none;
            font-size: 7px;
            padding: 0 4px 3px 0;
        }
        table.sheet {
            width: auto; /* keep real column widths effective */
            border-collapse: collapse;
            table-layout: fixed;
        }
        .sheet th,
        .sheet td {
            border: 0.4pt solid #222;
            vertical-align: middle;
            text-align: center;
            padding: 1px 1px;
        }
        .sheet th {
            font-size: 4px;
            font-weight: 700;
            background: #f3f4f6;
            line-height: 1.1;
        }
        .sheet thead th {
            /* extra breathing room for multi-line header labels */
            padding: 2px 1px;
            line-height: 1.15;
        }
        .sheet td.cell-text {
            font-size: 6px;
            line-height: 1.2;
            text-align: left;
            vertical-align: middle;
            padding: 1px 1px;
        }
        .sheet td.cell-name {
            /* keep first-name/surname visible and prevent squeezing */
            min-width: 32mm;
            padding: 2px 2px;
        }
        .sheet td.cell-position {
            /* keep position visible and prevent squeezing */
            min-width: 34mm;
            padding: 2px 2px;
        }
        .sheet td.cell-text .line {
            display: block;
            white-space: nowrap;
        }
        .sheet td.cell-day {
            font-size: 3.2px;
            padding: 1px 1px;
            min-width: 3.0mm;
            line-height: 1;
        }
        .sheet td.cell-num {
            font-size: 3.2px;
            padding: 1px 1px;
        }
        .weekend { background: #dbeafe; }
        .holiday { background: #fecaca; }
        .legend-wrap {
            margin-top: 5px;
        }
        .legend-title {
            font-size: 7px;
            font-weight: 700;
            margin: 0 0 2px;
        }
        table.legend {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .legend th,
        .legend td {
            border: 0.4pt solid #222;
            text-align: center;
            padding: 1px;
            font-size: 5.5px;
        }
        .legend th { background: #f3f4f6; font-weight: 700; }
        .legend .label { text-align: left; padding-left: 3px; width: 30mm; }
    </style>
</head>
<body>
    {{-- Branded header: logo left, company info centre, period right --}}
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
                        @if (filled($companyEmail) && filled($companyPhone)) &nbsp;·&nbsp; @endif
                        @if (filled($companyPhone)){{ $companyPhone }}@endif
                    </div>
                @endif
            </td>
            <td style="width: 28mm; text-align: right; font-size: 7px; vertical-align: bottom;">
                {{ $year }} m. {{ $monthName }}
            </td>
        </tr>
    </table>

    <div class="title">Mėnesinio darbo laiko apskaitos žiniaraštis</div>

    <table class="period" style="display:none">
        <tr>
            <td style="width: 50%;">{{ $year }} m.</td>
            <td style="width: 50%; text-align: right;">{{ $monthName }}</td>
        </tr>
    </table>

    <table class="sheet">
        <colgroup>
            <col style="width: 6mm">    {{-- Eil. Nr. --}}
            <col style="width: 7mm">    {{-- Tabelio Nr. --}}
            <col style="width: 32mm">   {{-- Vardas, pavardė --}}
            <col style="width: 34mm">   {{-- Profesija --}}
            <col style="width: 7mm">    {{-- Grafiko Nr. --}}
            <col style="width: 8mm">    {{-- Plan valandos --}}
            @for ($i = 0; $i < 31; $i++)
                <col style="width: 3.0mm"> {{-- Dienos --}}
            @endfor
            <col style="width: 8.8mm">  {{-- dienų --}}
            <col style="width: 8.8mm">  {{-- valandų --}}
            <col style="width: 8.8mm">  {{-- naktį --}}
            <col style="width: 8.8mm">  {{-- viršvalandžių --}}
            <col style="width: 8.8mm">  {{-- budėjimas --}}
            <col style="width: 8.8mm">  {{-- poilsio --}}
            <col style="width: 8.8mm">  {{-- švenčių --}}
            <col style="width: 8.8mm">  {{-- neatv. dienų --}}
            <col style="width: 8.8mm">  {{-- neatv. val. --}}
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2">Eil.<br>Nr.</th>
                <th rowspan="2">Tabelio<br>Nr.</th>
                <th rowspan="2">Vardas,<br>pavardė</th>
                <th rowspan="2">Profesija (pareigos),<br>kvalifikacinė kategorija</th>
                <th rowspan="2">Darbo<br>grafiko<br>numeris</th>
                <th rowspan="2">Nustatytas darbo valandų skaičius per mėnesį</th>
                <th colspan="31">Dienos</th>
                <th colspan="7">Faktiškai dirbta per mėnesį</th>
                <th colspan="2">Neatvykimas į darbą</th>
            </tr>
            <tr>
                @foreach ($days as $day)
                    <th class="@if ($day['is_holiday']) holiday @elseif ($day['is_weekend']) weekend @endif">
                        {{ $day['number'] }}
                    </th>
                @endforeach
                <th>dienų</th>
                <th>valandų</th>
                <th>naktį</th>
                <th>viršval.</th>
                <th>budėjimas</th>
                <th>poilsio<br>d.</th>
                <th>švenčių<br>d.</th>
                <th>dienų<br>sk.</th>
                <th>val.<br>sk.</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="cell-num">{{ $row['row_number'] }}</td>
                    <td class="cell-num">{{ $row['table_number'] }}</td>
                    <td class="cell-text cell-name"><span class="line">{{ $row['name'] }}</span><span class="line">{{ $row['surname'] }}</span></td>
                    <td class="cell-text cell-position"><span class="line">{{ $row['position'] }}</span></td>
                    <td class="cell-num">{{ $row['schedule_number'] }}</td>
                    <td class="cell-num">{{ $row['planned_hours'] }}</td>
                    @foreach ($row['cells'] as $index => $cell)
                        @php $day = $days[$index]; @endphp
                        <td class="cell-day @if ($day['is_holiday'] || ($cell['kind'] ?? '') === 'holiday') holiday @elseif ($day['is_weekend'] || ($cell['kind'] ?? '') === 'rest') weekend @endif">
                            {{ $cell['text'] }}
                        </td>
                    @endforeach
                    <td class="cell-num">{{ $row['worked_days'] }}</td>
                    <td class="cell-num">{{ $row['worked_hours'] }}</td>
                    <td class="cell-num">{{ $row['night_hours'] }}</td>
                    <td class="cell-num">{{ $row['overtime_hours'] }}</td>
                    <td class="cell-num">{{ $row['on_call_hours'] }}</td>
                    <td class="cell-num">{{ $row['rest_day_hours'] }}</td>
                    <td class="cell-num">{{ $row['holiday_work_hours'] }}</td>
                    <td class="cell-num">{{ $row['absence_days'] }}</td>
                    <td class="cell-num">{{ $row['absence_hours'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="46">Nėra darbuotojų.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="legend-wrap">
        <div class="legend-title">Tarnybinės komandiruotės ir neatvykimo į darbą atvejai per mėnesį</div>
        <table class="legend">
            <tr>
                <th class="label"></th>
                @foreach ($legendCodes as $code)
                    <th>{{ $code }}</th>
                @endforeach
            </tr>
            <tr>
                <td class="label">Dienos</td>
                @foreach ($legendCodes as $code)
                    <td>{{ ($legendTotals[$code]['days'] ?? 0) > 0 ? $legendTotals[$code]['days'] : '' }}</td>
                @endforeach
            </tr>
            <tr>
                <td class="label">Valandos</td>
                @foreach ($legendCodes as $code)
                    <td>
                        @if (($legendTotals[$code]['hours'] ?? 0) > 0)
                            {{ \App\Services\WorkTimeTimesheetPdfGenerator::formatHours((float) $legendTotals[$code]['hours']) }}
                        @endif
                    </td>
                @endforeach
            </tr>
        </table>
    </div>
</body>
</html>
