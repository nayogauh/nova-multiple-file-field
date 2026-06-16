<?php

namespace Nayogauh\MultipleFile;

use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;

class FieldServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        Nova::serving(function (ServingNova $event) {
            $script = __DIR__ . '/../dist/js/field.js';
            $style = __DIR__ . '/../dist/css/field.css';

            if (file_exists($script)) {
                Nova::script('multiple-file', $script);
            }

            if (file_exists($style)) {
                Nova::style('multiple-file', $style);
            }
        });
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
