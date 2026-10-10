@php($ssAppName = config('app.name', 'FuturaTextiles SS'))
@php($ssLogoUrl = rtrim((string) config('app.url'), '/').'/images/logo-white.png')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#000000;">
    <tr>
        <td style="padding:20px 28px;text-align:center;">
            <img src="{{ $ssLogoUrl }}" alt="{{ $ssAppName }}" width="200" height="auto" style="display:block;margin:0 auto;max-width:200px;height:auto;">
        </td>
    </tr>
</table>
