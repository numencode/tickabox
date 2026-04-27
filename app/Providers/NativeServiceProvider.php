<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Providers\DeviceServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (! app()->environment('local', 'testing')) {
            $apiUrl = config('services.tickabox_api.base_url', '');

            if ($apiUrl && ! str_starts_with($apiUrl, 'https://')) {
                throw new \RuntimeException(
                    'TICKABOX_API_URL must use HTTPS in non-local environments. Got: '.$apiUrl
                );
            }
        }
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            DeviceServiceProvider::class,
            NetworkServiceProvider::class,
        ];
    }
}
