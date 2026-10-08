<?php

namespace App\Console\Commands;

use App\Mail\PeopleReminderMail;
use App\Models\NotificationDispatch;
use App\Models\Organisation;
use App\Models\OrganisationSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPeopleReminders extends Command
{
    protected $signature = 'holidays:send-reminders {--force : Ignore configured send hour}';

    protected $description = 'Send configured birthday and work-anniversary reminder emails.';

    public function handle(): int
    {
        Organisation::query()
            ->where('is_active', true)
            ->each(function (Organisation $organisation) {
                $this->processOrganisation($organisation);
            });

        return self::SUCCESS;
    }

    private function processOrganisation(Organisation $organisation): void
    {
        $settings = array_merge(
            $this->defaults(),
            OrganisationSetting::query()
                ->where('organisation_id', $organisation->id)
                ->where('key', 'notifications')
                ->value('value') ?? [],
        );

        $timezone = $organisation->timezone ?: 'Europe/London';
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if (
            ! $this->option('force')
            && $today->setTime((int) $settings['send_hour'], 0)->hour
                !== CarbonImmutable::now($timezone)->hour
        ) {
            return;
        }

        $recipients = User::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_archived', false)
            ->whereIn('id', $settings['recipient_user_ids'])
            ->whereNotNull('email')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $people = User::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_archived', false)
            ->get();

        if ($settings['birthday_enabled']) {
            foreach ($people->whereNotNull('date_of_birth') as $person) {
                $this->checkBirthday(
                    $organisation,
                    $person,
                    $recipients,
                    $today,
                    $settings['birthday_days_before'],
                );
            }
        }

        if ($settings['anniversary_enabled']) {
            foreach ($people->whereNotNull('employment_start_date') as $person) {
                $this->checkAnniversary(
                    $organisation,
                    $person,
                    $recipients,
                    $today,
                    $settings['anniversary_days_before'],
                );
            }
        }
    }

    private function checkBirthday(
        Organisation $organisation,
        User $person,
        $recipients,
        CarbonImmutable $today,
        array $offsets,
    ): void {
        $event = $person->date_of_birth
            ->setYear($today->year)
            ->startOfDay();

        if ($event->isBefore($today)) {
            $event = $event->addYear();
        }

        $daysBefore = $today->diffInDays($event, false);

        if (! in_array($daysBefore, $offsets, true)) {
            return;
        }

        $this->send(
            organisation: $organisation,
            person: $person,
            recipients: $recipients,
            type: 'birthday',
            daysBefore: $daysBefore,
            eventDate: $event->toDateString(),
        );
    }

    private function checkAnniversary(
        Organisation $organisation,
        User $person,
        $recipients,
        CarbonImmutable $today,
        array $offsets,
    ): void {
        $event = $person->employment_start_date
            ->setYear($today->year)
            ->startOfDay();

        if ($event->isBefore($today)) {
            $event = $event->addYear();
        }

        $daysBefore = $today->diffInDays($event, false);

        if (! in_array($daysBefore, $offsets, true)) {
            return;
        }

        $years = $event->year - $person->employment_start_date->year;

        if ($years < 1) {
            return;
        }

        $this->send(
            organisation: $organisation,
            person: $person,
            recipients: $recipients,
            type: 'anniversary',
            daysBefore: $daysBefore,
            eventDate: $event->toDateString(),
            years: $years,
        );
    }

    private function send(
        Organisation $organisation,
        User $person,
        $recipients,
        string $type,
        int $daysBefore,
        string $eventDate,
        ?int $years = null,
    ): void {
        $eventKey = sprintf(
            '%s-%s-%d-before',
            $eventDate,
            $person->id,
            $daysBefore,
        );

        foreach ($recipients as $recipient) {
            $alreadySent = NotificationDispatch::query()
                ->where('organisation_id', $organisation->id)
                ->where('type', $type)
                ->where('event_key', $eventKey)
                ->where('recipient_email', $recipient->email)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            Mail::to($recipient->email)->send(
                new PeopleReminderMail(
                    person: $person,
                    reminderType: $type,
                    daysBefore: $daysBefore,
                    eventDate: $eventDate,
                    years: $years,
                ),
            );

            NotificationDispatch::query()->create([
                'organisation_id' => $organisation->id,
                'person_id' => $person->id,
                'recipient_id' => $recipient->id,
                'type' => $type,
                'event_key' => $eventKey,
                'recipient_email' => $recipient->email,
                'sent_at' => now(),
            ]);

            $this->info(sprintf(
                'Sent %s reminder for %s to %s.',
                $type,
                $person->name,
                $recipient->email,
            ));
        }
    }

    private function defaults(): array
    {
        return [
            'birthday_enabled' => true,
            'birthday_days_before' => [7, 0],
            'anniversary_enabled' => true,
            'anniversary_days_before' => [7, 0],
            'recipient_user_ids' => [],
            'send_hour' => 9,
        ];
    }
}
