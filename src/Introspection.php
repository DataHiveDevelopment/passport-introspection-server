<?php

namespace DataHiveDevelopment\PassportIntrospectionServer;

use Illuminate\Support\Facades\Route;

class Introspection
{
    /**
     * Indicates if the migrations will be run.
     *
     * @var bool
     */
    public static $runsMigrations = true;

    /**
     * Binds the introspection routes into the controller.
     *
     * @param  array  $options
     * @return void
     */
    public static function routes(array $options = [])
    {
        $defaultOptions = [
            'middleware' => 'client',
            'prefix' => 'oauth',
            'namespace' => '\DataHiveDevelopment\PassportIntrospectionServer\Http\Controllers',
        ];

        $options = array_merge($defaultOptions, $options);

        Route::group($options, function () {
            Route::post('/introspect', [
                'uses' => 'IntrospectionController@introspect',
                'as' => 'introspection.introspect',
            ]);
        });
    }

    /**
     * Configure Introspection Server to not register its migrations.
     *
     * @return static
     */
    public static function ignoreMigrations()
    {
        static::$runsMigrations = false;

        return new static;
    }
}
