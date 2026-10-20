<?php

namespace App\Console\Commands;

use App\Models\AllowanceLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organisation;
use App\Models\User;
use App\Services\AllowanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ImportTimetasticHistory extends Command
{
    protected $signature = 'holidays:import-timetastic
        {--commit : Write the import or allowance reconciliation to the database}
        {--audit : Compare Platform allowance totals with Timetastic snapshots}
        {--reconcile-allowances : Preview/reconcile historical allowance balances from Timetastic}
        {--year=* : Restrict preview/import/audit/reconciliation to one or more years}';

    protected $description = 'Preview/import Timetastic history (2022-2027) and audit holiday balances';

    private array $historicalInactiveTypes = [
        'working from home',
        'day in the office',
        'car valet',
        'birthday',
    ];

    public function handle(AllowanceService $allowanceService): int
    {
        $path = database_path('imports/timetastic-all-years.json');

        if (! is_file($path)) {
            $this->error("Import data file not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload)) {
            $this->error('Timetastic import data could not be decoded.');
            return self::FAILURE;
        }

        $organisation = Organisation::query()
            ->where('slug', 'platform')
            ->firstOrFail();

        $years = collect($this->option('year'))
            ->map(fn ($year) => (int) $year)
            ->filter()
            ->unique()
            ->values();

        if ($this->option('reconcile-allowances')) {
            return $this->reconcileAllowances(
                $organisation,
                $payload['allowance_snapshots'] ?? [],
                $allowanceService,
                $years->all(),
                (bool) $this->option('commit'),
            );
        }

        if ($this->option('audit')) {
            return $this->audit(
                $organisation,
                $payload['allowance_snapshots'] ?? [],
                $allowanceService,
                $years->all(),
            );
        }

        $bookings = collect($payload['bookings'] ?? [])
            ->when(
                $years->isNotEmpty(),
                fn ($items) => $items->filter(
                    fn ($item) => collect($item['report_years'] ?? [])
                        ->intersect($years)
                        ->isNotEmpty()
                )
            )
            ->values();

        $users = User::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_archived', false)
            ->get()
            ->keyBy(fn (User $user) => $this->normalise($user->name));

        $missingUsers = $bookings
            ->pluck('user_name')
            ->unique()
            ->filter(fn ($name) => ! $users->has($this->normalise($name)))
            ->values();

        $existingTypes = LeaveType::query()
            ->where('organisation_id', $organisation->id)
            ->get()
            ->keyBy(fn (LeaveType $type) => $this->normalise($type->label));

        $neededTypes = $bookings
            ->pluck('leave_type')
            ->unique()
            ->values();

        $missingTypes = $neededTypes
            ->filter(fn ($label) => ! $existingTypes->has($this->normalise($label)))
            ->values();

        $alreadyTracked = DB::table('timetastic_imports')
            ->whereIn('booking_id', $bookings->pluck('booking_id')->all())
            ->pluck('booking_id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        $ready = $bookings->filter(
            fn ($item) => ! $alreadyTracked->has((string) $item['booking_id'])
        );

        $this->newLine();
        $this->info('Timetastic history import preview');
        $this->line('Annual reports included: 2022, 2023, 2024, 2025, 2026, 2027');
        $this->line('Unique non-cancelled/non-declined bookings in bundle: ' . count($payload['bookings'] ?? []));
        $this->line('Bookings in this run: ' . $bookings->count());
        $this->line('Already imported/tracked: ' . $bookings->count() - $ready->count());
        $this->line('Ready to import: ' . $ready->count());
        $this->line('Cancelled/declined source rows omitted: ' . ($payload['cancelled_and_declined_omitted'] ?? 0));

        if ($missingUsers->isNotEmpty()) {
            $this->newLine();
            $this->warn('Missing users:');
            foreach ($missingUsers as $name) {
                $this->line('  - ' . $name);
            }
        }

        if ($missingTypes->isNotEmpty()) {
            $this->newLine();
            $this->info('Leave types that will be created on commit:');
            foreach ($missingTypes as $label) {
                $suffix = in_array($this->normalise($label), $this->historicalInactiveTypes, true)
                    ? ' (historical/inactive)'
                    : '';
                $this->line('  - ' . $label . $suffix);
            }
        }

        $this->newLine();
        $this->info('Ready by year:');
        foreach (
            $ready
                ->flatMap(fn ($item) => $item['report_years'] ?? [])
                ->countBy()
                ->sortKeys()
            as $year => $count
        ) {
            $this->line(sprintf('  %-6s %5d', $year, $count));
        }

        $this->newLine();
        $this->info('Ready by leave type:');
        foreach (
            $ready->countBy('leave_type')->sortKeys()
            as $type => $count
        ) {
            $this->line(sprintf('  %-28s %5d', $type, $count));
        }

        if ($missingUsers->isNotEmpty()) {
            $this->newLine();
            $this->error('Import stopped. Add/fix the missing users first.');
            return self::FAILURE;
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->comment('PREVIEW ONLY - nothing was written.');
            $this->line('When this looks right, rerun with: php artisan holidays:import-timetastic --commit');
            return self::SUCCESS;
        }

        DB::transaction(function () use (
            $organisation,
            $payload,
            $bookings,
            $users,
            $existingTypes,
            $missingTypes,
            $allowanceService,
        ) {
            $types = $existingTypes;

            foreach ($missingTypes as $label) {
                $type = $this->createMissingType($organisation->id, $label);
                $types->put($this->normalise($label), $type);
            }

            foreach ($bookings as $item) {
                $bookingId = (string) $item['booking_id'];

                $tracked = DB::table('timetastic_imports')
                    ->where('booking_id', $bookingId)
                    ->first();

                if ($tracked) {
                    continue;
                }

                /** @var User $user */
                $user = $users->get($this->normalise($item['user_name']));

                /** @var LeaveType|null $leaveType */
                $leaveType = $types->get($this->normalise($item['leave_type']));

                if (! $leaveType) {
                    throw new \RuntimeException(
                        'Leave type could not be resolved: ' . $item['leave_type']
                    );
                }

                $probableExisting = LeaveRequest::query()
                    ->where('user_id', $user->id)
                    ->where('leave_type_id', $leaveType->id)
                    ->whereDate('starts_on', $item['starts_on'])
                    ->whereDate('ends_on', $item['ends_on'])
                    ->where('duration_minutes', (int) $item['duration_minutes'])
                    ->first();

                if ($probableExisting) {
                    DB::table('timetastic_imports')->insert([
                        'booking_id' => $bookingId,
                        'leave_request_id' => $probableExisting->id,
                        'report_years' => json_encode($item['report_years'] ?? []),
                        'source_payload' => json_encode($item),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    continue;
                }

                $leaveRequest = new LeaveRequest();

                $attributes = [
                    'user_id' => $user->id,
                    'leave_type_id' => $leaveType->id,
                    'starts_on' => $item['starts_on'],
                    'start_session' => $item['start_session'] ?: 'morning',
                    'ends_on' => $item['ends_on'],
                    'end_session' => $item['end_session'] ?: 'afternoon',
                    'duration_minutes' => max(1, (int) $item['duration_minutes']),
                    'status' => $item['status'] === 'pending'
                        ? LeaveRequest::STATUS_PENDING
                        : LeaveRequest::STATUS_APPROVED,
                    'reason' => $item['reason'] ?: null,
                    'reviewed_at' => $item['status'] === 'approved'
                        ? ($item['approved_at'] ?: $item['requested_at'] ?: now())
                        : null,
                ];

                if (Schema::hasColumn('leave_requests', 'is_manual')) {
                    $attributes['is_manual'] = true;
                }

                if (Schema::hasColumn('leave_requests', 'external_source')) {
                    $attributes['external_source'] = 'timetastic';
                }

                if (Schema::hasColumn('leave_requests', 'external_import_source')) {
                    $attributes['external_import_source'] = 'timetastic';
                }

                foreach ([
                    'external_booking_id',
                    'external_id',
                    'external_import_id',
                    'external_reference',
                ] as $column) {
                    if (Schema::hasColumn('leave_requests', $column)) {
                        $attributes[$column] = $bookingId;
                    }
                }

                $leaveRequest->forceFill($attributes);

                if ($item['requested_at']) {
                    $leaveRequest->created_at = CarbonImmutable::parse($item['requested_at']);
                    $leaveRequest->updated_at = CarbonImmutable::parse($item['requested_at']);
                }

                $leaveRequest->save();

                DB::table('timetastic_imports')->insert([
                    'booking_id' => $bookingId,
                    'leave_request_id' => $leaveRequest->id,
                    'report_years' => json_encode($item['report_years'] ?? []),
                    'source_payload' => json_encode($item),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($leaveType->isHoliday()) {
                    foreach (($item['deducted_by_year'] ?? []) as $year => $days) {
                        $minutes = (int) round(((float) $days) * AllowanceService::MINUTES_PER_DAY);

                        if ($minutes <= 0) {
                            continue;
                        }

                        $yearStart = CarbonImmutable::create(
                            (int) $year,
                            1,
                            1,
                            0,
                            0,
                            0,
                            $organisation->timezone ?: 'Europe/London',
                        )->startOfDay();

                        $yearInfo = $allowanceService->leaveYearFor(
                            $organisation,
                            $yearStart,
                        );

                        AllowanceLedgerEntry::query()->firstOrCreate(
                            [
                                'user_id' => $user->id,
                                'leave_year_start' => $yearInfo['start']->toDateString(),
                                'source_key' => sprintf(
                                    'timetastic-%s-%s',
                                    $bookingId,
                                    $yearInfo['start']->toDateString(),
                                ),
                            ],
                            [
                                'leave_year_end' => $yearInfo['end']->toDateString(),
                                'entry_type' => AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION,
                                'minutes' => -1 * $minutes,
                                'effective_date' => $item['starts_on'],
                                'reference_type' => LeaveRequest::class,
                                'reference_id' => $leaveRequest->id,
                                'note' => 'Timetastic Holiday import',
                                'metadata' => [
                                    'timetastic_booking_id' => $bookingId,
                                    'status_at_import' => $leaveRequest->status,
                                    'source' => 'Timetastic annual reports',
                                ],
                            ],
                        );
                    }
                }
            }

            foreach (($payload['allowance_snapshots'] ?? []) as $snapshot) {
                $user = $users->get($this->normalise($snapshot['user_name']));

                DB::table('timetastic_allowance_snapshots')->updateOrInsert(
                    [
                        'organisation_id' => $organisation->id,
                        'timetastic_user_id' => $snapshot['timetastic_user_id'],
                        'year' => (int) $snapshot['year'],
                    ],
                    [
                        'user_id' => $user?->id,
                        'user_name' => $snapshot['user_name'],
                        'email' => $snapshot['email'] ?: null,
                        'allowance_days' => $snapshot['allowance'],
                        'brought_forward_days' => $snapshot['brought_forward'],
                        'time_in_lieu_days' => $snapshot['time_in_lieu'],
                        'taken_days' => $snapshot['taken'],
                        'carried_into_next_year_days' => $snapshot['carried_into_next_year'],
                        'remaining_days' => $snapshot['remaining'],
                        'holiday_days' => $snapshot['holiday'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        });

        $this->newLine();
        $this->info('Timetastic history import complete.');
        $this->line('Run the allowance comparison with:');
        $this->line('  php artisan holidays:import-timetastic --audit');

        return self::SUCCESS;
    }

    private function reconcileAllowances(
        Organisation $organisation,
        array $snapshots,
        AllowanceService $allowanceService,
        array $years,
        bool $commit,
    ): int {
        /*
         * Timetastic is the migration authority up to and including 2026.
         * 2027 is deliberately left to Platform's new entitlement rules
         * (service increments, new starter rules and future carry-over).
         */
        $maximumHistoricalYear = 2026;

        $snapshots = collect($snapshots)
            ->filter(fn ($snapshot) =>
                (int) $snapshot['year'] <= $maximumHistoricalYear
            )
            ->when(
                count($years) > 0,
                fn ($items) => $items->whereIn('year', $years)
            )
            ->sortBy([
                ['year', 'asc'],
                ['user_name', 'asc'],
            ])
            ->values();

        $users = User::query()
            ->where('organisation_id', $organisation->id)
            ->get()
            ->keyBy(fn (User $user) => $this->normalise($user->name));

        $rows = [];
        $changes = 0;
        $missingUsers = 0;

        $runner = function () use (
            $snapshots,
            $users,
            $organisation,
            $allowanceService,
            &$rows,
            &$changes,
            &$missingUsers,
            $commit,
        ) {
            foreach ($snapshots as $snapshot) {
                /** @var User|null $user */
                $user = $users->get($this->normalise($snapshot['user_name']));
                $year = (int) $snapshot['year'];

                if (! $user) {
                    $rows[] = [
                        $year,
                        $snapshot['user_name'],
                        'MISSING USER',
                        '',
                        '',
                        '',
                    ];
                    $missingUsers++;
                    continue;
                }

                $anchor = CarbonImmutable::create(
                    $year,
                    7,
                    1,
                    12,
                    0,
                    0,
                    $organisation->timezone ?: 'Europe/London',
                );

                $yearInfo = $allowanceService->leaveYearFor(
                    $organisation,
                    $anchor,
                );

                /*
                 * Re-run ensureYear before each person/year so carry-over from
                 * the previous reconciled year is reflected immediately.
                 */
                $summary = $allowanceService->ensureYear($user, $anchor);

                $sourceKey = sprintf(
                    'timetastic-allowance-reconciliation-%d',
                    $year,
                );

                $existing = AllowanceLedgerEntry::query()
                    ->where('user_id', $user->id)
                    ->whereDate(
                        'leave_year_start',
                        $yearInfo['start']->toDateString(),
                    )
                    ->where('source_key', $sourceKey)
                    ->first();

                $existingMinutes = (int) ($existing?->minutes ?? 0);

                $currentRemainingMinutes =
                    (int) $summary['remaining_minutes'];

                /*
                 * Remove any previous reconciliation from the current total,
                 * then calculate the exact idempotent adjustment needed to
                 * reach Timetastic's recorded remaining balance.
                 */
                $remainingWithoutReconciliation =
                    $currentRemainingMinutes - $existingMinutes;

                $targetRemainingMinutes = (int) round(
                    ((float) $snapshot['remaining'])
                    * AllowanceService::MINUTES_PER_DAY
                );

                $requiredAdjustmentMinutes =
                    $targetRemainingMinutes - $remainingWithoutReconciliation;

                $currentDays = round(
                    $currentRemainingMinutes
                    / AllowanceService::MINUTES_PER_DAY,
                    2,
                );

                $targetDays = round(
                    $targetRemainingMinutes
                    / AllowanceService::MINUTES_PER_DAY,
                    2,
                );

                $adjustmentDays = round(
                    $requiredAdjustmentMinutes
                    / AllowanceService::MINUTES_PER_DAY,
                    2,
                );

                $status = abs($currentDays - $targetDays) < 0.01
                    ? 'MATCH'
                    : 'ADJUST';

                if ($status === 'ADJUST') {
                    $changes++;
                }

                $rows[] = [
                    $year,
                    $snapshot['user_name'],
                    $status,
                    number_format($currentDays, 2),
                    number_format($targetDays, 2),
                    ($adjustmentDays >= 0 ? '+' : '')
                        . number_format($adjustmentDays, 2),
                ];

                if (! $commit) {
                    continue;
                }

                if ($requiredAdjustmentMinutes === 0) {
                    if ($existing) {
                        $existing->delete();
                    }

                    continue;
                }

                AllowanceLedgerEntry::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'leave_year_start' =>
                            $yearInfo['start']->toDateString(),
                        'source_key' => $sourceKey,
                    ],
                    [
                        'leave_year_end' =>
                            $yearInfo['end']->toDateString(),
                        'entry_type' =>
                            AllowanceLedgerEntry::TYPE_MANUAL_ADJUSTMENT,
                        'minutes' => $requiredAdjustmentMinutes,
                        'effective_date' =>
                            $yearInfo['end']->toDateString(),
                        'reference_type' => null,
                        'reference_id' => null,
                        'note' =>
                            'Timetastic migration allowance reconciliation',
                        'metadata' => [
                            'source' => 'Timetastic annual report',
                            'timetastic_year' => $year,
                            'timetastic_allowance_days' =>
                                (float) $snapshot['allowance'],
                            'timetastic_brought_forward_days' =>
                                (float) $snapshot['brought_forward'],
                            'timetastic_time_in_lieu_days' =>
                                (float) $snapshot['time_in_lieu'],
                            'timetastic_taken_days' =>
                                (float) $snapshot['taken'],
                            'timetastic_remaining_days' =>
                                (float) $snapshot['remaining'],
                            'migration_only' => true,
                        ],
                    ],
                );

                /*
                 * Refresh now so the next year's carry-over calculation sees
                 * the corrected balance.
                 */
                $allowanceService->ensureYear($user, $anchor);
            }
        };

        if ($commit) {
            DB::transaction($runner);
        } else {
            $runner();
        }

        $this->newLine();
        $this->info(
            $commit
                ? 'Timetastic historical allowance reconciliation'
                : 'Timetastic historical allowance reconciliation preview'
        );

        $this->table(
            [
                'Year',
                'Person',
                'Status',
                'Current remaining',
                'TT remaining',
                'Adjustment',
            ],
            $rows,
        );

        $this->newLine();

        if ($missingUsers > 0) {
            $this->error(
                "{$missingUsers} snapshots could not be matched to a Platform user."
            );

            return self::FAILURE;
        }

        if (! $commit) {
            $this->comment(
                'PREVIEW ONLY - no allowance adjustments were written.'
            );
            $this->line(
                "{$changes} historical person/year balances would be adjusted."
            );
            $this->line(
                '2027 is intentionally excluded so Platform future entitlement rules remain authoritative.'
            );
            $this->line(
                'When this looks right, rerun with: '
                . 'php artisan holidays:import-timetastic '
                . '--reconcile-allowances --commit'
            );

            return self::SUCCESS;
        }

        $this->info(
            "{$changes} historical person/year balances reconciled to Timetastic."
        );
        $this->line(
            '2027 was left untouched for Platform future entitlement rules.'
        );
        $this->line(
            'Now run: php artisan holidays:import-timetastic --audit --year=2022 --year=2023 --year=2024 --year=2025 --year=2026'
        );

        return self::SUCCESS;
    }

    private function audit(
        Organisation $organisation,
        array $snapshots,
        AllowanceService $allowanceService,
        array $years,
    ): int {
        $snapshots = collect($snapshots)
            ->when(
                count($years) > 0,
                fn ($items) => $items->whereIn('year', $years)
            )
            ->values();

        $users = User::query()
            ->where('organisation_id', $organisation->id)
            ->get()
            ->keyBy(fn (User $user) => $this->normalise($user->name));

        $rows = [];
        $mismatches = 0;

        foreach ($snapshots as $snapshot) {
            /** @var User|null $user */
            $user = $users->get($this->normalise($snapshot['user_name']));

            if (! $user) {
                $rows[] = [
                    $snapshot['year'],
                    $snapshot['user_name'],
                    'MISSING USER',
                    '',
                    '',
                    '',
                    '',
                ];
                $mismatches++;
                continue;
            }

            $summary = $allowanceService->ensureYear(
                $user,
                CarbonImmutable::create(
                    (int) $snapshot['year'],
                    7,
                    1,
                    12,
                    0,
                    0,
                    $organisation->timezone ?: 'Europe/London',
                ),
            );

            $ttAvailable =
                (float) $snapshot['allowance']
                + (float) $snapshot['brought_forward']
                + (float) $snapshot['time_in_lieu'];

            $platformAvailable =
                (float) $summary['granted_days']
                + (float) $summary['manual_adjustment_minutes']
                    / AllowanceService::MINUTES_PER_DAY;

            $ttTaken = (float) $snapshot['taken'];
            $platformTaken = (float) $summary['used_days'];

            $ttRemaining = (float) $snapshot['remaining'];
            $platformRemaining = (float) $summary['remaining_days'];

            $remainingDelta = round($platformRemaining - $ttRemaining, 2);
            $availableDelta = round($platformAvailable - $ttAvailable, 2);
            $takenDelta = round($platformTaken - $ttTaken, 2);

            $ok =
                abs($remainingDelta) < 0.01
                && abs($availableDelta) < 0.01
                && abs($takenDelta) < 0.01;

            if (! $ok) {
                $mismatches++;
            }

            $rows[] = [
                $snapshot['year'],
                $snapshot['user_name'],
                $ok ? 'OK' : 'CHECK',
                number_format($ttAvailable, 2),
                number_format($platformAvailable, 2),
                number_format($ttTaken, 2) . ' / ' . number_format($platformTaken, 2),
                number_format($ttRemaining, 2) . ' / ' . number_format($platformRemaining, 2),
            ];
        }

        $this->newLine();
        $this->info('Timetastic allowance audit');
        $this->table(
            [
                'Year',
                'Person',
                'Status',
                'TT available',
                'Platform available',
                'Taken TT / Platform',
                'Remaining TT / Platform',
            ],
            $rows,
        );

        $this->newLine();

        if ($mismatches === 0) {
            $this->info('All checked Timetastic allowance snapshots match Platform.');
        } else {
            $this->warn("{$mismatches} person/year allowance snapshots need checking.");
            $this->line('This audit does not alter balances. It only identifies differences.');
        }

        return self::SUCCESS;
    }

    private function createMissingType(int $organisationId, string $label): LeaveType
    {
        $normalised = $this->normalise($label);
        $inactive = in_array($normalised, $this->historicalInactiveTypes, true);

        $presets = [
            'festive break' => ['#EF5B3F', 'snowflake'],
            'working from home' => ['#77716B', 'house-laptop'],
            'day in the office' => ['#77716B', 'building'],
            'car valet' => ['#77716B', 'car'],
            'birthday' => ['#EF5B3F', 'cake-candles'],
            'team day' => ['#EF5B3F', 'users'],
            'training' => ['#EF5B3F', 'graduation-cap'],
            'client meetings' => ['#EF5B3F', 'handshake'],
            'in manchester' => ['#EF5B3F', 'location-dot'],
            'in cheshire' => ['#EF5B3F', 'location-dot'],
            'sick' => ['#EF5B3F', 'briefcase-medical'],
            'leave' => ['#EF5B3F', 'calendar-day'],
            'maternity or paternity' => ['#EF5B3F', 'baby'],
        ];

        [$colour, $icon] = $presets[$normalised] ?? ['#77716B', 'calendar-day'];

        $key = $normalised === 'festive break'
            ? 'festive-break'
            : Str::slug($label);

        $type = new LeaveType();
        $type->forceFill([
            'organisation_id' => $organisationId,
            'label' => $label,
            'key' => $key,
            'colour' => $colour,
            'icon' => $icon,
            'is_system' => false,
            'is_protected_holiday' => false,
            'visibility' => 'public',
            'requires_approval' => false,
            'include_in_staffing_limits' => false,
            'annual_usage_limit_minutes' => null,
            'external_availability' => 'available',
            'is_active' => ! $inactive,
            'display_order' => 500,
        ]);
        $type->save();

        return $type;
    }

    private function normalise(?string $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->squish()
            ->toString();
    }
}
