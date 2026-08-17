@php($ssAppName = config('app.name', 'FuturaTextiles SS'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $ssAppName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #e6e9ef;border-radius:10px;overflow:hidden;">
                    <tr>
                        <td>@include('emails.partials.header')</td>
                    </tr>
                    <tr>
                        <td style="padding:26px 28px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#334155;">
                            {!! $content !!}
                        </td>
                    </tr>
                    <tr>
                        <td>@include('emails.partials.footer')</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
