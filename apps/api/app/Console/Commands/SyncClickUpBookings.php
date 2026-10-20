<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use App\Services\ClickUpBookingSyncService;
use Illuminate\Console\Command;

class SyncClickUpBookings extends Command
{
    protected $signature = 'holidays:clickup-sync
        {--from= : Include bookings ending on/after this date; defaults to today}
        {--commit : Actually create/update ClickUp tasks}';

    protected $description = 'Preview or sync approved Platform Holidays bookings into ClickUp';

    public function handle(ClickUpBookingSyncService $sync): int
    {
        $from = $this->option('from') ?: today()->toDateString();

        $bookings = LeaveRequest::query()
            ->whereDate('ends_on', '>=', $from)
            ->whereIn('status', [
                LeaveRequest::STATUS_APPROVED,
                LeaveRequest::STATUS_CANCELLED,
                LeaveRequest::STATUS_REJECTED,
            ])
            ->with(['user', 'leaveType'])
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($bookings as $booking) {
            $rows[] = [
                $booking->id,
                $booking->user?->name ?: '—',
                $booking->leaveType?->label ?: '—',
                $booking->starts_on?->toDateString() ?: '—',
                $booking->ends_on?->toDateString() ?: '—',
                strtoupper($booking->status),
                $sync->previewAction($booking),
                $booking->clickup_task_id ?: '—',
            ];
        }

        $this->newLine();
        $this->info(
            $this->option('commit')
                ? 'ClickUp booking sync'
                : 'ClickUp booking sync preview'
        );

        $this->line('From: ' . $from);
        $this->table(
            ['ID', 'Person', 'Type', 'Starts', 'Ends', 'Status', 'Action', 'ClickUp task'],
            $rows,
        );

        if (! $this->option('commit')) {
            $this->comment('PREVIEW ONLY - ClickUp was not changed.');
            $this->line('Run with --commit when the rows above look right.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($bookings->count());
        $bar->start();

        foreach ($bookings as $booking) {
            $sync->sync($booking);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $failed = LeaveRequest::query()
            ->whereIn('id', $bookings->pluck('id'))
            ->whereNotNull('clickup_sync_error')
            ->count();

        if ($failed > 0) {
            $this->warn($failed . ' booking(s) have a ClickUp sync error. Run the preview again to inspect task IDs, then check the application log.');
            return self::FAILURE;
        }

        $this->info('ClickUp booking sync completed.');
        return self::SUCCESS;
    }
}
