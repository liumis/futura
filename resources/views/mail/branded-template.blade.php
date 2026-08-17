<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $subject ?? ($appName ?? config('app.name')) }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f0f2f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(43,58,103,0.08);">
                    <tr>
                        <td style="padding:28px 36px 20px;text-align:center;border-bottom:1px solid #e8ecf1;">
                            <img src="{{ $logoUrl ?? asset('images/logo.svg') }}" alt="{{ $appName ?? config('app.name') }}" width="210" height="auto" style="display:block;margin:0 auto;max-width:210px;height:auto;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 36px 16px;">
                            <h1 style="margin:0 0 14px;font-size:22px;font-weight:700;color:#1a1f2e;line-height:1.3;">
                                {{ $heading ?? 'Important update' }}
                            </h1>
                            <p style="margin:0 0 14px;font-size:15px;line-height:1.55;color:#4a5568;">
                                Hello {{ $recipientName ?? 'Customer' }},
                            </p>
                            <p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#4a5568;">
                                {{ $intro ?? 'This is a preview of your branded email template. You can reuse this layout for notifications, reminders, and order updates.' }}
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;border:1px solid #e8ecf1;border-radius:10px;overflow:hidden;">
                                <tr>
                                    <td style="padding:14px 16px;background:#fafbfc;border-bottom:1px solid #e8ecf1;font-size:13px;color:#64748b;font-weight:600;">
                                        {{ $detailsTitle ?? 'Details' }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;font-size:14px;line-height:1.6;color:#334155;">
                                        {!! nl2br(e($details ?? "Order #: 100245\nStatus: Processing\nExpected dispatch: 2026-04-28")) !!}
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 20px;">
                                <tr>
                                    <td style="border-radius:8px;background-color:#2b3a67;">
                                        <a href="{{ $actionUrl ?? url('/') }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
                                            {{ $actionText ?? 'Open dashboard' }}
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0;font-size:13px;line-height:1.55;color:#718096;">
                                {{ $footerNote ?? 'If you have any questions, reply to this email and our team will help you.' }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 36px 26px;border-top:1px solid #e8ecf1;background-color:#fafbfc;">
                            <p style="margin:0;font-size:12px;line-height:1.45;color:#94a3b8;text-align:center;">
                                {{ $appName ?? config('app.name') }} - Branded email template preview
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
