<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WholeCompanyGroupBookingService;
use Illuminate\Console\Command;

class SyncWholeCompanyBookingsForPerson extends Command
{
    protected $signature = 'holidays:sync-whole-company-bookings
        {person : User ID or email}
        {--commit : Actually create missing leave requests}';

    protected $description = 'Preview or attach a person to current/future whole-company group bookings';

    public function handle(WholeCompanyGroupBookingService $service): int
    {
        $value = (string) $this->argument('person');

        $person = User::query()
            ->where('id', ctype_digit($value) ? (int) $value : -1)
            ->orWhere('email', $value)
            ->first();

        if (! $person) {
            $this->error('Person not found.');
            return self::FAILURE;
        }

        $actor = User::query()
            ->where('organisation_id', $person->organisation_id)
            ->where('role', 'administrator')
            ->where('is_archived', false)
            ->orderBy('id')
            ->first();

        if (! $actor) {
            $this->error('No active administrator found for this organisation.');
            return self::FAILURE;
        }

        $this->info('Person: ' . $person->name . ' <' . $person->email . '>');

        if (! $this->option('commit')) {
            $this->comment('PREVIEW ONLY - no bookings were changed.');
            $this->line('Run again with --commit to attach missing whole-company bookings.');
            return self::SUCCESS;
        }

        $count = $service->addPersonToCurrentAndFutureBookings($person, $actor);

        $this->info($count . ' whole-company booking(s) added.');
        return self::SUCCESS;
    }
}
