<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PeopleReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public int $age;

    public function __construct(
        public User $person,
        public string $reminderType,
        public int $daysBefore,
        public string $eventDate,
        public ?int $years = null,
    ) {
        $this->age = $this->reminderType === 'birthday'
            && $this->person->date_of_birth
            ? CarbonImmutable::parse($this->eventDate)->year
                - $this->person->date_of_birth->year
            : 0;
    }

    public function build(): self
    {
        if ($this->reminderType === 'birthday') {
            $subject = match ($this->daysBefore) {
                0 => sprintf(
                    '🎂 Today is %s’s birthday — %d today!',
                    $this->person->name,
                    $this->age,
                ),
                1 => sprintf(
                    '🎈 %s turns %d tomorrow!',
                    $this->person->name,
                    $this->age,
                ),
                default => sprintf(
                    '🎉 %s turns %d in %d days',
                    $this->person->name,
                    $this->age,
                    $this->daysBefore,
                ),
            };
        } else {
            $subject = match ($this->daysBefore) {
                0 => sprintf(
                    '🎉 Today is %s’s work anniversary!',
                    $this->person->name,
                ),
                1 => sprintf(
                    '🎉 %s’s work anniversary is tomorrow',
                    $this->person->name,
                ),
                default => sprintf(
                    '🎉 %s’s work anniversary is in %d days',
                    $this->person->name,
                    $this->daysBefore,
                ),
            };
        }

        return $this
            ->subject($subject)
            ->view('mail.people-reminder');
    }
}
