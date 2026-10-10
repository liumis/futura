<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22px 20px 28px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #1a1f2e;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            line-height: 1.3;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #111111;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .title {
            font-size: 16px;
            font-weight: 700;
            color: #111111;
            margin: 0 0 2px;
        }
        .subtitle {
            font-size: 10px;
            color: #64748b;
            margin: 0 0 4px;
        }
        .meta { color: #64748b; font-size: 8px; }
        .company { margin-bottom: 10px; }
        .company-name { font-size: 10px; font-weight: 700; }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 3px 4px;
            text-align: left;
            vertical-align: top;
            word-wrap: break-word;
        }
        th {
            background: #f1f5f9;
            font-size: 7px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .right { text-align: right; }
        .totals td {
            font-weight: 700;
            background: #f8fafc;
        }
        .empty {
            text-align: center;
            padding: 24px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">{{ $title }}</p>
        <p class="subtitle">{{ $subtitle }}</p>
        <p class="meta">Generated {{ $generated_at }} · {{ $count }} line(s)</p>
    </div>

    <div class="company">
        <div class="company-name">{{ $company_name }}</div>
    </div>

    @if ($count === 0)
        <div class="empty">No bank lines are missing a document.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 8%;">Payment date</th>
                    <th style="width: 18%;">From</th>
                    <th style="width: 18%;">To</th>
                    <th style="width: 28%;">Description</th>
                    <th style="width: 8%;">Direction</th>
                    <th style="width: 10%;" class="right">Income</th>
                    <th style="width: 10%;" class="right">Outcome</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['from'] }}</td>
                        <td>{{ $row['to'] }}</td>
                        <td>{{ $row['description'] }}</td>
                        <td>{{ $row['direction'] }}</td>
                        <td class="right">{{ $row['income'] }}</td>
                        <td class="right">{{ $row['outcome'] }}</td>
                    </tr>
                @endforeach
                <tr class="totals">
                    <td colspan="5">Total</td>
                    <td class="right">{{ $income_total }}</td>
                    <td class="right">{{ $outcome_total }}</td>
                </tr>
            </tbody>
        </table>
    @endif
</body>
</html>
