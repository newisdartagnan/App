<?php

namespace App\Providers;

use App\Sms\EnvoyeurSms;
use App\Sms\EnvoyeurSmsJournal;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EnvoyeurSms::class, function () {
            return match (config('doc-and-i.sms.driver')) {
                'log' => new EnvoyeurSmsJournal,
                default => throw new InvalidArgumentException(
                    'Pilote SMS inconnu : '.config('doc-and-i.sms.driver').' (voir SMS_DRIVER).'
                ),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
