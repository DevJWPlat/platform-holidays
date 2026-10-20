<?php

namespace App\Services;

use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClickUpBookingSyncService
{
    public function __construct(
        private readonly ClickUpService $clickUp,
    ) {}

    public function sync(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing(['user.organisation', 'leaveType']);

        if (! $leaveRequest->user || ! $leaveRequest->leaveType) {
            return;
        }

        if (in_array($leaveRequest->status, [
            LeaveRequest::STATUS_CANCELLED,
            LeaveRequest::STATUS_REJECTED,
        ], true)) {
            $this->remove($leaveRequest);
            return;
        }

        if ($leaveRequest->status !== LeaveRequest::STATUS_APPROVED) {
            return;
        }

        // Do not back-create ClickUp tasks for old historic records.
        if (
            ! $leaveRequest->clickup_task_id
            && $leaveRequest->ends_on
            && $leaveRequest->ends_on->isBefore(today())
        ) {
            return;
        }

        try {
            $member = $this->clickUp->memberForPlatformUser(
                $leaveRequest->user->email,
                $leaveRequest->user->name,
            );

            if (! $member) {
                throw new \RuntimeException(
                    'No ClickUp account matched ' . $leaveRequest->user->email
                );
            }

            $payload = $this->payload($leaveRequest, (int) $member['id']);

            if ($leaveRequest->clickup_task_id) {
                try {
                    $this->clickUp->updateTask(
                        (string) $leaveRequest->clickup_task_id,
                        $this->updatePayload($payload),
                    );
                } catch (RequestException $exception) {
                    if ($exception->response?->status() !== 404) {
                        throw $exception;
                    }

                    $created = $this->clickUp->createTask($payload);
                    $leaveRequest->clickup_task_id = (string) ($created['id'] ?? '');
                }
            } else {
                $created = $this->clickUp->createTask($payload);
                $leaveRequest->clickup_task_id = (string) ($created['id'] ?? '');
            }

            if (! $leaveRequest->clickup_task_id) {
                throw new \RuntimeException('ClickUp did not return a task ID.');
            }

            $leaveRequest->clickup_synced_at = now();
            $leaveRequest->clickup_sync_error = null;
            $leaveRequest->saveQuietly();
        } catch (Throwable $exception) {
            Log::warning('ClickUp booking sync failed', [
                'leave_request_id' => $leaveRequest->id,
                'message' => $exception->getMessage(),
            ]);

            $leaveRequest->forceFill([
                'clickup_sync_error' => mb_substr($exception->getMessage(), 0, 4000),
            ])->saveQuietly();
        }
    }

    public function remove(LeaveRequest $leaveRequest): void
    {
        if (! $leaveRequest->clickup_task_id) {
            return;
        }

        try {
            $this->clickUp->deleteTask((string) $leaveRequest->clickup_task_id);

            $leaveRequest->forceFill([
                'clickup_task_id' => null,
                'clickup_synced_at' => now(),
                'clickup_sync_error' => null,
            ])->saveQuietly();
        } catch (Throwable $exception) {
            Log::warning('ClickUp booking delete failed', [
                'leave_request_id' => $leaveRequest->id,
                'clickup_task_id' => $leaveRequest->clickup_task_id,
                'message' => $exception->getMessage(),
            ]);

            $leaveRequest->forceFill([
                'clickup_sync_error' => mb_substr($exception->getMessage(), 0, 4000),
            ])->saveQuietly();
        }
    }

    public function previewAction(LeaveRequest $leaveRequest): string
    {
        if (in_array($leaveRequest->status, [
            LeaveRequest::STATUS_CANCELLED,
            LeaveRequest::STATUS_REJECTED,
        ], true)) {
            return $leaveRequest->clickup_task_id ? 'DELETE' : 'SKIP';
        }

        if ($leaveRequest->status !== LeaveRequest::STATUS_APPROVED) {
            return 'SKIP';
        }

        return $leaveRequest->clickup_task_id ? 'UPDATE' : 'CREATE';
    }

    private function payload(LeaveRequest $leaveRequest, int $assigneeId): array
    {
        $timezone = $leaveRequest->user->organisation?->timezone ?: 'Europe/London';

        $start = CarbonImmutable::parse(
            $leaveRequest->starts_on->toDateString(),
            $timezone,
        )->setTime(12, 0);

        $end = CarbonImmutable::parse(
            $leaveRequest->ends_on->toDateString(),
            $timezone,
        )->setTime(12, 0);

        return [
            'name' => $this->taskName($leaveRequest),
            'description' => $this->description($leaveRequest),
            'assignees' => [$assigneeId],
            'start_date' => $start->getTimestampMs(),
            'start_date_time' => false,
            'due_date' => $end->getTimestampMs(),
            'due_date_time' => false,
            'notify_all' => false,
        ];
    }

    private function updatePayload(array $payload): array
    {
        // ClickUp's update endpoint handles assignee changes with a different
        // object shape, and the booking owner cannot change in Platform anyway.
        unset($payload['assignees'], $payload['notify_all']);

        return $payload;
    }

    private function taskName(LeaveRequest $leaveRequest): string
    {
        $label = $leaveRequest->leaveType->label ?: 'Time off';
        $start = CarbonImmutable::parse($leaveRequest->starts_on);
        $end = CarbonImmutable::parse($leaveRequest->ends_on);

        if ($start->isSameDay($end)) {
            return sprintf('%s: %s', $label, $start->format('j M Y'));
        }

        if ($start->year === $end->year) {
            return sprintf(
                '%s: %s - %s',
                $label,
                $start->format('j M'),
                $end->format('j M Y'),
            );
        }

        return sprintf(
            '%s: %s - %s',
            $label,
            $start->format('j M Y'),
            $end->format('j M Y'),
        );
    }

    private function description(LeaveRequest $leaveRequest): string
    {
        $lines = [
            'Synced automatically from Platform Holidays.',
            '',
            'Person: ' . $leaveRequest->user->name,
            'Type: ' . $leaveRequest->leaveType->label,
            'Platform booking ID: ' . $leaveRequest->id,
        ];

        if (trim((string) $leaveRequest->reason) !== '') {
            $lines[] = 'Reason: ' . trim((string) $leaveRequest->reason);
        }

        return implode("\n", $lines);
    }
}
