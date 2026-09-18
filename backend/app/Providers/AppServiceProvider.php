<?php

namespace App\Providers;

use App\Helpers\PdfHelper;
use App\Mail\Transport\MicrosoftGraphTransport;
use App\Models\Event;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->afterResolving('mail.manager', function (MailManager $manager) {
            $manager->extend('microsoft-graph', function (array $config) {
                return new MicrosoftGraphTransport(
                    (string) ($config['tenant_id'] ?? ''),
                    (string) ($config['client_id'] ?? ''),
                    (string) ($config['client_secret'] ?? ''),
                    (bool) ($config['save_to_sent_items'] ?? false),
                );
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->runningInConsole()) {
            set_time_limit(0);
        } else {
            $maxTime = (int) config('app.max_execution_time', 30);
            if ($maxTime > 0) {
                set_time_limit($maxTime);
            }
        }

        // Explicit model binding for Event model
        Route::model('event', Event::class);

        // Register Blade directive for formatting team names with noshow
        Blade::directive('formatTeamName', function ($expression) {
            return "<?php echo App\Helpers\PdfHelper::formatTeamNameWithNoshow($expression); ?>";
        });
    }
}
