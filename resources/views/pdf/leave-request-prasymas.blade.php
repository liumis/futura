<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>Prašymas</title>
    <style>
        @page { margin: 48px 52px; }
        body {
            margin: 0;
            color: #1a1f2e;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.55;
        }
        .place-date {
            text-align: right;
            color: #475569;
            margin-bottom: 36px;
        }
        .company {
            margin-bottom: 28px;
            color: #334155;
        }
        .company-name {
            font-weight: 700;
            color: #2b3a67;
            font-size: 13px;
        }
        .title {
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: #2b3a67;
            margin: 28px 0 32px;
            text-transform: uppercase;
        }
        .body-text {
            margin-bottom: 18px;
            text-align: justify;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin: 22px 0 28px;
        }
        .meta-table th,
        .meta-table td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }
        .meta-table th {
            width: 36%;
            background: #f1f5f9;
            font-weight: 600;
            color: #334155;
        }
        .comment {
            margin-top: 8px;
            white-space: pre-wrap;
        }
        .signatures {
            width: 100%;
            margin-top: 56px;
            border-collapse: collapse;
        }
        .signatures td {
            width: 50%;
            vertical-align: top;
            padding-right: 24px;
        }
        .sig-label {
            color: #64748b;
            font-size: 11px;
            margin-bottom: 28px;
        }
        .sig-line {
            border-top: 1px solid #94a3b8;
            padding-top: 6px;
            color: #334155;
        }
        .footer {
            margin-top: 48px;
            font-size: 10px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="place-date">
        {{ $generatedAt }}
    </div>

    <div class="company">
        <div class="company-name">{{ $companyName }}</div>
        @if (filled($companyAddress))
            <div>{{ $companyAddress }}</div>
        @endif
        @if (filled($companyId))
            <div>Įmonės kodas: {{ $companyId }}</div>
        @endif
    </div>

    <div class="title">Prašymas</div>

    <p class="body-text">
        Aš, <strong>{{ $employeeName }}</strong>@if (filled($employeePosition)), {{ $employeePosition }}@endif,
        prašau suteikti man
        <strong>{{ $leaveTypeName }}</strong>
        nuo <strong>{{ $dateFrom }}</strong> iki <strong>{{ $dateTo }}</strong> (imtinai).
    </p>

    <table class="meta-table">
        <tr>
            <th>Darbuotojas</th>
            <td>{{ $employeeName }}</td>
        </tr>
        <tr>
            <th>Prašymo tipas</th>
            <td>{{ $leaveTypeName }}</td>
        </tr>
        <tr>
            <th>Laikotarpis</th>
            <td>{{ $dateFrom }} – {{ $dateTo }}</td>
        </tr>
        @if (filled($comment))
            <tr>
                <th>Komentaras</th>
                <td><div class="comment">{{ $comment }}</div></td>
            </tr>
        @endif
    </table>

    <p class="body-text">
        Prašau patvirtinti šį prašymą.
    </p>

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-label">Darbuotojas</div>
                <div class="sig-line">{{ $employeeName }}</div>
            </td>
            <td>
                <div class="sig-label">Data</div>
                <div class="sig-line">{{ $generatedAt }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Sugeneruota sistemoje · Leave request #{{ $leaveRequestId }}
    </div>
</body>
</html>
