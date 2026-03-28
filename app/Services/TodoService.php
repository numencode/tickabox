<?php

namespace App\Services;

use App\Jobs\SyncPendingOperationsJob;
use App\Models\SyncOperation;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TodoService
{
    public function __construct(protected ConnectivityService $connectivityService) {}

    public function create(User $user, string $title): Todo
    {
        $title = trim($title);

        $todo = DB::transaction(function () use ($user, $title) {
            $todo = Todo::create([
                'user_id' => $user->id,
                'title' => $title,
                'is_completed' => false,
                'sync_status' => 'pending',
                'last_modified_at' => now(),
            ]);

            $this->queueSyncOperation($user, $todo, 'created');

            return $todo;
        });

        $this->syncIfOnline();

        return $todo;
    }

    public function toggle(Todo $todo): Todo
    {
        $todo = DB::transaction(function () use ($todo) {
            $todo->update([
                'is_completed' => ! $todo->is_completed,
                'sync_status' => 'pending',
                'last_modified_at' => now(),
            ]);

            $this->queueSyncOperation($todo->user, $todo, 'updated');

            return $todo->fresh();
        });

        $this->syncIfOnline();

        return $todo;
    }

    public function delete(Todo $todo): void
    {
        DB::transaction(function () use ($todo) {
            $deletedAt = now();

            $todo->update([
                'sync_status' => 'pending',
                'last_modified_at' => $deletedAt,
            ]);

            SyncOperation::create([
                'user_id' => $todo->user_id,
                'entity_type' => 'todo',
                'entity_uuid' => $todo->uuid,
                'operation' => 'deleted',
                'payload' => [
                    'uuid' => $todo->uuid,
                    'title' => $todo->title,
                    'is_completed' => $todo->is_completed,
                    'last_modified_at' => $deletedAt->toIso8601String(),
                    'deleted_at' => $deletedAt->toIso8601String(),
                ],
                'status' => 'pending',
                'available_at' => now(),
            ]);

            $todo->delete();
        });

        $this->syncIfOnline();
    }

    public function syncNow(): void
    {
        $this->syncIfOnline();
    }

    protected function syncIfOnline(): void
    {
        if (! $this->connectivityService->isOnline()) {
            return;
        }

        SyncPendingOperationsJob::dispatchSync();
    }

    protected function queueSyncOperation(User $user, Todo $todo, string $operation): SyncOperation
    {
        return SyncOperation::create([
            'user_id' => $user->id,
            'entity_type' => 'todo',
            'entity_uuid' => $todo->uuid,
            'operation' => $operation,
            'payload' => [
                'uuid' => $todo->uuid,
                'title' => $todo->title,
                'is_completed' => $todo->is_completed,
                'last_modified_at' => $todo->last_modified_at?->toIso8601String(),
                'deleted_at' => $todo->deleted_at?->toIso8601String(),
            ],
            'status' => 'pending',
            'available_at' => now(),
        ]);
    }
}