<?php

namespace App\Providers;

use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use App\Services\OperatorReader;
use App\Services\StationReader;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register FileSystemReader as singleton
        $this->app->singleton(FileSystemReader::class, function ($app) {
            return new FileSystemReader(
                Config::get('bramon.base_dir'),
                Config::get('bramon.cache_ttl')
            );
        });

        // Register OperatorReader as singleton
        $this->app->singleton(OperatorReader::class, function ($app) {
            return new OperatorReader(Config::get('bramon.operators_file'));
        });

        // Register StationReader as singleton
        $this->app->singleton(StationReader::class, function ($app) {
            return new StationReader(Config::get('bramon.stations_file'));
        });

        // Register CaptureIndex as singleton.
        // Listing endpoints read this prebuilt index so they never have to
        // walk the captures tree, which keeps request memory flat.
        $this->app->singleton(CaptureIndex::class, function ($app) {
            return new CaptureIndex(
                Config::get('bramon.index_dir'),
                (bool) Config::get('bramon.index_auto_build', false)
            );
        });

        // Register DataProcessor as singleton
        $this->app->singleton(DataProcessor::class, function ($app) {
            return new DataProcessor(
                $app->make(FileSystemReader::class),
                $app->make(OperatorReader::class),
                $app->make(StationReader::class),
                // Lets a station without an operators.json entry be described by
                // the operator its captures actually recorded.
                $app->make(CaptureIndex::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}