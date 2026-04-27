<?php

use App\Jobs\SyncPendingOperationsJob;
use App\Models\SyncOperation;
use App\Models\Todo;
use App\Services\ConnectivityService;
use App\Services\TodoService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

// Prevent the sync job from making real HTTP calls during these tests.
beforeEach(function () {
    Http::fake(['*' => Http::response(
        ['results' => [], 'todos' => [], 'has_more' => false, 'server_time' => now()->toIso8601String()],
        200
    )]);
});

function offlineService(): TodoService
{
    $cs = Mockery::mock(ConnectivityService::class);
    $cs->shouldReceive('isOnline')->andReturn(false);

    return new TodoService($cs);
}

function onlineService(): TodoService
{
    $cs = Mockery::mock(ConnectivityService::class);
    $cs->shouldReceive('isOnline')->andReturn(true);

    return new TodoService($cs);
}

/*
|--------------------------------------------------------------------------
| Create
|--------------------------------------------------------------------------
*/

it('creates a todo and queues a pending sync operation', function () {
    $user = makeUser();
    $todo = offlineService()->create($user, 'Buy groceries');

    expect($todo->title)->toBe('Buy groceries');
    expect($todo->is_completed)->toBeFalse();
    expect($todo->sync_status)->toBe('pending');
    expect($todo->user_id)->toBe($user->id);

    $this->assertDatabaseHas('sync_operations', [
        'user_id' => $user->id,
        'entity_uuid' => $todo->uuid,
        'operation' => 'created',
        'status' => 'pending',
    ]);
});

it('trims whitespace from the title', function () {
    $user = makeUser();
    $todo = offlineService()->create($user, '  Trimmed  ');

    expect($todo->title)->toBe('Trimmed');
});

it('throws for an empty title', function () {
    $user = makeUser();

    expect(fn () => offlineService()->create($user, '   '))
        ->toThrow(\InvalidArgumentException::class);
});

it('throws for a title longer than 255 characters', function () {
    $user = makeUser();

    expect(fn () => offlineService()->create($user, str_repeat('a', 256)))
        ->toThrow(\InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Toggle
|--------------------------------------------------------------------------
*/

it('toggles a todo from incomplete to complete', function () {
    $user = makeUser();
    $todo = Todo::factory()->forUser($user)->create(['is_completed' => false]);

    $updated = offlineService()->toggle($user, $todo);

    expect($updated->is_completed)->toBeTrue();
    expect($updated->sync_status)->toBe('pending');
    $this->assertDatabaseHas('sync_operations', [
        'entity_uuid' => $todo->uuid,
        'operation' => 'updated',
    ]);
});

it('toggles a todo from complete to incomplete', function () {
    $user = makeUser();
    $todo = Todo::factory()->forUser($user)->completed()->create();

    $updated = offlineService()->toggle($user, $todo);

    expect($updated->is_completed)->toBeFalse();
});

it('aborts with 403 when toggling another users todo', function () {
    $userA = makeUser();
    $userB = makeUser();
    $todo = Todo::factory()->forUser($userB)->create();

    expect(fn () => offlineService()->toggle($userA, $todo))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

it('soft-deletes the todo and queues a deleted sync operation', function () {
    $user = makeUser();
    $todo = Todo::factory()->forUser($user)->create();

    offlineService()->delete($user, $todo);

    expect(Todo::find($todo->id))->toBeNull();
    expect(Todo::withTrashed()->find($todo->id))->not->toBeNull();
    $this->assertDatabaseHas('sync_operations', [
        'entity_uuid' => $todo->uuid,
        'operation' => 'deleted',
        'status' => 'pending',
    ]);
});

it('cancels pending created/updated ops when deleting', function () {
    $user = makeUser();
    $todo = Todo::factory()->forUser($user)->create();

    // Queue a pending 'updated' operation for this todo
    SyncOperation::create([
        'user_id' => $user->id,
        'entity_type' => 'todo',
        'entity_uuid' => $todo->uuid,
        'operation' => 'updated',
        'status' => 'pending',
    ]);

    offlineService()->delete($user, $todo);

    $this->assertDatabaseHas('sync_operations', [
        'entity_uuid' => $todo->uuid,
        'operation' => 'updated',
        'status' => 'cancelled',
    ]);
});

it('aborts with 403 when deleting another users todo', function () {
    $userA = makeUser();
    $userB = makeUser();
    $todo = Todo::factory()->forUser($userB)->create();

    expect(fn () => offlineService()->delete($userA, $todo))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

/*
|--------------------------------------------------------------------------
| Sync trigger
|--------------------------------------------------------------------------
*/

it('dispatches the sync job when online after a mutation', function () {
    Bus::fake();
    $user = makeUser(['sanctum_token' => 'test-token']);

    onlineService()->create($user, 'Sync me');

    Bus::assertDispatched(SyncPendingOperationsJob::class);
});

it('does not dispatch the sync job when offline', function () {
    Bus::fake();
    $user = makeUser();

    offlineService()->create($user, 'No sync');

    Bus::assertNotDispatched(SyncPendingOperationsJob::class);
});
