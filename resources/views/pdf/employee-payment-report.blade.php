<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->name }}</title>
    <style>
        @page { margin: 28px 32px 36px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #1a1f2e;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
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
        .meta { color: #64748b; font-size: 10px; }
        .company { margin-bottom: 18px; }
        .company-name { font-size: 13px; font-weight: 700; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f1f5f9;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .right { text-align: right; }
        .footer {
            margin-top: 24px;
            color: #64748b;
            font-size: 10px;
        }
        .approvers {
            margin-top: 20px;
        }
        .approvers li { margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">{{ $report->name }}</p>
        <p class="meta">
            Created {{ optional($report->created_at)->format('Y-m-d H:i:s') }}
            @if ($report->creator)
                by {{ $report->creator->fullName() ?: $report->creator->email }}
            @endif
            · Status: {{ $report->status->label() }}
        </p>
    </div>

    <div class="company">
        <div class="company-name">{{ $company->company_name ?: 'Company' }}</div>
        @if (filled($company->company_iban))
            <div>IBAN: {{ $company->company_iban }}</div>
        @endif
        @if (filled($company->company_address))
            <div>{{ $company->company_address }}</div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Person</th>
                <th class="right">Base (Gross)</th>
                <th class="right">Bonus (Gross)</th>
                <th class="right">Gross</th>
                <th class="right">NPD</th>
                <th class="right">GPM 20%</th>
                <th class="right">Sodra health 6.98%</th>
                <th class="right">Sodra pension &amp; soc.</th>
                <th class="right">Net</th>
                <th class="right">Sodra employer 1.77%</th>
                <th class="right">Workplace cost</th>
                <th>Comment</th>
                <th>Line status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $line['person'] }}</td>
                    <td class="right">{{ $line['base_salary'] }}</td>
                    <td class="right">{{ $line['bonus_payment'] }}</td>
                    <td class="right">{{ $line['gross'] }}</td>
                    <td class="right">{{ $line['npd'] }}</td>
                    <td class="right">{{ $line['gpm'] }}</td>
                    <td class="right">{{ $line['sodra_health'] }}</td>
                    <td class="right">{{ $line['sodra_pension'] }}</td>
                    <td class="right">{{ $line['net'] }}</td>
                    <td class="right">{{ $line['sodra_employer'] }}</td>
                    <td class="right">{{ $line['workplace_cost'] }}</td>
                    <td>{{ $line['comment'] }}</td>
                    <td>{{ $line['status'] }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="4" class="right"><strong>Totals</strong></td>
                <td class="right"><strong>{{ $grossTotal }}</strong></td>
                <td colspan="3"></td>
                <td class="right"><strong>{{ $netTotal }}</strong></td>
                <td></td>
                <td class="right"><strong>{{ $workplaceCostTotal }}</strong></td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

    @if ($approvers->isNotEmpty())
        <div class="approvers">
            <strong>Approvers</strong>
            <ul>
                @foreach ($approvers as $approver)
                    <li>
                        {{ $approver['name'] }}
                        —
                        @if ($approver['approved'])
                            confirmed{{ $approver['auto'] ? ' (auto)' : '' }}
                            @if ($approver['approved_at'])
                                at {{ $approver['approved_at'] }}
                            @endif
                        @else
                            waiting
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="footer">
        Generated {{ now()->format('Y-m-d H:i:s') }}
    </div>
</body>
</html>
