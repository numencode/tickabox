<?php

use App\Exceptions\AuthExpiredException;
use App\Jobs\SyncPendingOperationsJob;
use App\Models\SyncMeta;
use App\Models\SyncOperation;
use App\Models\Todo;
use App\Services\ConnectivityService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function pullOnlineCs(): ConnectivityService
{
    $cs = Mockery::mock(ConnectivityService::class);
    $cs->shouldReceive('isOnline')->andReturn(true);

    return $cs;
}

function runPullJob(int $userId): void
{
    (new SyncPendingOperationsJob($userId))->handle(pullOnlineCs());
}

function emptyPushResponse(): array
{
    return ['results' => [], 'server_time' => now()->toIso8601String()];
}

/*
|--------------------------------------------------------------------------
| New remote todos
|--------------------------------------------------------------------------
*/

it('creates a new local todo from the pull response', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $remoteUuid = (string) Str::uuid();
    $serverTime = now()->toIso8601String();

    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [[
                'uuid' => $remoteUuid,
                'title' => 'Remote Task',
                'is_completed' => false,
                'last_modified_at' => $serverTime,
                'deleted_at' => null,
            ]],
            'has_more' => false,
            'next_since' => null,
            'next_since_id' => 0,
            'server_time' => $serverTime,
        ], 200),
    ]);

    runPullJob($user->id);

    $this->assertDatabaseHas('todos', ['uuid' => $remoteUuid, 'title' => 'Remote Task', 'user_id' => $user->id]);
    expect(Todo::where('uuid', $remoteUuid)->first()->sync_status)->toBe('synced');
});

it('soft-deletes a new remote todo if it arrives already deleted', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $remoteUuid = (string) Str::uuid();
    $serverTime = now()->toIso8601String();

    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [[
                'uuid' => $remoteUuid,
                'title' => 'Already Deleted',
                'is_completed' => false,
                'last_modified_at' => $serverTime,
                'deleted_at' => $serverTime,
            ]],
            'has_more' => false,
            'server_time' => $serverTime,
        ], 200),
    ]);

    runPullJob($user->id);

    expect(Todo::where('uuid', $remoteUuid)->count())->toBe(0);
    expect(Todo::withTrashed()->where('uuid', $remoteUuid)->first()->trashed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Last-Write-Wins on existing todos
|--------------------------------------------------------------------------
*/

it('updates an existing todo when the remote version is newer (LWW)', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $todo = Todo::factory()->forUser($user)->create([
        'title' => 'Local Title',
        'last_modified_at' => now()->subHour(),
    ]);
    $serverTime = now()->toIso8601String();

    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [[
                'uuid' => $todo->uuid,
                'title' => 'Remote Title',
                'is_completed' => true,
                'last_modified_at' => $serverTime,
                'deleted_at' => null,
            ]],
            'has_more' => false,
            'server_time' => $serverTime,
        ], 200),
    ]);

    runPullJob($user->id);

    expect($todo->fresh()->title)->toBe('Remote Title');
    expect($todo->fresh()->is_completed)->toBeTrue();
    expect($todo->fresh()->sync_status)->toBe('synced');
});

it('ignores a remote update when the local version is newer (LWW)', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $serverTime = now()->subHour()->toIso8601String();
    $todo = Todo::factory()->forUser($user)->create([
        'title' => 'Local Newer',
        'last_modified_at' => now(),
    ]);

    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [[
                'uuid' => $todo->uuid,
                'title' => 'Remote Older',
                'is_completed' => false,
                'last_modified_at' => $serverTime,
                'deleted_at' => null,
            ]],
            'has_more' => false,
            'server_time' => now()->toIso8601String(),
        ], 200),
    ]);

    runPullJob($user->id);

    expect($todo->fresh()->title)->toBe('Local Newer');
});

it('soft-deletes an existing todo when the remote signals deletion', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $todo = Todo::factory()->forUser($user)->create(['last_modified_at' => now()->subHour()]);
    $serverTime = now()->toIso8601String();

    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [[
                'uuid' => $todo->uuid,
                'title' => $todo->title,
                'is_completed' => false,
                'last_modified_at' => $serverTime,
                'deleted_at' => $serverTime,
            ]],
            'has_more' => false,
            'server_time' => $serverTime,
        ], 200),
    ]);

    runPullJob($user->id);

    expect(Todo::find($todo->id))->toBeNull();
    expect(Todo::withTrashed()->find($todo->id)->trashed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Pending local delete protection
|--------------------------------------------------------------------------
*/

it('does not restore a todo that has a pending local delete operation', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $todo = Todo::factory()->forUser($user)->create([
        'title' => 'Locally Deleted',
        'last_modified_at' => now()->subHour(),
    ]);

    // Locally deleted but not yet synced
    SyncOperation::create([
        'user_id' => $user->id,
        'entity_type' => 'todo',
        'entity_uuid' => $todo->uuid,
        'operation' => 'deleted',
        'status' => 'pending',
    ]);

    $serverTime = now()->toIso8601String();
    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [[
                'uuid' => $todo->uuid,
                'title' => 'Server Title',
                'is_completed' => false,
                'last_modified_at' => $serverTime,
                'deleted_at' => null,
            ]],
            'has_more' => false,
            'server_time' => $serverTime,
        ], 200),
    ]);

    runPullJob($user->id);

    // The title must NOT have been overwritten — local delete has precedence
    expect($todo->fresh()->title)->toBe('Locally Deleted');
});

/*
|--------------------------------------------------------------------------
| Cursor / server_time persistence
|--------------------------------------------------------------------------
*/

it('saves server_time as last_pulled_at after a complete pull', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $serverTime = '2026-04-27T10:00:00+00:00';

    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [],
            'has_more' => false,
            'server_time' => $serverTime,
        ], 200),
    ]);

    runPullJob($user->id);

    expect(SyncMeta::getValue($user->id, 'last_pulled_at'))->toBe($serverTime);
});

it('skips a remote todo that is missing last_modified_at', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $remoteUuid = (string) Str::uuid();
    $serverTime = now()->toIso8601String();

    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull' => Http::response([
            'todos' => [[
                'uuid' => $remoteUuid,
                'title' => 'No Timestamp',
                'is_completed' => false,
                'last_modified_at' => null,
                'deleted_at' => null,
            ]],
            'has_more' => false,
            'server_time' => $serverTime,
        ], 200),
    ]);

    runPullJob($user->id);

    expect(Todo::where('uuid', $remoteUuid)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

it('follows pagination when has_more is true', function () {
    $user = makeUser(['sanctum_token' => 'tok']);
    $uuid1 = (string) Str::uuid();
    $uuid2 = (string) Str::uuid();
    $serverTime = now()->toIso8601String();
    $midTime = now()->subMinutes(5)->toIso8601String();
    $callCount = 0;

    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull*' => function () use ($uuid1, $uuid2, $midTime, $serverTime, &$callCount) {
            $callCount++;

            if ($callCount === 1) {
                return Http::response([
                    'todos' => [['uuid' => $uuid1, 'title' => 'Page 1', 'is_completed' => false,
                        'last_modified_at' => $midTime, 'deleted_at' => null]],
                    'has_more' => true,
                    'next_since' => $midTime,
                    'next_since_id' => 1,
                    'server_time' => $serverTime,
                ], 200);
            }

            return Http::response([
                'todos' => [['uuid' => $uuid2, 'title' => 'Page 2', 'is_completed' => false,
                    'last_modified_at' => $serverTime, 'deleted_at' => null]],
                'has_more' => false,
                'server_time' => $serverTime,
            ], 200);
        },
    ]);

    runPullJob($user->id);

    $this->assertDatabaseHas('todos', ['uuid' => $uuid1, 'user_id' => $user->id]);
    $this->assertDatabaseHas('todos', ['uuid' => $uuid2, 'user_id' => $user->id]);
    expect(SyncMeta::getValue($user->id, 'last_pulled_at'))->toBe($serverTime);
});

/*
|--------------------------------------------------------------------------
| HTTP error handling
|--------------------------------------------------------------------------
*/

it('clears auth and throws AuthExpiredException on pull 401', function () {
    $user = makeUser(['sanctum_token' => 'tok', 'remote_id' => 7]);

    Http::fake([
        '*/api/sync/push' => Http::response(emptyPushResponse(), 200),
        '*/api/sync/pull' => Http::response('', 401),
    ]);

    expect(fn () => runPullJob($user->id))->toThrow(AuthExpiredException::class);

    expect($user->fresh()->sanctum_token)->toBeNull();
    expect($user->fresh()->remote_id)->toBeNull();
});
