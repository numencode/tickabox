<?php

use App\Models\SyncMeta;

it('returns null default when key does not exist', function () {
    $user = makeUser();

    expect(SyncMeta::getValue($user->id, 'last_pulled_at'))->toBeNull();
});

it('returns the provided default when key does not exist', function () {
    $user = makeUser();

    expect(SyncMeta::getValue($user->id, 'missing_key', 'fallback'))->toBe('fallback');
});

it('stores and retrieves a value', function () {
    $user = makeUser();
    SyncMeta::setValue($user->id, 'last_pulled_at', '2026-04-24T12:00:00Z');

    expect(SyncMeta::getValue($user->id, 'last_pulled_at'))->toBe('2026-04-24T12:00:00Z');
});

it('updates an existing value without creating a duplicate row', function () {
    $user = makeUser();
    SyncMeta::setValue($user->id, 'last_pulled_at', 'first');
    SyncMeta::setValue($user->id, 'last_pulled_at', 'second');

    expect(SyncMeta::getValue($user->id, 'last_pulled_at'))->toBe('second');
    expect(SyncMeta::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('deleteForUser removes all entries for that user', function () {
    $user = makeUser();
    SyncMeta::setValue($user->id, 'key1', 'a');
    SyncMeta::setValue($user->id, 'key2', 'b');

    SyncMeta::deleteForUser($user->id);

    expect(SyncMeta::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('deleteForUser does not remove entries belonging to other users', function () {
    $userA = makeUser();
    $userB = makeUser();
    SyncMeta::setValue($userA->id, 'last_pulled_at', 'a-value');
    SyncMeta::setValue($userB->id, 'last_pulled_at', 'b-value');

    SyncMeta::deleteForUser($userA->id);

    expect(SyncMeta::getValue($userB->id, 'last_pulled_at'))->toBe('b-value');
});
