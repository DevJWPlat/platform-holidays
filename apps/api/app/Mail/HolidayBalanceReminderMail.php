<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HolidayBalanceReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $personName,
        public readonly int|float $remainingDays,
        public readonly int $monthsRemaining,
        public readonly string $leaveYearEnd,
    ) {
    }

    public function envelope(): Envelope
    {
        $remaining = rtrim(
            rtrim(number_format($this->remainingDays, 2), '0'),
            '.',
        );

        return new Envelope(
            subject:
                "🏖️ {$this->monthsRemaining} months left — "
                . "{$remaining} holiday days remaining",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.holiday-balance-reminder',
        );
    }
}
