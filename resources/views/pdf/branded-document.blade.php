<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>{{ $documentName }}</title>
    <style>
        @page { margin: 14mm 16mm 16mm; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.5;
        }
        .branding { width: 100%; margin-bottom: 10px; table-layout: fixed; }
        .branding td { border: none; vertical-align: middle; padding: 0; }
        .branding .logo-cell { width: 32mm; }
        .branding .logo-cell img { max-height: 14mm; max-width: 30mm; }
        .branding .info-cell { text-align: center; }
        .company { font-size: 12px; font-weight: 700; margin: 0 0 2px; color: #111111; }
        .company-meta { font-size: 8px; color: #555; margin: 0; }
        .title {
            font-size: 16px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 0.04em;
            color: #111111;
            margin: 14px 0 4px;
        }
        .doc-date {
            text-align: center;
            font-size: 10px;
            color: #475569;
            margin: 0 0 16px;
        }
        .body-html { color: #111; }
        .body-html p { margin: 0 0 10px; }
        .body-html h2, .body-html h3, .body-html h4 {
            color: #111111;
            margin: 16px 0 8px;
            font-weight: 700;
        }
        .body-html h2 { font-size: 14px; }
        .body-html h3 { font-size: 12.5px; }
        .body-html h4 { font-size: 11.5px; }
        .body-html ul, .body-html ol { margin: 0 0 10px; padding-left: 18px; }
        .body-html li { margin: 0 0 4px; }
        .body-html blockquote {
            margin: 0 0 10px;
            padding: 6px 10px;
            border-left: 2px solid #111111;
            color: #334155;
        }
        .body-html table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 12px;
        }
        .body-html th, .body-html td {
            border: 0.6pt solid #334155;
            padding: 5px 6px;
            text-align: left;
            vertical-align: top;
        }
        .body-html th { background: #f1f5f9; font-weight: 700; }
        .body-html a { color: #111111; }
        .footer {
            margin-top: 22px;
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

    <div class="title">{{ $documentName }}</div>
    <div class="doc-date">{{ $documentDate }}</div>

    <div class="body-html">{!! $bodyHtml !!}</div>
</body>
</html>
