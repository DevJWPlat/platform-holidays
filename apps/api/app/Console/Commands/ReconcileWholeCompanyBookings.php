<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WholeCompanyGroupBookingService;
use Illuminate\Console\Command;

class ReconcileWholeCompanyBookings extends Command
{
    protected $signature = 'holidays:reconcile-whole-company-bookings
        {--commit : Actually create missing leave requests}';

    protected $description = 'Find active people missing current/future whole-company group bookings and attach them';

    public function handle(WholeCompanyGroupBookingService $service): int
    {
        $people = User::query()
            ->where('is_archived', false)
            ->with('organisation')
            ->orderBy('organisation_id')
            ->orderBy('name')
            ->get();

        if ($people->isEmpty()) {
            $this->info('No active people found.');
            return self::SUCCESS;
        }

        $rows = [];
        $totalCreated = 0;

        foreach ($people as $person) {
            $actor = User::query()
                ->where('organisation_id', $person->organisation_id)
                ->where('role', 'administrator')
                ->where('is_archived', false)
                ->orderBy('id')
                ->first();

            if (! $actor) {
                $rows[] = [
                    $person->name,
                    $person->email,
                    'NO ADMIN',
                    '—',
                ];
                continue;
            }

            if (! $this->option('commit')) {
                $rows[] = [
                    $person->name,
                    $person->email,
                    'CHECK',
                    'Preview only',
                ];
                continue;
            }

            $created = $service->addPersonToCurrentAndFutureBookings($person, $actor);
            $totalCreated += $created;

            $rows[] = [
                $person->name,
                $person->email,
                $created > 0 ? 'ADDED' : 'OK',
                (string) $created,
            ];
        }

        $this->table(
            ['Person', 'Email', 'Status', 'Bookings added'],
            $rows,
        );

        if (! $this->option('commit')) {
            $this->comment('PREVIEW ONLY - no bookings were changed.');
            $this->line('Run again with --commit to reconcile missing whole-company bookings.');
            return self::SUCCESS;
        }

        $this->info($totalCreated . ' missing whole-company booking(s) created.');
        return self::SUCCESS;
    }
}
