<?php

namespace Leuchtturm;

use Illuminate\Support\ServiceProvider;

class LeuchtturmServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton("Leuchtturm", function () {
            return new LeuchtturmManager();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (app()->runningInConsole()) {
            // publish config when running in console
            $this->publishes([
                __DIR__ . '/../config/leuchtturm.php' => config_path('leuchtturm.php'),
            ], 'leuchtturm-config');
        }
    }
}