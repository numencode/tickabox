<?php

use App\Exceptions\AuthExpiredException;
use App\Jobs\SyncPendingOperationsJob;
use App\Models\SyncOperation;
use App\Models\Todo;
use App\Services\ConnectivityService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function onlineCs(): ConnectivityService
{
    $cs = Mockery::mock(ConnectivityService::class);
    $cs->shouldReceive('isOnline')->andReturn(true);

    return $cs;
}

function offlineCs(): ConnectivityService
{
    $cs = Mockery::mock(ConnectivityService::class);
    $cs->shouldReceive('isOnline')->andReturn(false);

    return $cs;
}

function runJob(int $userId, ConnectivityService $cs): void
{
    (new SyncPendingOperationsJob($userId))->handle($cs);
}

/*
|--------------------------------------------------------------------------
| Guard clauses
|--------------------------------------------------------------------------
*/

it('returns early when offline', function () {
    Http::fake();
    runJob(999, offlineCs());
    Http::assertNothingSent();
});

it('returns early when the user does not exist', function () {
    Http::fake();
    runJob(999, onlineCs());
    Http::assertNothingSent();
});

it('returns early when the user has no token', function () {
    Http::fake();
    $user = makeUser(['sanctum_token' => null]);
    runJob($user->id, onlineCs());
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Successful push
|--------------------------------------------------------------------------
*/

it('pushes pending operations and marks them done', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $todo = Todo::factory()->forUser($user)->create(['sync_status' => 'pending']);
    $op = SyncOperation::create([
        'user_id' => $user->id,
        'entity_type' => 'todo',
        'entity_uuid' => $todo->uuid,
        'operation' => 'created',
        'payload' => ['uuid' => $todo->uuid, 'title' => $todo->title, 'is_completed' => false,
            'last_modified_at' => now()->toIso8601String(), 'deleted_at' => null],
        'status' => 'pending',
    ]);

    $serverTime = now()->toIso8601String();
    Http::fake([
        '*/api/sync/push' => Http::response([
            'results' => [['uuid' => $todo->uuid, 'status' => 'ok',
                'title' => $todo->title, 'is_completed' => false,
                'last_modified_at' => $serverTime, 'deleted_at' => null]],
            'server_time' => $serverTime,
        ], 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [], 'has_more' => false,
            'next_since' => null, 'next_since_id' => 0,
            'server_time' => $serverTime,
        ], 200),
    ]);

    runJob($user->id, onlineCs());

    expect($op->fresh()->status)->toBe('done');
    expect(Todo::find($todo->id)->sync_status)->toBe('synced');
});

it('marks operations failed when server does not confirm them', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $todo = Todo::factory()->forUser($user)->create();
    $op = SyncOperation::create([
        'user_id' => $user->id,
        'entity_type' => 'todo',
        'entity_uuid' => $todo->uuid,
        'operation' => 'updated',
        'payload' => ['uuid' => $todo->uuid, 'title' => $todo->title, 'is_completed' => false,
            'last_modified_at' => now()->subMinute()->toIso8601String(), 'deleted_at' => null],
        'status' => 'pending',
    ]);

    Http::fake([
        '*/api/sync/push' => Http::response([
            'results' => [], // op UUID not in results → not confirmed
            'server_time' => now()->toIso8601String(),
        ], 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [], 'has_more' => false, 'server_time' => now()->toIso8601String(),
        ], 200),
    ]);

    runJob($user->id, onlineCs());

    $fresh = $op->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->available_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| HTTP error handling
|--------------------------------------------------------------------------
*/

it('clears auth and throws AuthExpiredException on push 401', function () {
    $user = makeUser(['sanctum_token' => 'expired-tok', 'remote_id' => 5]);
    $todo = Todo::factory()->forUser($user)->create();
    SyncOperation::create([
        'user_id' => $user->id,
        'entity_type' => 'todo',
        'entity_uuid' => $todo->uuid,
        'operation' => 'created',
        'payload' => ['uuid' => $todo->uuid, 'title' => $todo->title, 'is_completed' => false,
            'last_modified_at' => now()->toIso8601String(), 'deleted_at' => null],
        'status' => 'pending',
    ]);

    Http::fake(['*/api/sync/push' => Http::response('', 401)]);

    expect(fn () => runJob($user->id, onlineCs()))->toThrow(AuthExpiredException::class);

    expect($user->fresh()->sanctum_token)->toBeNull();
    expect($user->fresh()->remote_id)->toBeNull();
});

it('sets available_at on push 429 using the Retry-After header', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $todo = Todo::factory()->forUser($user)->create();
    $op = SyncOperation::create([
        'user_id' => $user->id,
        'entity_type' => 'todo',
        'entity_uuid' => $todo->uuid,
        'operation' => 'created',
        'payload' => ['uuid' => $todo->uuid, 'title' => $todo->title, 'is_completed' => false,
            'last_modified_at' => now()->toIso8601String(), 'deleted_at' => null],
        'status' => 'pending',
    ]);

    Http::fake([
        '*/api/sync/push' => Http::response('', 429, ['Retry-After' => '30']),
        '*/api/sync/pull' => Http::response([
            'todos' => [], 'has_more' => false, 'server_time' => now()->toIso8601String(),
        ], 200),
    ]);

    runJob($user->id, onlineCs());

    $fresh = $op->fresh();
    expect($fresh->status)->toBe('pending');
    expect($fresh->available_at)->not->toBeNull();
    expect($fresh->available_at->isFuture())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Deduplication
|--------------------------------------------------------------------------
*/

it('keeps only the most recent operation per UUID and cancels superseded ones', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $todo = Todo::factory()->forUser($user)->create();
    $serverTime = now()->toIso8601String();

    // Two ops for the same UUID — the second (updated) should survive
    $older = SyncOperation::create([
        'user_id' => $user->id,
        'entity_type' => 'todo',
        'entity_uuid' => $todo->uuid,
        'operation' => 'created',
        'payload' => ['uuid' => $todo->uuid, 'title' => 'old', 'is_completed' => false,
            'last_modified_at' => now()->subMinutes(5)->toIso8601String(), 'deleted_at' => null],
        'status' => 'pending',
        'created_at' => now()->subMinutes(2),
    ]);
    $newer = SyncOperation::create([
        'user_id' => $user->id,
        'entity_type' => 'todo',
        'entity_uuid' => $todo->uuid,
        'operation' => 'updated',
        'payload' => ['uuid' => $todo->uuid, 'title' => 'new', 'is_completed' => false,
            'last_modified_at' => now()->toIso8601String(), 'deleted_at' => null],
        'status' => 'pending',
        'created_at' => now()->subMinute(),
    ]);

    Http::fake([
        '*/api/sync/push' => Http::response([
            'results' => [['uuid' => $todo->uuid, 'status' => 'ok',
                'title' => 'new', 'is_completed' => false,
                'last_modified_at' => $serverTime, 'deleted_at' => null]],
            'server_time' => $serverTime,
        ], 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [], 'has_more' => false, 'server_time' => $serverTime,
        ], 200),
    ]);

    runJob($user->id, onlineCs());

    expect($older->fresh()->status)->toBe('cancelled');
    expect($newer->fresh()->status)->toBe('done');
});

/*
|--------------------------------------------------------------------------
| Orphan detection
|--------------------------------------------------------------------------
*/

it('cancels a created op whose todo no longer exists locally', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $orphanUuid = (string) Str::uuid();
    $op = SyncOperation::create([
        'user_id' => $user->id,
        'entity_type' => 'todo',
        'entity_uuid' => $orphanUuid,
        'operation' => 'created',
        'payload' => ['uuid' => $orphanUuid, 'title' => 'Gone', 'is_completed' => false,
            'last_modified_at' => now()->toIso8601String(), 'deleted_at' => null],
        'status' => 'pending',
    ]);

    Http::fake(['*/api/sync/*' => Http::response(['todos' => [], 'has_more' => false,
        'server_time' => now()->toIso8601String(), 'results' => []], 200)]);

    runJob($user->id, onlineCs());

    expect($op->fresh()->status)->toBe('cancelled');
});
