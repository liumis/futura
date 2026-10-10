<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>Įsakymas dėl priedo</title>
    <style>
        @page { margin: 48px 52px; }
        body {
            margin: 0;
            color: #1a1f2e;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.55;
        }
        .place-date { text-align: right; color: #475569; margin-bottom: 36px; }
        .company { margin-bottom: 28px; color: #334155; }
        .company-name { font-weight: 700; color: #111111; font-size: 13px; }
        .title {
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: #111111;
            margin: 28px 0 12px;
            text-transform: uppercase;
        }
        .doc-no { text-align: center; font-size: 12px; font-weight: 700; margin-bottom: 28px; color: #334155; }
        .body-text { margin-bottom: 18px; text-align: justify; }
        .meta-table { width: 100%; border-collapse: collapse; margin: 22px 0 28px; }
        .meta-table th, .meta-table td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }
        .meta-table th { width: 36%; background: #f1f5f9; font-weight: 700; color: #334155; }
        .comment { margin-top: 8px; white-space: pre-wrap; }
        .signatures { width: 100%; margin-top: 56px; border-collapse: collapse; }
        .signatures td { width: 50%; vertical-align: top; padding-right: 24px; }
        .sig-label { color: #64748b; font-size: 11px; margin-bottom: 28px; }
        .sig-line { border-top: 1px solid #94a3b8; padding-top: 6px; color: #334155; min-height: 24px; }
        .footer { margin-top: 48px; font-size: 10px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="place-date">{{ $generatedAt }}</div>

    <div class="company">
        <div class="company-name">{{ $companyName }}</div>
        @if (filled($companyAddress))<div>{{ $companyAddress }}</div>@endif
        @if (filled($companyId))<div>Įmonės kodas: {{ $companyId }}</div>@endif
    </div>

    <div class="title">Įsakymas</div>
    <div class="doc-no">Nr. {{ $orderNumber }}</div>

    <p class="body-text">
        Vadovaudamasis Lietuvos Respublikos darbo kodeksu, kolektyvine sutartimi (jei taikoma) ir įmonės vidaus tvarka,
        <strong>ĮSAKAU:</strong>
    </p>

    <p class="body-text">
        1. Išmokėti darbuotojui <strong>{{ $employeeName }}</strong>@if (filled($employeePosition)) ({{ $employeePosition }})@endif
        priedą (premiją) <strong>{{ $bonusAmount }}</strong> už {{ $periodLabel }}.
    </p>

    <p class="body-text">
        2. Personalo ir buhalterijos tarnyboms apskaičiuoti mokesčius, įtraukti priedą į {{ $periodLabel }} darbo užmokestį
        ir išmokėti kartu su darbo užmokesčiu arba pagal nurodytą mokėjimo datą <strong>{{ $paymentDate }}</strong>.
    </p>

    <table class="meta-table">
        <tr><th>Darbuotojas</th><td>{{ $employeeName }}</td></tr>
        <tr><th>Laikotarpis</th><td>{{ $periodLabel }}</td></tr>
        <tr><th>Priedo suma (bruto)</th><td>{{ $bonusAmount }}</td></tr>
        <tr><th>Mokėjimo data</th><td>{{ $paymentDate }}</td></tr>
        @if (filled($comment))
            <tr><th>Pastaba</th><td><div class="comment">{{ $comment }}</div></td></tr>
        @endif
    </table>

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-label">Generalinis direktorius / įgaliotas asmuo</div>
                <div class="sig-line">&nbsp;</div>
            </td>
            <td>
                <div class="sig-label">Data</div>
                <div class="sig-line">&nbsp;</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Sugeneruota sistemoje · Monthly payment #{{ $paymentId }}
    </div>
</body>
</html>
