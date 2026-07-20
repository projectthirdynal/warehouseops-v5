<?php

namespace App\Providers;

use App\Domain\Analytics\Services\SalesDashboardService;
use App\Domain\Courier\Services\CourierServiceManager;
use App\Domain\Courier\Services\StatusMapper;
use App\Domain\Shop\CourierCsv\CourierCsvAddressValidator;
use App\Domain\Shop\CourierCsv\CourierCsvCodValidator;
use App\Domain\Shop\CourierCsv\CourierCsvCorrectionSuggester;
use App\Domain\Shop\CourierCsv\CourierCsvEncodingChecker;
use App\Domain\Shop\CourierCsv\CourierCsvPhoneValidator;
use App\Domain\Shop\CourierCsv\CourierCsvSchemaRegistry;
use App\Domain\Shop\CourierCsv\CourierCsvTemplateBuilder;
use App\Domain\Shop\CourierCsv\CourierCsvTestMode;
use App\Domain\Shop\CourierCsv\CourierCsvUploadVerifier;
use App\Domain\Shop\CourierCsv\CourierCsvValidationAnalytics;
use App\Domain\Shop\CourierCsv\CourierCsvValidationConfig;
use App\Domain\Shop\CourierCsv\CourierCsvValidator;
use App\Domain\Shop\CourierCsv\CourierCsvWeightDimensionValidator;
use App\Domain\Order\Models\Order;
use App\Domain\Waybill\Models\Waybill;
use App\Models\SiteSetting;
use App\Observers\OrderObserver;
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
        $this->app->singleton(SalesDashboardService::class);
        $this->app->singleton(CourierServiceManager::class);
        $this->app->singleton(StatusMapper::class);
        $this->app->singleton(CourierCsvSchemaRegistry::class);
        $this->app->singleton(CourierCsvValidationConfig::class);
        $this->app->singleton(CourierCsvValidationAnalytics::class);
        $this->app->singleton(CourierCsvCorrectionSuggester::class);
        $this->app->singleton(CourierCsvEncodingChecker::class);
        $this->app->singleton(CourierCsvTemplateBuilder::class);
        $this->app->singleton(CourierCsvTestMode::class);
        $this->app->singleton(CourierCsvUploadVerifier::class);
        $this->app->singleton(CourierCsvPhoneValidator::class);
        $this->app->singleton(CourierCsvCodValidator::class);
        $this->app->singleton(CourierCsvAddressValidator::class);
        $this->app->singleton(CourierCsvWeightDimensionValidator::class);
        $this->app->singleton(CourierCsvValidator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Waybill::observe(WaybillObserver::class);
        Order::observe(OrderObserver::class);
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
