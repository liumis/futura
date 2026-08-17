<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Document approval</title>
    <style>
        @page { margin: 12mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #2d2d2d;
            margin: 0;
        }
        .signature-stamp {
            border: 0.6pt solid #aaa;
            border-radius: 3px;
            padding: 4px 6px;
            width: 145px;
            font-size: 7px;
            line-height: 1.25;
        }
        .signature-stamp .label {
            font-weight: bold;
            font-size: 6.5px;
            margin-bottom: 2px;
        }
        .signature-stamp .name {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            margin: 2px 0;
        }
        .signature-stamp .meta {
            font-size: 7px;
            margin-top: 1px;
        }
    </style>
</head>
<body>
    <div class="signature-stamp">
        <div class="label">Document approval</div>
        <div class="name">{{ $approverName }}</div>
        <div class="meta">{{ $timestampLabel }}</div>
        <div class="meta">Purpose: Approval</div>
    </div>
</body>
</html>
