<?php

namespace App\Jobs;

use App\Exceptions\AuthExpiredException;
use App\Models\SyncMeta;
use App\Models\SyncOperation;
use App\Models\Todo;
use App\Models\User;
use App\Services\ConnectivityService;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Facades\SecureStorage;

class SyncPendingOperationsJob
{
    use Queueable;

    public function __construct(public readonly int $userId) {}

    protected bool $authExpired = false;

    public function handle(ConnectivityService $connectivityService): void
    {
        if (! $connectivityService->isOnline()) {
            return;
        }

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        try {
            $secureToken = SecureStorage::get('sanctum_token');
        } catch (\Throwable $e) {
            Log::debug('SyncPendingOperationsJob: SecureStorage unavailable, falling back to DB token.', ['error' => $e->getMessage()]);
            $secureToken = null;
        }
        $token = $secureToken ?: $user->sanctum_token;

        if (! $token) {
            return;
        }

        $baseUrl = rtrim(config('services.tickabox_api.base_url'), '/');

        if (! $baseUrl) {
            return;
        }

        $this->pushPendingOperations($user, $token, $baseUrl);
        $this->pullRemoteChanges($user, $token, $baseUrl);

        if ($this->authExpired) {
            throw new AuthExpiredException;
        }
    }

    protected function pushPendingOperations(User $user, string $token, string $baseUrl): void
    {
        SyncOperation::where('user_id', $user->id)
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'pending']);

        $operations = DB::transaction(function () use ($user) {
            $operations = SyncOperation::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'failed'])
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(50)
                ->lockForUpdate()
                ->get();

            if ($operations->isEmpty()) {
                return collect();
            }

            // Keep only the most recent operation per entity UUID; cancel superseded ones.
            $superseded = collect();
            $operations = $operations
                ->groupBy('entity_uuid')
                ->map(function ($group) use (&$superseded) {
                    if ($group->count() > 1) {
                        $superseded = $superseded->merge($group->slice(0, -1));
                    }

                    return $group->last();
                })
                ->values();

            if ($superseded->isNotEmpty()) {
                SyncOperation::whereIn('id', $superseded->pluck('id'))
                    ->update(['status' => 'cancelled']);
            }

            // Drop operations whose todo no longer exists locally (even soft-deleted).
            // created/updated ops are stale → cancelled; deleted ops are already done → done.
            $existingUuids = Todo::withTrashed()
                ->where('user_id', $user->id)
                ->whereIn('uuid', $operations->pluck('entity_uuid'))
                ->pluck('uuid')
                ->flip();

            [$orphaned, $operations] = $operations->partition(
                fn ($op) => ! $existingUuids->has($op->entity_uuid)
            );

            if ($orphaned->isNotEmpty()) {
                foreach ($orphaned as $op) {
                    $op->update(['status' => $op->operation === 'deleted' ? 'done' : 'cancelled']);
                }
            }

            foreach ($operations as $operation) {
                $operation->update([
                    'status' => 'processing',
                    'attempts' => $operation->attempts + 1,
                    'last_error' => null,
                ]);
            }

            return $operations;
        });

        if ($operations->isEmpty()) {
            return;
        }

        try {
            $payload = [
                'operations' => $operations->map(function (SyncOperation $operation) {
                    return [
                        'uuid' => $operation->entity_uuid,
                        'operation' => $operation->operation,
                        'payload' => $operation->payload,
                    ];
                })->values()->all(),
            ];

            $pushResponse = Http::baseUrl($baseUrl)
                ->timeout(config('services.tickabox_api.timeout', 30))
                ->connectTimeout(config('services.tickabox_api.connect_timeout', 5))
                ->acceptJson()
                ->withToken($token)
                ->post('/api/sync/push', $payload);

            if ($pushResponse->status() === 401) {
                $this->clearExpiredAuth($user);

                return;
            }

            if ($pushResponse->status() === 403) {
                Log::warning('SyncPendingOperationsJob: push rejected (403) — account may be deactivated.');

                return;
            }

            if ($pushResponse->status() === 429) {
                $retryAfter = (int) ($pushResponse->header('Retry-After') ?? 60);
                Log::warning('SyncPendingOperationsJob: push rate limited, will retry.', [
                    'user_id' => $user->id,
                    'retry_after' => $retryAfter,
                ]);

                foreach ($operations as $operation) {
                    $operation->update([
                        'status' => 'pending',
                        'available_at' => now()->addSeconds($retryAfter),
                    ]);
                }

                return;
            }

            $response = $pushResponse->throw()->json();

            DB::transaction(function () use ($operations, $response, $user) {
                $confirmedUuids = collect($response['results'] ?? [])
                    ->filter(fn ($r) => ($r['status'] ?? '') === 'ok')
                    ->pluck('uuid')->filter()->flip();

                foreach ($operations as $operation) {
                    if ($confirmedUuids->has($operation->entity_uuid)) {
                        $operation->update(['status' => 'done', 'last_error' => null]);
                    } else {
                        $operation->update([
                            'status' => 'failed',
                            'last_error' => 'Not confirmed in server response.',
                            'available_at' => now()->addSeconds($this->backoffDelay($operation->attempts)),
                        ]);
                    }
                }

                foreach (($response['results'] ?? []) as $remoteTodo) {
                    $todo = Todo::withTrashed()
                        ->where('user_id', $user->id)
                        ->where('uuid', $remoteTodo['uuid'] ?? null)
                        ->first();

                    if (! $todo) {
                        continue;
                    }

                    if (! empty($remoteTodo['deleted_at'])) {
                        $todo->update([
                            'sync_status' => 'synced',
                            'last_modified_at' => $remoteTodo['last_modified_at'] ?? $todo->last_modified_at,
                        ]);

                        if (! $todo->trashed()) {
                            $todo->delete();
                        }

                        continue;
                    }

                    $remoteModifiedAt = ! empty($remoteTodo['last_modified_at'])
                        ? Carbon::parse($remoteTodo['last_modified_at'], 'UTC')
                        : null;

                    if ($remoteModifiedAt && $todo->last_modified_at && $remoteModifiedAt->lt($todo->last_modified_at)) {
                        $todo->update(['sync_status' => 'synced']);

                        continue;
                    }

                    if ($todo->trashed()) {
                        $todo->restore();
                    }

                    $todo->update([
                        'title' => $this->arrayString($remoteTodo, 'title', $todo->title),
                        'is_completed' => $this->arrayBool($remoteTodo, 'is_completed', (bool) $todo->is_completed),
                        'sync_status' => 'synced',
                        'last_modified_at' => $remoteTodo['last_modified_at'] ?? $todo->last_modified_at,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Todo push sync failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            foreach ($operations as $operation) {
                $operation->update([
                    'status' => 'failed',
                    'last_error' => $e->getMessage(),
                    'available_at' => now()->addSeconds($this->backoffDelay($operation->attempts)),
                ]);
            }
        }
    }

    protected function pullRemoteChanges(User $user, string $token, string $baseUrl): void
    {
        $since = SyncMeta::getValue($user->id, 'last_pulled_at');
        $sinceId = 0;
        $maxPages = 20;

        for ($page = 0; $page < $maxPages; $page++) {
            try {
                $pullResponse = Http::baseUrl($baseUrl)
                    ->timeout(config('services.tickabox_api.timeout', 30))
                    ->connectTimeout(config('services.tickabox_api.connect_timeout', 5))
                    ->acceptJson()
                    ->withToken($token)
                    ->get('/api/sync/pull', array_filter([
                        'since' => $since,
                        'since_id' => $sinceId > 0 ? $sinceId : null,
                    ]));

                if ($pullResponse->status() === 401) {
                    $this->clearExpiredAuth($user);

                    return;
                }

                if ($pullResponse->status() === 403) {
                    Log::warning('SyncPendingOperationsJob: pull rejected (403) — account may be deactivated.');

                    return;
                }

                if ($pullResponse->status() === 429) {
                    $retryAfter = (int) ($pullResponse->header('Retry-After') ?? 60);
                    Log::warning('SyncPendingOperationsJob: pull rate limited, will retry.', [
                        'user_id' => $user->id,
                        'retry_after' => $retryAfter,
                    ]);

                    return;
                }

                $response = $pullResponse->throw()->json();

                if (! is_array($response)) {
                    Log::warning('SyncPendingOperationsJob: pull response body is not valid JSON, skipping.');

                    return;
                }

                $hasMore = (bool) ($response['has_more'] ?? false);
                $serverTime = $response['server_time'] ?? null;

                DB::transaction(function () use ($response, $user) {
                    $remoteTodos = $response['todos'] ?? [];

                    $uuids = collect($remoteTodos)->pluck('uuid')->filter()->all();

                    $localTodos = Todo::withTrashed()
                        ->where('user_id', $user->id)
                        ->whereIn('uuid', $uuids)
                        ->get()
                        ->keyBy('uuid');

                    // UUIDs where user already deleted locally but hasn't synced yet.
                    // Pull must not restore them before the delete op is pushed.
                    $pendingDeletedUuids = SyncOperation::where('user_id', $user->id)
                        ->whereIn('entity_uuid', $uuids)
                        ->whereIn('status', ['pending', 'failed', 'processing'])
                        ->where('operation', 'deleted')
                        ->pluck('entity_uuid')
                        ->flip();

                    foreach ($remoteTodos as $remoteTodo) {
                        $uuid = $remoteTodo['uuid'] ?? null;

                        if (! $uuid) {
                            continue;
                        }

                        if (empty($remoteTodo['last_modified_at'])) {
                            Log::warning('SyncPendingOperationsJob: remote todo missing last_modified_at, skipping.', [
                                'user_id' => $user->id,
                                'uuid' => $uuid,
                            ]);

                            continue;
                        }

                        $incomingModifiedAt = Carbon::parse($remoteTodo['last_modified_at'], 'UTC');

                        $localTodo = $localTodos->get($uuid);

                        if (! $localTodo) {
                            $localTodo = Todo::create([
                                'uuid' => $uuid,
                                'user_id' => $user->id,
                                'title' => $this->arrayString($remoteTodo, 'title', ''),
                                'is_completed' => $this->arrayBool($remoteTodo, 'is_completed', false),
                                'sync_status' => 'synced',
                                'last_modified_at' => $incomingModifiedAt,
                            ]);

                            if (! empty($remoteTodo['deleted_at'])) {
                                $localTodo->delete();
                            }

                            continue;
                        }

                        $localModifiedAt = $localTodo->last_modified_at;

                        if ($localModifiedAt && $incomingModifiedAt->lt($localModifiedAt)) {
                            continue;
                        }

                        if (! empty($remoteTodo['deleted_at'])) {
                            $localTodo->update([
                                'title' => $this->arrayString($remoteTodo, 'title', $localTodo->title),
                                'is_completed' => $this->arrayBool($remoteTodo, 'is_completed', (bool) $localTodo->is_completed),
                                'sync_status' => 'synced',
                                'last_modified_at' => $incomingModifiedAt,
                            ]);

                            if (! $localTodo->trashed()) {
                                $localTodo->delete();
                            }

                            continue;
                        }

                        // Pending local delete takes precedence — don't restore until it has synced.
                        if ($pendingDeletedUuids->has($uuid)) {
                            continue;
                        }

                        if ($localTodo->trashed()) {
                            $localTodo->restore();
                        }

                        $localTodo->update([
                            'title' => $this->arrayString($remoteTodo, 'title', $localTodo->title),
                            'is_completed' => $this->arrayBool($remoteTodo, 'is_completed', (bool) $localTodo->is_completed),
                            'sync_status' => 'synced',
                            'last_modified_at' => $incomingModifiedAt,
                        ]);
                    }

                    $deletedUuids = collect($remoteTodos)
                        ->filter(fn ($t) => ! empty($t['deleted_at']))
                        ->pluck('uuid')
                        ->filter();

                    if ($deletedUuids->isNotEmpty()) {
                        SyncOperation::where('user_id', $user->id)
                            ->whereIn('entity_uuid', $deletedUuids)
                            ->whereIn('status', ['pending', 'failed'])
                            ->where('operation', '!=', 'deleted')
                            ->update(['status' => 'cancelled']);
                    }
                });

                if (! $hasMore) {
                    if (! empty($serverTime)) {
                        SyncMeta::setValue($user->id, 'last_pulled_at', $serverTime);
                    }

                    return;
                }

                // Advance cursor using server-provided next page pointers.
                $nextSince = $response['next_since'] ?? null;
                $nextSinceId = (int) ($response['next_since_id'] ?? 0);

                if (! $nextSince) {
                    // Can't advance cursor — save progress and stop.
                    if (! empty($serverTime)) {
                        SyncMeta::setValue($user->id, 'last_pulled_at', $serverTime);
                    }

                    return;
                }

                $since = $nextSince;
                $sinceId = $nextSinceId;
            } catch (\Throwable $e) {
                Log::error('Todo pull sync failed', [
                    'user_id' => $user->id,
                    'page' => $page,
                    'since' => $since,
                    'message' => $e->getMessage(),
                ]);

                return;
            }
        }

        Log::warning('SyncPendingOperationsJob: pull reached max page limit.', ['user_id' => $user->id]);
    }

    protected function clearExpiredAuth(User $user): void
    {
        Log::warning('SyncPendingOperationsJob: token rejected (401), clearing auth state.');

        $this->authExpired = true;

        SyncMeta::deleteForUser($user->id);

        try {
            SecureStorage::delete('sanctum_token');
        } catch (\Throwable $e) {
            Log::debug('SyncPendingOperationsJob: SecureStorage unavailable on delete.', ['error' => $e->getMessage()]);
        }

        $user->update([
            'remote_id' => null,
            'sanctum_token' => null,
        ]);
    }

    protected function backoffDelay(int $attempts): int
    {
        $base = min((int) pow(2, max(0, $attempts - 1)), 300);

        return $base + random_int(0, max(1, (int) ($base * 0.3)));
    }

    protected function arrayString(array $data, string $key, string $default): string
    {
        if (! array_key_exists($key, $data)) {
            return $default;
        }
        $value = $data[$key];

        if (! (is_string($value) || is_numeric($value))) {
            return $default;
        }

        return mb_substr((string) $value, 0, 255);
    }

    protected function arrayBool(array $data, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $data)) {
            return $default;
        }
        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
