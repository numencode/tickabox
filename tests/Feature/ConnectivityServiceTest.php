<?php

use App\Services\ConnectivityService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// Device and Network facades are unavailable in the test environment, so
// ConnectivityService always falls back to the HTTP reachability check.

it('reports online when the API /up endpoint returns 200', function () {
    Http::fake(['*/up' => Http::response('', 200)]);

    expect(app(ConnectivityService::class)->isOnline())->toBeTrue();
});

it('reports offline when the API /up endpoint returns a non-2xx status', function () {
    Http::fake(['*/up' => Http::response('', 503)]);

    expect(app(ConnectivityService::class)->isOnline())->toBeFalse();
});

it('reports offline when the HTTP call throws a connection error', function () {
    Http::fake(['*/up' => fn () => throw new ConnectionException('refused')]);

    expect(app(ConnectivityService::class)->isOnline())->toBeFalse();
});

it('status returns the connected flag', function () {
    Http::fake(['*/up' => Http::response('', 200)]);

    $status = app(ConnectivityService::class)->status();

    expect($status)->toHaveKey('connected', true);
    expect($status)->toHaveKey('type');
});

it('isVirtualDevice returns false when Device facade is unavailable', function () {
    // In test env the NativePHP Device facade throws — code catches and returns false.
    expect(app(ConnectivityService::class)->isVirtualDevice())->toBeFalse();
});
