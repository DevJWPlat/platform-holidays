<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Platform Holidays</title>
</head>
<body style="margin:0;padding:0;background:#f3f0eb;font-family:Arial,sans-serif;color:#151515;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:34px 16px;">
<tr>
<td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border:1px solid #dedad4;">
<tr>
<td style="padding:24px 30px;border-bottom:5px solid #ef5b3f;">
    <strong style="font-size:18px;letter-spacing:3px;">PLATFORM</strong>
</td>
</tr>

<tr>
<td style="padding:32px 30px;">
    @php
        $person = $leaveRequest->user;
        $leaveType = $leaveRequest->leaveType?->label ?? 'Time off';

        $start = $leaveRequest->starts_on?->format('j F Y');
        $end = $leaveRequest->ends_on?->format('j F Y');

        $period = $start === $end
            ? $start
            : $start . ' — ' . $end;

        $session = null;

        if (
            $leaveRequest->starts_on?->toDateString()
                === $leaveRequest->ends_on?->toDateString()
        ) {
            if (
                $leaveRequest->start_session === 'morning'
                && $leaveRequest->end_session === 'morning'
            ) {
                $session = 'Morning';
            } elseif ($leaveRequest->start_session === 'afternoon') {
                $session = 'Afternoon';
            }
        }
    @endphp

    @if ($event === 'submitted')
        <p style="margin:0 0 8px;color:#ef5b3f;font-size:12px;font-weight:bold;letter-spacing:2px;">
            NEW LEAVE REQUEST
        </p>

        <h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;">
            {{ $person->name }} has requested {{ strtolower($leaveType) }}
        </h1>

        <p style="margin:0 0 20px;color:#555;font-size:15px;line-height:1.6;">
            There’s a new request waiting for review in Platform Holidays.
        </p>
    @elseif ($event === 'approved')
        <p style="margin:0 0 8px;color:#7ca536;font-size:12px;font-weight:bold;letter-spacing:2px;">
            REQUEST APPROVED
        </p>

        <h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;">
            Your {{ strtolower($leaveType) }} is approved 🎉
        </h1>

        <p style="margin:0 0 20px;color:#555;font-size:15px;line-height:1.6;">
            That’s sorted — it’s now approved in Platform Holidays.
        </p>
    @elseif ($event === 'rejected')
        <p style="margin:0 0 8px;color:#ef5b3f;font-size:12px;font-weight:bold;letter-spacing:2px;">
            REQUEST NOT APPROVED
        </p>

        <h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;">
            Your {{ strtolower($leaveType) }} request wasn’t approved
        </h1>

        <p style="margin:0 0 20px;color:#555;font-size:15px;line-height:1.6;">
            Have a look at the review note below, then speak to your approver if needed.
        </p>
    @endif

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f8f7f5;">
        <tr>
            <td style="padding:12px 14px;border-bottom:1px solid #e8e4de;color:#777;font-size:12px;">Type</td>
            <td style="padding:12px 14px;border-bottom:1px solid #e8e4de;font-size:14px;font-weight:bold;">{{ $leaveType }}</td>
        </tr>
        <tr>
            <td style="padding:12px 14px;border-bottom:1px solid #e8e4de;color:#777;font-size:12px;">Date</td>
            <td style="padding:12px 14px;border-bottom:1px solid #e8e4de;font-size:14px;font-weight:bold;">
                {{ $period }}
                @if ($session)
                    · {{ $session }}
                @endif
            </td>
        </tr>

        @if ($leaveRequest->reason)
            <tr>
                <td style="padding:12px 14px;border-bottom:1px solid #e8e4de;color:#777;font-size:12px;">Reason</td>
                <td style="padding:12px 14px;border-bottom:1px solid #e8e4de;font-size:14px;">{{ $leaveRequest->reason }}</td>
            </tr>
        @endif

        @if ($event === 'rejected' && $leaveRequest->review_note)
            <tr>
                <td style="padding:12px 14px;color:#777;font-size:12px;">Review note</td>
                <td style="padding:12px 14px;font-size:14px;font-weight:bold;">{{ $leaveRequest->review_note }}</td>
            </tr>
        @endif
    </table>

    @if ($actor)
        <p style="margin:18px 0 0;color:#777;font-size:12px;">
            Reviewed by {{ $actor->name }}.
        </p>
    @endif
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
