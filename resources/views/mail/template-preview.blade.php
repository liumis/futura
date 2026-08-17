<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject !== '' ? $subject : ($appName ?? config('app.name')) }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#1a1f2e;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f0f2f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(43,58,103,0.08);">
                    <tr>
                        <td style="padding:24px 32px;border-bottom:1px solid #e8ecf1;">
                            <img src="{{ $logoUrl ?? asset('images/logo.svg') }}" alt="{{ $appName ?? config('app.name') }}" width="180" style="display:block;max-width:180px;height:auto;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 32px 8px;">
                            <div style="font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Subject</div>
                            <div style="font-size:17px;font-weight:700;color:#1a1f2e;margin-bottom:16px;">{{ $subject !== '' ? $subject : '—' }}</div>

                            <div style="font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">From</div>
                            <div style="font-size:14px;color:#334155;margin-bottom:16px;">{{ $fromName }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 24px;">
                            <div style="font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;">Body</div>
                            <div style="border:1px solid #e8ecf1;border-radius:10px;padding:16px;font-size:14px;line-height:1.6;color:#334155;white-space:pre-wrap;">{{ $body !== '' ? $body : '—' }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 24px;border-top:1px solid #e8ecf1;background-color:#fafbfc;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;">
                                Preview of template "{{ $templateName }}". When sending a warehouse order, ordered items are appended below the body and <code>{order_id}</code> in the subject is replaced with the order number.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
