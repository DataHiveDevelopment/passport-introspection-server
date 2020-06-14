<?php

namespace DataHiveDevelopment\PassportIntrospectionServer;

use Illuminate\Support\ServiceProvider;

class IntrospectionServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            if (Introspection::$runsMigrations) {
                return $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
            }

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'introspection-server-migrations');
        }
    }
}
