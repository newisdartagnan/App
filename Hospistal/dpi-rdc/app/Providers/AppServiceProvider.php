<?php

namespace App\Providers;

use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Visit;
use App\Observers\AuditObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Patient::observe(AuditObserver::class);
        Visit::observe(AuditObserver::class);
        Consultation::observe(AuditObserver::class);
    }
}
