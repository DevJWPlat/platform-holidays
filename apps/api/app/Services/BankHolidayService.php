<?php

namespace App\Services;

use App\Models\OrganisationSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class BankHolidayService
{
    private const SOURCE_URL = 'https://www.gov.uk/bank-holidays.json';

    public function organisationSettings(int $organisationId): array
    {
        $setting = OrganisationSetting::query()
            ->where('organisation_id', $organisationId)
            ->where('key', 'bank_holidays')
            ->first();

        return array_merge([
            'exclude_from_leave_duration' => true,
        ], is_array($setting?->value) ? $setting->value : []);
    }

    public function divisionFor(User $user): string
    {
        return 'england-and-wales';
    }

    public function events(string $division): array
    {
        $payload = Cache::remember(
            'gov-uk-bank-holidays-v1',
            now()->addHours(12),
            function () {
                try {
                    $response = Http::acceptJson()
                        ->timeout(8)
                        ->retry(2, 250)
                        ->get(self::SOURCE_URL);

                    if ($response->successful()) {
                        return $response->json();
                    }
                } catch (Throwable) {
                    // Keep leave booking usable if GOV.UK is temporarily unavailable.
                }

                return [];
            },
        );

        $events = $payload[$division]['events'] ?? [];

        return collect($events)
            ->filter(fn ($event) => isset($event['date'], $event['title']))
            ->map(fn ($event) => [
                'title' => (string) $event['title'],
                'date' => (string) $event['date'],
                'notes' => (string) ($event['notes'] ?? ''),
                'bunting' => (bool) ($event['bunting'] ?? false),
            ])
            ->values()
            ->all();
    }

    public function datesFor(User $user): array
    {
        return collect($this->events($this->divisionFor($user)))
            ->pluck('date')
            ->all();
    }

    public function isBankHoliday(User $user, CarbonImmutable $date): bool
    {
        return in_array($date->toDateString(), $this->datesFor($user), true);
    }

    public function excludeFromDuration(User $user, array $duration): array
    {
        $settings = $this->organisationSettings((int) $user->organisation_id);

        if (! (bool) $settings['exclude_from_leave_duration']) {
            return $duration;
        }

        $bankHolidayDates = array_flip($this->datesFor($user));
        $breakdown = collect($duration['breakdown'] ?? [])
            ->filter(function ($day) use ($bankHolidayDates) {
                $date = (string) ($day['date'] ?? '');

                return ! isset($bankHolidayDates[$date]);
            })
            ->values()
            ->all();

        $minutes = array_sum(array_map(
            fn ($day) => (int) ($day['minutes'] ?? 0),
            $breakdown,
        ));

        $duration['breakdown'] = $breakdown;
        $duration['minutes'] = $minutes;
        $duration['days'] = round($minutes / AllowanceService::MINUTES_PER_DAY, 2);

        return $duration;
    }
}
