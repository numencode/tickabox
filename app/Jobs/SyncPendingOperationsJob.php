<?php

namespace App\Jobs;

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

    public function handle(ConnectivityService $connectivityService): void
    {
        if (! $connectivityService->isOnline()) {
            return;
        }

        $user = User::query()
            ->whereNotNull('remote_id')
            ->first();

        if (! $user) {
            return;
        }

        $secureToken = SecureStorage::get('sanctum_token');
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
    }

    protected function pushPendingOperations(User $user, string $token, string $baseUrl): void
    {
        $operations = SyncOperation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'failed'])
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($operations->isEmpty()) {
            return;
        }

        foreach ($operations as $operation) {
            $operation->update([
                'status' => 'processing',
                'attempts' => $operation->attempts + 1,
                'last_error' => null,
            ]);
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

            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($token)
                ->post('/api/sync/push', $payload)
                ->throw()
                ->json();

            DB::transaction(function () use ($operations, $response, $user) {
                foreach ($operations as $operation) {
                    $operation->update([
                        'status' => 'done',
                        'last_error' => null,
                    ]);
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
                        if (! $todo->trashed()) {
                            $todo->delete();
                        }

                        $todo->update([
                            'sync_status' => 'synced',
                            'last_modified_at' => $remoteTodo['last_modified_at'] ?? $todo->last_modified_at,
                        ]);

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
                ]);
            }
        }
    }

    protected function pullRemoteChanges(User $user, string $token, string $baseUrl): void
    {
        $since = SyncMeta::getValue('last_pulled_at');

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($token)
                ->get('/api/sync/pull', array_filter([
                    'since' => $since,
                ]))
                ->throw()
                ->json();

            DB::transaction(function () use ($response, $user) {
                foreach (($response['todos'] ?? []) as $remoteTodo) {
                    $uuid = $remoteTodo['uuid'] ?? null;

                    if (! $uuid) {
                        continue;
                    }

                    $incomingModifiedAt = ! empty($remoteTodo['last_modified_at'])
                        ? Carbon::parse($remoteTodo['last_modified_at'])
                        : now();

                    $localTodo = Todo::withTrashed()
                        ->where('user_id', $user->id)
                        ->where('uuid', $uuid)
                        ->first();

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

                if (! empty($response['server_time'])) {
                    SyncMeta::setValue('last_pulled_at', $response['server_time']);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Todo pull sync failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function arrayString(array $data, string $key, string $default): string
    {
        return array_key_exists($key, $data)
            ? (string) $data[$key]
            : $default;
    }

    protected function arrayBool(array $data, string $key, bool $default): bool
    {
        return array_key_exists($key, $data)
            ? filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $data[$key]
            : $default;
    }
}