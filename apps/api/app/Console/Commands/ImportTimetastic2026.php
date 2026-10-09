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
use Illuminate\Support\Str;

class ImportTimetastic2026 extends Command
{
    protected $signature = 'holidays:import-timetastic-2026
        {--commit : Actually write the import. Without this flag the command is preview-only}
        {--organisation=platform : Organisation slug to import into}';

    protected $description = 'Preview or import approved 2026 Timetastic bookings bundled with Platform Holidays';

    public function handle(AllowanceService $allowanceService): int
    {
        $path = storage_path('app/imports/timetastic-2026-approved.json');

        if (! is_file($path)) {
            $this->error('Import data file not found: ' . $path);
            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows)) {
            $this->error('Import data file is invalid JSON.');
            return self::FAILURE;
        }

        $organisation = Organisation::query()
            ->where('slug', (string) $this->option('organisation'))
            ->first();

        if (! $organisation) {
            $this->error('Organisation not found: ' . $this->option('organisation'));
            return self::FAILURE;
        }

        $users = User::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_archived', false)
            ->get()
            ->keyBy(fn (User $user) => $this->normalise($user->name));

        $types = LeaveType::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_active', true)
            ->get();

        $typeAliases = [
            'maternity or paternity' => ['maternity or paternity', 'maternity / paternity'],
            'in cheshire' => ['in cheshire', 'in cheshire / pet'],
        ];

        $resolvedTypes = [];
        foreach ($types as $type) {
            $resolvedTypes[$this->normalise($type->label)] = $type;
        }

        $missingUsers = [];
        $missingTypes = [];
        $preview = [];
        $duplicateCount = 0;

        foreach ($rows as $row) {
            $userKey = $this->normalise((string) ($row['user_name'] ?? ''));
            $user = $users->get($userKey);

            if (! $user) {
                $missingUsers[$row['user_name']] = true;
                continue;
            }

            $typeKey = $this->normalise((string) ($row['leave_type'] ?? ''));
            $candidates = $typeAliases[$typeKey] ?? [$typeKey];

            $leaveType = null;
            foreach ($candidates as $candidate) {
                $candidateKey = $this->normalise($candidate);
                if (isset($resolvedTypes[$candidateKey])) {
                    $leaveType = $resolvedTypes[$candidateKey];
                    break;
                }
            }

            if (! $leaveType) {
                $missingTypes[$row['leave_type']] = true;
                continue;
            }

            $alreadyExists = LeaveRequest::query()
                ->where('external_source', 'timetastic')
                ->where('external_id', (string) $row['booking_id'])
                ->exists();

            if ($alreadyExists) {
                $duplicateCount++;
                continue;
            }

            $preview[] = [
                'row' => $row,
                'user' => $user,
                'leave_type' => $leaveType,
            ];
        }

        $this->newLine();
        $this->info('Timetastic 2026 import preview');
        $this->line('Approved rows in bundled file: ' . count($rows));
        $this->line('Ready to import: ' . count($preview));
        $this->line('Already imported/skipped as duplicates: ' . $duplicateCount);
        $this->line('Cancelled rows omitted from bundled file: 45');

        if ($missingUsers) {
            $this->newLine();
            $this->warn('Missing users:');
            foreach (array_keys($missingUsers) as $name) {
                $this->line('  - ' . $name);
            }
        }

        if ($missingTypes) {
            $this->newLine();
            $this->warn('Missing leave types:');
            foreach (array_keys($missingTypes) as $label) {
                $this->line('  - ' . $label);
            }
        }

        $byType = [];
        foreach ($preview as $item) {
            $label = $item['leave_type']->label;
            $byType[$label] = ($byType[$label] ?? 0) + 1;
        }

        if ($byType) {
            ksort($byType);
            $this->newLine();
            $this->info('Ready by leave type:');
            foreach ($byType as $label => $count) {
                $this->line(sprintf('  %-28s %d', $label, $count));
            }
        }

        if ($missingUsers || $missingTypes) {
            $this->newLine();
            $this->error('Import stopped. Add/fix the missing users and leave types, then run the preview again.');
            return self::FAILURE;
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->comment('PREVIEW ONLY — nothing was written.');
            $this->comment('When this looks right, rerun with: php artisan holidays:import-timetastic-2026 --commit');
            return self::SUCCESS;
        }

        if (! count($preview)) {
            $this->info('Nothing new to import.');
            return self::SUCCESS;
        }

        $imported = 0;
        $holidayDeductions = 0;

        DB::transaction(function () use (
            $preview,
            $organisation,
            $allowanceService,
            &$imported,
            &$holidayDeductions,
        ) {
            foreach ($preview as $item) {
                /** @var User $user */
                $user = $item['user'];

                /** @var LeaveType $leaveType */
                $leaveType = $item['leave_type'];

                $row = $item['row'];
                $startsOn = CarbonImmutable::parse($row['starts_on']);

                $attributes = [
                    'user_id' => $user->id,
                    'leave_type_id' => $leaveType->id,
                    'starts_on' => $row['starts_on'],
                    'start_session' => $row['start_session'],
                    'ends_on' => $row['ends_on'],
                    'end_session' => $row['end_session'],
                    'duration_minutes' => max(0, (int) $row['duration_minutes']),
                    'status' => LeaveRequest::STATUS_APPROVED,
                    'reason' => $row['reason'] ?: null,
                    'reviewed_at' => $row['booked_at']
                        ? CarbonImmutable::parse($row['booked_at'])
                        : $startsOn,
                    'external_source' => 'timetastic',
                    'external_id' => (string) $row['booking_id'],
                ];

                if (\Illuminate\Support\Facades\Schema::hasColumn('leave_requests', 'is_manual')) {
                    $attributes['is_manual'] = true;
                }

                $leaveRequest = new LeaveRequest();
                $leaveRequest->forceFill($attributes);
                $leaveRequest->save();

                if ($row['booked_at']) {
                    $bookedAt = CarbonImmutable::parse($row['booked_at']);
                    $leaveRequest->forceFill([
                        'created_at' => $bookedAt,
                        'updated_at' => $bookedAt,
                    ])->saveQuietly();
                }

                if ($leaveType->isHoliday() && (int) $row['deducted_minutes'] > 0) {
                    $allowanceService->ensureYear($user, $startsOn);
                    $year = $allowanceService->leaveYearFor($organisation, $startsOn);

                    AllowanceLedgerEntry::query()->updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'leave_year_start' => $year['start']->toDateString(),
                            'source_key' => 'timetastic-booking-' . $row['booking_id'],
                        ],
                        [
                            'leave_year_end' => $year['end']->toDateString(),
                            'entry_type' => AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION,
                            'minutes' => -1 * abs((int) $row['deducted_minutes']),
                            'effective_date' => $row['starts_on'],
                            'reference_type' => LeaveRequest::class,
                            'reference_id' => $leaveRequest->id,
                            'note' => 'Imported Timetastic holiday',
                            'metadata' => [
                                'external_source' => 'timetastic',
                                'external_booking_id' => (string) $row['booking_id'],
                                'booking_unit' => $row['booking_unit'] ?? null,
                                'booking_total' => $row['booking_total'] ?? null,
                                'total_working' => $row['total_working'] ?? null,
                            ],
                        ],
                    );

                    $holidayDeductions++;
                }

                $imported++;
            }
        });

        $this->newLine();
        $this->info('Import complete.');
        $this->line('Leave records imported: ' . $imported);
        $this->line('Holiday allowance deductions created: ' . $holidayDeductions);

        return self::SUCCESS;
    }

    private function normalise(string $value): string
    {
        return (string) Str::of($value)
            ->lower()
            ->replace(['&'], ['and'])
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }
}
