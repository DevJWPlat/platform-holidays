<?php

namespace App\Services;

use App\Models\LeaveType;
use App\Models\OrganisationSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class CompanyClosureService
{
    public function closures(int $organisationId): array
    {
        $setting = OrganisationSetting::query()
            ->where('organisation_id', $organisationId)
            ->where('key', 'company_closures')
            ->first();

        $value = is_array($setting?->value)
            ? $setting->value
            : [];

        return collect($value)
            ->filter(fn ($item) =>
                is_array($item)
                && isset(
                    $item['id'],
                    $item['starts_on'],
                    $item['ends_on'],
                )
            )
            ->sortBy('starts_on')
            ->values()
            ->all();
    }

    public function add(
        int $organisationId,
        string $startsOn,
        string $endsOn,
    ): array {
        $items = $this->closures($organisationId);

        $item = [
            'id' => (string) Str::uuid(),
            'title' => 'Festive Break',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ];

        $items[] = $item;

        $this->save($organisationId, $items);

        return $item;
    }

    public function remove(
        int $organisationId,
        string $id,
    ): void {
        $items = collect($this->closures($organisationId))
            ->reject(fn ($item) => (string) $item['id'] === $id)
            ->values()
            ->all();

        $this->save($organisationId, $items);
    }

    public function overlaps(
        User $user,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
    ): ?array {
        return collect(
            $this->closures((int) $user->organisation_id),
        )->first(function ($item) use ($startsOn, $endsOn) {
            $closureStart = CarbonImmutable::parse($item['starts_on']);
            $closureEnd = CarbonImmutable::parse($item['ends_on']);

            return $closureStart->lessThanOrEqualTo($endsOn)
                && $closureEnd->greaterThanOrEqualTo($startsOn);
        });
    }

    public function virtualRequestsFor(
        User $user,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        $leaveType = $this->festiveBreakType(
            (int) $user->organisation_id,
        );

        return collect(
            $this->closures((int) $user->organisation_id),
        )
            ->filter(function ($item) use ($from, $to) {
                $start = CarbonImmutable::parse($item['starts_on']);
                $end = CarbonImmutable::parse($item['ends_on']);

                if ($from && $end->lessThan($from)) {
                    return false;
                }

                if ($to && $start->greaterThan($to)) {
                    return false;
                }

                return true;
            })
            ->map(fn ($item) => $this->virtualRequest(
                $user,
                $item,
                $leaveType,
            ))
            ->values()
            ->all();
    }

    public function wallchartItems(
        int $organisationId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $people = User::query()
            ->where('organisation_id', $organisationId)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();

        return $people
            ->flatMap(fn (User $person) =>
                $this->virtualRequestsFor(
                    $person,
                    $from,
                    $to,
                )
            )
            ->values()
            ->all();
    }

    private function save(
        int $organisationId,
        array $items,
    ): void {
        OrganisationSetting::query()->updateOrCreate(
            [
                'organisation_id' => $organisationId,
                'key' => 'company_closures',
            ],
            [
                'value' => collect($items)
                    ->sortBy('starts_on')
                    ->values()
                    ->all(),
            ],
        );
    }

    private function festiveBreakType(
        int $organisationId,
    ): ?LeaveType {
        return LeaveType::query()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('key', 'festive-break')
                    ->orWhere('key', 'festive_break')
                    ->orWhereRaw(
                        'LOWER(label) = ?',
                        ['festive break'],
                    );
            })
            ->first();
    }

    private function virtualRequest(
        User $user,
        array $item,
        ?LeaveType $leaveType,
    ): array {
        $start = CarbonImmutable::parse($item['starts_on']);
        $end = CarbonImmutable::parse($item['ends_on']);

        $workingDays = 0;
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            if (! $cursor->isWeekend()) {
                $workingDays++;
            }

            $cursor = $cursor->addDay();
        }

        return [
            'id' =>
                'company-closure-'
                . $item['id']
                . '-'
                . $user->id,
            'is_company_closure' => true,
            'company_closure_id' => $item['id'],
            'status' => 'approved',
            'starts_on' => $item['starts_on'],
            'start_session' => 'morning',
            'ends_on' => $item['ends_on'],
            'end_session' => 'end_of_day',
            'duration_minutes' => $workingDays * 420,
            'duration_days' => $workingDays,
            'reason' => 'Company closure',
            'review_note' => null,
            'reviewed_at' => null,
            'cancelled_at' => null,
            'created_at' => null,
            'can_cancel' => false,
            'user_id' => $user->id,
            'leave_type' => [
                'id' => $leaveType?->id,
                'label' => 'Festive Break',
                'key' => $leaveType?->key ?: 'festive-break',
                'colour' =>
                    $leaveType?->colour ?: '#ef5b3f',
                'icon' =>
                    $leaveType?->icon ?: 'snowflake',
                'icon_colour' =>
                    $leaveType?->icon_colour ?: 'white',
                'is_protected_holiday' => false,
                'requires_approval' => false,
            ],
        ];
    }
}
