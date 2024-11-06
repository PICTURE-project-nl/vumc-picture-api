<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        \Validator::extend('email_domain', function($attribute, $value, $parameters, $validator) {
            $allowedEmailDomains = ['example.com', 'example2.com'];

//            $allowedEmailDomainsPath = '/var/www/laravel/vumc-picture-api/allowed_email_domains.json';
//
//            if (file_exists($allowedEmailDomainsPath)){
//                $jsonString = file_get_contents($allowedEmailDomainsPath);
//                $allowedEmailDomains = json_decode($jsonString, true);
//            }

//            return in_array( explode('@', $parameters[0])[1] , $allowedEmailDomains);
            return true;
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
