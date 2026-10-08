<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Platform Holidays reminder</title>
</head>
<body style="margin:0;padding:0;background:#f3f0eb;font-family:Arial,sans-serif;color:#151515;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:34px 16px;">
<tr>
<td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #dedad4;">
    <tr>
        <td style="padding:24px 30px;border-bottom:5px solid #ef5b3f;">
            <strong style="font-size:18px;letter-spacing:3px;">PLATFORM</strong>
        </td>
    </tr>

    <tr>
        <td style="padding:34px 30px;">
            @if ($reminderType === 'birthday')
                <div style="font-size:42px;line-height:1;margin-bottom:18px;">🎂</div>

                @if ($daysBefore === 0)
                    <p style="margin:0 0 8px;color:#ef5b3f;font-size:12px;font-weight:bold;letter-spacing:2px;">
                        BIRTHDAY ALERT
                    </p>

                    <h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;">
                        Today is {{ $person->name }}'s birthday!
                    </h1>

                    <p style="margin:0;font-size:18px;line-height:1.6;">
                        {{ $person->name }} is now <strong>{{ $age }}</strong> 🎉
                    </p>

                    <p style="margin:18px 0 0;font-size:15px;line-height:1.6;color:#555;">
                        Consider this your official reminder before someone says
                        “you remembered, right?” 😅
                    </p>
                @elseif ($daysBefore === 1)
                    <p style="margin:0 0 8px;color:#ef5b3f;font-size:12px;font-weight:bold;letter-spacing:2px;">
                        BIRTHDAY TOMORROW
                    </p>

                    <h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;">
                        {{ $person->name }} turns {{ $age }} tomorrow 🎈
                    </h1>

                    <p style="margin:0;font-size:15px;line-height:1.6;color:#555;">
                        Plenty of time to sort the card, cake or convincing last-minute excuse.
                    </p>
                @else
                    <p style="margin:0 0 8px;color:#ef5b3f;font-size:12px;font-weight:bold;letter-spacing:2px;">
                        BIRTHDAY HEADS-UP
                    </p>

                    <h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;">
                        {{ $person->name }} turns {{ $age }} in {{ $daysBefore }} days 🎉
                    </h1>

                    <p style="margin:0;font-size:15px;line-height:1.6;color:#555;">
                        You're getting this early because future-you will be grateful.
                    </p>
                @endif
            @else
                <div style="font-size:42px;line-height:1;margin-bottom:18px;">🎉</div>

                <p style="margin:0 0 8px;color:#ef5b3f;font-size:12px;font-weight:bold;letter-spacing:2px;">
                    WORK ANNIVERSARY
                </p>

                @if ($daysBefore === 0)
                    <h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;">
                        Today is {{ $person->name }}'s work anniversary!
                    </h1>

                    <p style="margin:0;font-size:18px;line-height:1.6;">
                        That's <strong>{{ $years }} {{ $years === 1 ? 'year' : 'years' }}</strong> at Platform 🎊
                    </p>
                @else
                    <h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;">
                        {{ $person->name }}'s work anniversary is
                        {{ $daysBefore === 1 ? 'tomorrow' : 'in ' . $daysBefore . ' days' }}
                    </h1>

                    <p style="margin:0;font-size:16px;line-height:1.6;">
                        They'll be celebrating
                        <strong>{{ $years }} {{ $years === 1 ? 'year' : 'years' }}</strong>
                        at Platform.
                    </p>
                @endif
            @endif

            <p style="margin:24px 0 0;padding-top:18px;border-top:1px solid #eee;color:#777;font-size:13px;">
                {{ CarbonCarbon::parse($eventDate)->format('l j F Y') }}
            </p>
        </td>
    </tr>
</table>
</td>
</tr>
</table>
</body>
</html>
