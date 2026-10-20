<?php

namespace App\Observers;

use App\Models\LeaveRequest;
use App\Services\ClickUpBookingSyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class LeaveRequestObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(LeaveRequest $leaveRequest): void
    {
        app(ClickUpBookingSyncService::class)->sync($leaveRequest);
    }
}
