<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Document signing invitation</title>
</head>
<body style="margin:0;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#1a1f2e;background:#f8fafc;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px;">
        <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.04em;text-transform:uppercase;color:#64748b;">
            {{ $appName }}
        </p>
        <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">
            Please sign “{{ $document->name }}”
        </h1>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#334155;">
            Hello {{ $signer->displayName() }},
        </p>
        <p style="margin:0 0 20px;font-size:14px;line-height:1.5;color:#334155;">
            You have been invited to sign this document electronically via Dokobit.
            You do not need an account in our system — open the link below and complete signing.
        </p>
        <p style="margin:0 0 24px;">
            <a href="{{ $signingUrl }}"
               style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;font-size:14px;font-weight:600;">
                Open Dokobit signing
            </a>
        </p>
        <p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;">
            If the button does not work, copy this URL into your browser:<br>
            {{ $signingUrl }}
        </p>
    </div>
</body>
</html>
