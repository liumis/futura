<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>Prašymas grąžinti</title>
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
            margin-bottom: 8px;
            color: #334155;
        }
        .company-name {
            font-weight: 700;
            color: #111111;
            font-size: 13px;
        }
        .recipient {
            margin: 28px 0 24px;
        }
        .recipient-label {
            color: #64748b;
            font-size: 11px;
            margin-bottom: 4px;
        }
        .title {
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.06em;
            color: #111111;
            margin: 28px 0 28px;
            text-transform: uppercase;
        }
        .body-text {
            margin-bottom: 16px;
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
            font-weight: 700;
            color: #334155;
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
    </style>
</head>
<body>
    <div class="place-date">Šiauliai, {{ $documentDate }}</div>

    <div class="company">
        <div class="company-name">{{ $companyName }}</div>
        @if (filled($companyAddress))
            <div>{{ $companyAddress }}</div>
        @endif
        @if (filled($companyId) || filled($companyVat))
            <div>
                @if (filled($companyId))Įm. kodas {{ $companyId }}@endif
                @if (filled($companyId) && filled($companyVat)) · @endif
                @if (filled($companyVat))PVM {{ $companyVat }}@endif
            </div>
        @endif
        @if (filled($companyEmail))
            <div>{{ $companyEmail }}</div>
        @endif
    </div>

    <div class="recipient">
        <div class="recipient-label">Kam / To</div>
        <div class="company-name">{{ $counterpartName }}</div>
        @if (filled($counterpartContact))
            <div>{{ $counterpartContact }}</div>
        @endif
    </div>

    <div class="title">Prašymas grąžinti lėšas<br><span style="font-size:14px;letter-spacing:0.04em;">Request for refund</span></div>

    <p class="body-text">
        Prašome grąžinti (arba patvirtiname, kad grąžiname) žemiau nurodytą sumą, susijusią su klaidingai atliktu mokėjimu.
        Mokėjimas pagal sąskaitą / dokumentą <strong>{{ $invoiceReference }}</strong> buvo skirtas kitai įmonei / kitai sąskaitai,
        todėl lėšos grąžinamos iš {{ $companyName }} sąskaitos.
    </p>
    <p class="body-text">
        Please refund (or we hereby confirm the refund of) the amount below related to a payment sent to the wrong bank account.
        The payment for invoice / document <strong>{{ $invoiceReference }}</strong> was intended for another company / account;
        the funds are returned from the {{ $companyName }} account.
    </p>

    <table class="meta-table">
        <tr>
            <th>Suma / Amount</th>
            <td>{{ $amountFormatted }}</td>
        </tr>
        <tr>
            <th>Sąskaita / Invoice</th>
            <td>{{ $invoiceReference }}</td>
        </tr>
        <tr>
            <th>Kontrahentas / Counterpart</th>
            <td>{{ $counterpartName }}</td>
        </tr>
        <tr>
            <th>Data / Date</th>
            <td>{{ $documentDate }}</td>
        </tr>
        <tr>
            <th>Priežastis / Reason</th>
            <td>{{ $reason }}</td>
        </tr>
        @if (filled($bankReference))
            <tr>
                <th>Banko eilutė / Bank ref.</th>
                <td>{{ $bankReference }}</td>
            </tr>
        @endif
    </table>

    <p class="body-text">
        Gavę lėšas, prašome jas pervesti į teisingą banko sąskaitą pagal originalią sąskaitą faktūrą.
        Once the funds are received, please transfer them to the correct bank account according to the original invoice.
    </p>

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-label">Parengė / Prepared by</div>
                <div class="sig-line">{{ $preparedBy }}</div>
            </td>
            <td>
                <div class="sig-label">Data / Date</div>
                <div class="sig-line">{{ $documentDate }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
