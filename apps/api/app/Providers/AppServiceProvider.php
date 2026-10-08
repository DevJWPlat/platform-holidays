<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Observers\DepartmentObserver;
use App\Observers\LeaveRequestObserver;
use App\Observers\UserObserver;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LeaveRequest::observe(LeaveRequestObserver::class);
        Department::observe(DepartmentObserver::class);
        User::observe(UserObserver::class);
        //
    }
}
