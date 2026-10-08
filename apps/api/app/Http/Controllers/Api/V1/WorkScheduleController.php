<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkScheduleResource;
use App\Models\WorkSchedule;
use Illuminate\Http\Request;

class WorkScheduleController extends Controller
{
    public function index(Request $request)
    {
        $schedules = WorkSchedule::query()
            ->where('organisation_id', $request->user()->organisation_id)
            ->where('is_active', true)
            ->with('sessions')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return WorkScheduleResource::collection($schedules);
    }
}
