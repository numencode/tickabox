<?php

use App\Models\SyncMeta;
use App\Models\SyncOperation;
use App\Models\Todo;
use App\Models\User;
use App\Services\ApiAuthService;
use Illuminate\Support\Facades\Http;

$apiResponse = fn (array $overrides = []) => array_merge([
    'token' => 'sanctum_token_abc',
    'expires_at' => now()->addDays(90)->toIso8601String(),
    'user' => ['id' => 99, 'name' => 'Test User', 'email' => 'test@example.com'],
], $overrides);

/*
|--------------------------------------------------------------------------
| Registration / Login
|--------------------------------------------------------------------------
*/

it('register persists the user and stores the token in the DB', function () use ($apiResponse) {
    Http::fake(['*/api/register' => Http::response($apiResponse(), 201)]);

    $user = app(ApiAuthService::class)->register('Test User', 'test@example.com', 'SecurePass1');

    expect($user->email)->toBe('test@example.com');
    expect($user->remote_id)->toBe(99);
    expect($user->sanctum_token)->toBe('sanctum_token_abc');
    $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'remote_id' => 99]);
});

it('login persists the user and stores the token in the DB', function () use ($apiResponse) {
    Http::fake(['*/api/login' => Http::response($apiResponse(), 200)]);

    $user = app(ApiAuthService::class)->login('test@example.com', 'password');

    expect($user->email)->toBe('test@example.com');
    expect($user->sanctum_token)->toBe('sanctum_token_abc');
});

it('login updates an existing local user record', function () use ($apiResponse) {
    User::factory()->create(['email' => 'test@example.com', 'name' => 'Old Name']);
    Http::fake(['*/api/login' => Http::response($apiResponse(), 200)]);

    app(ApiAuthService::class)->login('test@example.com', 'password');

    expect(User::where('email', 'test@example.com')->count())->toBe(1);
    expect(User::where('email', 'test@example.com')->first()->name)->toBe('Test User');
});

it('throws when the API response is missing the token', function () {
    Http::fake(['*/api/login' => Http::response(['user' => ['id' => 1, 'name' => 'X', 'email' => 'x@x.com']], 200)]);

    expect(fn () => app(ApiAuthService::class)->login('x@x.com', 'pass'))
        ->toThrow(UnexpectedValueException::class);
});

it('throws when the API response has incomplete user data', function () {
    Http::fake(['*/api/login' => Http::response(['token' => 'abc', 'user' => ['email' => 'x@x.com']], 200)]);

    expect(fn () => app(ApiAuthService::class)->login('x@x.com', 'pass'))
        ->toThrow(UnexpectedValueException::class);
});

/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
*/

it('logout clears todos, sync operations, and sync meta for the user', function () {
    Http::fake(['*/api/logout' => Http::response(['message' => 'Logged out.'], 200)]);

    $user = User::factory()->withToken('my-token')->withRemoteId(10)->create();
    Todo::factory()->forUser($user)->count(3)->create();
    SyncOperation::factory()->create(['user_id' => $user->id]);
    SyncMeta::setValue($user->id, 'last_pulled_at', '2026-01-01T00:00:00Z');

    app(ApiAuthService::class)->logout($user);

    expect(Todo::withTrashed()->where('user_id', $user->id)->count())->toBe(0);
    expect(SyncOperation::where('user_id', $user->id)->count())->toBe(0);
    expect(SyncMeta::query()->where('user_id', $user->id)->count())->toBe(0);
    expect($user->fresh()->remote_id)->toBeNull();
    expect($user->fresh()->sanctum_token)->toBeNull();
});

it('logout completes local cleanup even when the API call fails', function () {
    Http::fake(['*/api/logout' => Http::response('', 500)]);

    $user = User::factory()->withToken('my-token')->withRemoteId(10)->create();
    Todo::factory()->forUser($user)->create();

    app(ApiAuthService::class)->logout($user);

    expect(Todo::withTrashed()->where('user_id', $user->id)->count())->toBe(0);
    expect($user->fresh()->sanctum_token)->toBeNull();
});
