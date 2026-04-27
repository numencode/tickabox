<?php

use App\Providers\NativeServiceProvider;

it('does not throw in the testing environment regardless of the API URL scheme', function () {
    config(['services.tickabox_api.base_url' => 'http://insecure-api.example.com']);

    $provider = new NativeServiceProvider($this->app);
    $provider->boot(); // testing env — the HTTPS check is skipped

    expect(true)->toBeTrue(); // reached without exception
});

it('throws a RuntimeException when the API URL is HTTP in a non-local environment', function () {
    config(['services.tickabox_api.base_url' => 'http://api.example.com']);

    $this->app['env'] = 'production';

    $provider = new NativeServiceProvider($this->app);

    expect(fn () => $provider->boot())->toThrow(RuntimeException::class, 'TICKABOX_API_URL must use HTTPS');

    $this->app['env'] = 'testing'; // restore
});

it('does not throw when the API URL is HTTPS in a non-local environment', function () {
    config(['services.tickabox_api.base_url' => 'https://api.example.com']);

    $this->app['env'] = 'production';

    $provider = new NativeServiceProvider($this->app);
    $provider->boot(); // should not throw

    $this->app['env'] = 'testing';

    expect(true)->toBeTrue();
});

it('does not throw when the API URL is empty in a non-local environment', function () {
    config(['services.tickabox_api.base_url' => '']);

    $this->app['env'] = 'production';

    $provider = new NativeServiceProvider($this->app);
    $provider->boot(); // empty URL — no exception

    $this->app['env'] = 'testing';

    expect(true)->toBeTrue();
});
