<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $event,
        public ?User $actor = null,
    ) {
    }

    public function build(): self
    {
        $person = $this->leaveRequest->user;
        $leaveType = $this->leaveRequest->leaveType?->label ?? 'time off';

        $subject = match ($this->event) {
            'submitted' => sprintf(
                'Leave request to review — %s',
                $person->name,
            ),
            'approved' => sprintf(
                'Your %s request has been approved',
                strtolower($leaveType),
            ),
            'rejected' => sprintf(
                'Your %s request needs another look',
                strtolower($leaveType),
            ),
            default => 'Platform Holidays update',
        };

        return $this
            ->subject($subject)
            ->view('mail.leave-request');
    }
}
