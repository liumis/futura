<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $appName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f0f2f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(43,58,103,0.08);">
                    <tr>
                        <td style="padding:32px 40px 24px;text-align:center;border-bottom:1px solid #e8ecf1;">
                            <img src="{{ $logoUrl }}" alt="{{ $appName }}" width="200" height="auto" style="display:block;margin:0 auto;max-width:200px;height:auto;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 40px 8px;">
                            <h1 style="margin:0 0 16px;font-size:20px;font-weight:600;color:#1a1f2e;line-height:1.35;">
                                Reset your password
                            </h1>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.55;color:#4a5568;">
                                Hello {{ $userName }},
                            </p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.55;color:#4a5568;">
                                We received a request to reset the password for your {{ $appName }} account. Use the button below to choose a new password.
                            </p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 28px;">
                                <tr>
                                    <td style="border-radius:8px;background-color:#2b3a67;">
                                        <a href="{{ $actionUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
                                            Set new password
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 12px;font-size:13px;line-height:1.5;color:#718096;">
                                @if($expireMinutes === 60)
                                    This link expires in 1 hour.
                                @else
                                    This link expires in {{ $expireMinutes }} minutes.
                                @endif
                            </p>
                            <p style="margin:0;font-size:13px;line-height:1.5;color:#718096;">
                                If you did not request a password reset, you can ignore this email. Your password will stay the same.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 40px 32px;border-top:1px solid #e8ecf1;background-color:#fafbfc;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#a0aec0;text-align:center;">
                                {{ $appName }}
                            </p>
                        </td>
                    </tr>
                </table>
                <p style="margin:24px 0 0;font-size:12px;color:#a0aec0;text-align:center;max-width:560px;">
                    If the button does not work, copy and paste this link into your browser:<br>
                    <span style="word-break:break-all;color:#718096;">{{ $actionUrl }}</span>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
