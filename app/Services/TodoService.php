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

        if ($title === '' || strlen($title) > 255) {
            throw new \InvalidArgumentException('Title must be 1–255 characters.');
        }

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

        $this->syncIfOnline($user);

        return $todo;
    }

    public function toggle(User $user, Todo $todo): Todo
    {
        abort_if($todo->user_id !== $user->id, 403);

        $todo = DB::transaction(function () use ($todo, $user) {
            $fresh = Todo::where('user_id', $todo->user_id)
                ->lockForUpdate()
                ->findOrFail($todo->id);

            $fresh->update([
                'is_completed' => ! $fresh->is_completed,
                'sync_status' => 'pending',
                'last_modified_at' => now(),
            ]);

            $this->queueSyncOperation($user, $fresh, 'updated');

            return $fresh->fresh();
        });

        $this->syncIfOnline($user);

        return $todo;
    }

    public function delete(User $user, Todo $todo): void
    {
        abort_if($todo->user_id !== $user->id, 403);

        DB::transaction(function () use ($todo) {
            $fresh = Todo::where('user_id', $todo->user_id)
                ->lockForUpdate()
                ->find($todo->id);

            if (! $fresh || $fresh->trashed()) {
                return;
            }

            SyncOperation::where('user_id', $fresh->user_id)
                ->where('entity_uuid', $fresh->uuid)
                ->whereIn('status', ['pending', 'failed'])
                ->whereIn('operation', ['created', 'updated'])
                ->update(['status' => 'cancelled']);

            $deletedAt = now();

            $fresh->update([
                'sync_status' => 'pending',
                'last_modified_at' => $deletedAt,
            ]);

            SyncOperation::create([
                'user_id' => $fresh->user_id,
                'entity_type' => 'todo',
                'entity_uuid' => $fresh->uuid,
                'operation' => 'deleted',
                'payload' => [
                    'uuid' => $fresh->uuid,
                    'title' => $fresh->title,
                    'is_completed' => $fresh->is_completed,
                    'last_modified_at' => $deletedAt->toIso8601String(),
                    'deleted_at' => $deletedAt->toIso8601String(),
                ],
                'status' => 'pending',
            ]);

            $fresh->delete();
        });

        $this->syncIfOnline($user);
    }

    protected function syncIfOnline(User $user): void
    {
        if (! $this->connectivityService->isOnline()) {
            return;
        }

        SyncPendingOperationsJob::dispatchSync($user->id);
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
        ]);
    }
}
