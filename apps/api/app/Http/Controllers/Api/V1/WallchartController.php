<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\CompanyClosureService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class WallchartController extends Controller
{
    public function leave(
        Request $request,
        CompanyClosureService $companyClosureService,
    ) {
        $viewer = $request->user();

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => [
                'required',
                'date',
                'after_or_equal:from',
            ],
        ]);

        $items = LeaveRequest::query()
            ->whereHas(
                'user',
                fn ($query) => $query
                    ->where(
                        'organisation_id',
                        $viewer->organisation_id,
                    )
                    ->where('is_archived', false),
            )
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_APPROVED,
            ])
            ->whereDate('starts_on', '<=', $validated['to'])
            ->whereDate('ends_on', '>=', $validated['from'])
            ->with([
                'leaveType',
                'user.departments',
                'reviewer',
            ])
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $realItems = $items
            ->map(function ($item) use ($request) {
                $resource = (
                    new LeaveRequestResource($item)
                )->toArray($request);

                return array_merge(
                    $resource,
                    ['user_id' => $item->user_id],
                );
            })
            ->values();

        $closureItems = $companyClosureService
            ->wallchartItems(
                (int) $viewer->organisation_id,
                CarbonImmutable::parse($validated['from']),
                CarbonImmutable::parse($validated['to']),
            );

        return response()->json([
            'data' => $realItems
                ->concat($closureItems)
                ->values(),
        ]);
    }
}
