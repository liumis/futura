{{ $appName }}

Reset your password

Hello {{ $userName }},

We received a request to reset the password for your {{ $appName }} account.

Open this link in your browser to set a new password:
{{ $actionUrl }}

@if($expireMinutes === 60)
This link expires in 1 hour.
@else
This link expires in {{ $expireMinutes }} minutes.
@endif

If you did not request a password reset, you can ignore this email. Your password will stay the same.
