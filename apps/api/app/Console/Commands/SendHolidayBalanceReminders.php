<?php

namespace App\Console\Commands;

use App\Mail\HolidayBalanceReminderMail;
use App\Models\HolidayBalanceReminderDispatch;
use App\Models\User;
use App\Services\AllowanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendHolidayBalanceReminders extends Command
{
    protected $signature =
        'holidays:send-balance-reminders '
        . '{--force= : Force 6 or 3 month reminders for testing}';

    protected $description =
        'Send staff holiday-balance reminders at 6 and 3 months remaining.';

    public function handle(
        AllowanceService $allowanceService,
    ): int {
        $forced = $this->option('force');

        if (
            $forced !== null
            && ! in_array((int) $forced, [6, 3], true)
        ) {
            $this->error('--force must be 6 or 3.');

            return self::FAILURE;
        }

        $users = User::query()
            ->where('is_archived', false)
            ->whereNotNull('email')
            ->with('organisation')
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            if (! $user->organisation) {
                continue;
            }

            $timezone =
                $user->organisation->timezone ?: 'Europe/London';

            $today = CarbonImmutable::now($timezone)->startOfDay();

            $allowance = $allowanceService->ensureYear(
                $user,
                $today,
            );

            $leaveYearStart = CarbonImmutable::parse(
                $allowance['leave_year']['start'],
                $timezone,
            )->startOfDay();

            $leaveYearEnd = CarbonImmutable::parse(
                $allowance['leave_year']['end'],
                $timezone,
            )->startOfDay();

            $nextLeaveYearStart = $leaveYearEnd->addDay();

            $months = $forced !== null
                ? [(int) $forced]
                : [6, 3];

            foreach ($months as $monthsRemaining) {
                $sendDate =
                    $nextLeaveYearStart->subMonthsNoOverflow(
                        $monthsRemaining,
                    );

                if (
                    $forced === null
                    && ! $today->isSameDay($sendDate)
                ) {
                    continue;
                }

                $alreadySent =
                    HolidayBalanceReminderDispatch::query()
                        ->where('user_id', $user->id)
                        ->whereDate(
                            'leave_year_start',
                            $leaveYearStart->toDateString(),
                        )
                        ->where(
                            'months_remaining',
                            $monthsRemaining,
                        )
                        ->exists();

                if ($alreadySent && $forced === null) {
                    continue;
                }

                Mail::to($user->email)->send(
                    new HolidayBalanceReminderMail(
                        personName: $user->name,
                        remainingDays:
                            (float) $allowance['remaining_days'],
                        monthsRemaining: $monthsRemaining,
                        leaveYearEnd:
                            $leaveYearEnd->format('j F Y'),
                    ),
                );

                HolidayBalanceReminderDispatch::query()
                    ->updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'leave_year_start' =>
                                $leaveYearStart->toDateString(),
                            'months_remaining' =>
                                $monthsRemaining,
                        ],
                        [
                            'sent_at' => now(),
                        ],
                    );

                $sent++;

                $this->line(
                    sprintf(
                        'Sent %d-month reminder to %s.',
                        $monthsRemaining,
                        $user->email,
                    ),
                );
            }
        }

        $this->info(
            sprintf(
                'Holiday balance reminders sent: %d',
                $sent,
            ),
        );

        return self::SUCCESS;
    }
}
