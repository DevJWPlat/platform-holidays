<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Holiday balance reminder</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f2;font-family:Arial,sans-serif;color:#202020;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-collapse:collapse;">
                <tr>
                    <td style="padding:30px 32px 16px;">
                        <div style="font-size:12px;font-weight:700;letter-spacing:2px;color:#ef5b3f;">
                            PLATFORM HOLIDAYS
                        </div>

                        <h1 style="margin:12px 0 10px;font-size:28px;line-height:1.2;">
                            Time to check your holiday balance 👀
                        </h1>

                        <p style="margin:0;font-size:16px;line-height:1.6;color:#555;">
                            Hi {{ $personName }},
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:0 32px 24px;">
                        <p style="margin:0 0 18px;font-size:16px;line-height:1.65;color:#555;">
                            There are <strong>{{ $monthsRemaining }} months</strong>
                            left in the current leave year, so this is your reminder
                            to make sure you don't accidentally leave your days sitting
                            there looking lonely.
                        </p>

                        <div style="padding:22px;background:#111;color:#fff;">
                            <div style="font-size:12px;letter-spacing:1.5px;color:#ef5b3f;font-weight:700;">
                                REMAINING HOLIDAY
                            </div>
                            <div style="margin-top:8px;font-size:34px;font-weight:700;">
                                {{ rtrim(rtrim(number_format($remainingDays, 2), '0'), '.') }}
                                <span style="font-size:16px;font-weight:400;color:#aaa;">days</span>
                            </div>
                        </div>

                        <p style="margin:20px 0 0;font-size:14px;line-height:1.6;color:#666;">
                            The current leave year ends on
                            <strong>{{ $leaveYearEnd }}</strong>.
                            If you've been meaning to book something, future-you may
                            appreciate you doing it before everyone else has the same idea.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 32px;border-top:1px solid #ececea;font-size:12px;color:#888;">
                        This is an automatic Platform Holidays balance reminder.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
