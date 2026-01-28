<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Blade;
use App\Models\Event;
use App\Helpers\PdfHelper;

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
        // Explicit model binding for Event model
        Route::model('event', Event::class);

        // Register Blade directive for formatting team names with noshow
        Blade::directive('formatTeamName', function ($expression) {
            return "<?php echo App\Helpers\PdfHelper::formatTeamNameWithNoshow($expression); ?>";
        });
    }
}
