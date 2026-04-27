<?php

use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Str;

it('auto-generates a UUID when creating a todo', function () {
    $user = makeUser();
    $todo = Todo::create([
        'user_id' => $user->id,
        'title' => 'Test todo',
        'sync_status' => 'pending',
    ]);

    expect($todo->uuid)->toBeString()->toHaveLength(36);
    expect(Str::isUuid($todo->uuid))->toBeTrue();
});

it('does not overwrite an explicitly provided UUID', function () {
    $user = makeUser();
    $uuid = (string) Str::uuid();
    $todo = Todo::create([
        'uuid' => $uuid,
        'user_id' => $user->id,
        'title' => 'Test todo',
        'sync_status' => 'pending',
    ]);

    expect($todo->uuid)->toBe($uuid);
});

it('auto-sets last_modified_at when not provided', function () {
    $user = makeUser();
    $before = now()->subSecond();
    $todo = Todo::create([
        'user_id' => $user->id,
        'title' => 'Auto timestamp',
        'sync_status' => 'pending',
    ]);

    expect($todo->last_modified_at)->not->toBeNull();
    expect($todo->last_modified_at->isAfter($before))->toBeTrue();
});

it('does not overwrite an explicitly provided last_modified_at', function () {
    $user = makeUser();
    $ts = now()->subHour();
    $todo = Todo::create([
        'user_id' => $user->id,
        'title' => 'Custom timestamp',
        'sync_status' => 'pending',
        'last_modified_at' => $ts,
    ]);

    expect($todo->last_modified_at->timestamp)->toBe($ts->timestamp);
});

it('soft-deletes rather than hard-deletes', function () {
    $user = makeUser();
    $todo = Todo::factory()->forUser($user)->create();

    $todo->delete();

    expect(Todo::find($todo->id))->toBeNull();
    expect(Todo::withTrashed()->find($todo->id))->not->toBeNull();
    expect(Todo::withTrashed()->find($todo->id)->trashed())->toBeTrue();
});

it('belongs to a user', function () {
    $user = makeUser();
    $todo = Todo::factory()->forUser($user)->create();

    expect($todo->user->id)->toBe($user->id);
});
