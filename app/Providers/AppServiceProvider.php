<?php

namespace App\Providers;

use App\Domain\Courier\Services\CourierServiceManager;
use App\Domain\Courier\Services\StatusMapper;
use App\Domain\Waybill\Models\Waybill;
use App\Models\SiteSetting;
use App\Observers\WaybillObserver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CourierServiceManager::class);
        $this->app->singleton(StatusMapper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Waybill::observe(WaybillObserver::class);
        $this->bootMailFromDatabase();
    }

    private function bootMailFromDatabase(): void
    {
        try {
            if (! Schema::hasTable('site_settings')) {
                return;
            }
            $mailer = SiteSetting::get('mail_mailer');
            if (! $mailer) {
                return;
            }
            $rawPwd   = SiteSetting::get('mail_password', '');
            $password = '';
            if ($rawPwd) {
                try { $password = Crypt::decryptString($rawPwd); } catch (\Throwable) { $password = $rawPwd; }
            }
            $enc = SiteSetting::get('mail_encryption', 'tls');
            config([
                'mail.default'                 => $mailer,
                'mail.mailers.smtp.host'       => SiteSetting::get('mail_host', ''),
                'mail.mailers.smtp.port'       => (int) SiteSetting::get('mail_port', 587),
                'mail.mailers.smtp.encryption' => $enc === 'none' ? null : $enc,
                'mail.mailers.smtp.username'   => SiteSetting::get('mail_username', ''),
                'mail.mailers.smtp.password'   => $password,
                'mail.from.address'            => SiteSetting::get('mail_from_address') ?: config('mail.from.address'),
                'mail.from.name'               => SiteSetting::get('mail_from_name') ?: config('mail.from.name'),
            ]);
        } catch (\Throwable) {
            // Never crash the app if DB is unreachable at boot
        }
    }
}
