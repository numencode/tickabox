<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SyncOperationFactory extends Factory
{
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'user_id' => User::factory(),
            'entity_type' => 'todo',
            'entity_uuid' => $uuid,
            'operation' => 'created',
            'payload' => [
                'uuid' => $uuid,
                'title' => fake()->sentence(3),
                'is_completed' => false,
                'last_modified_at' => now()->toIso8601String(),
                'deleted_at' => null,
            ],
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'available_at' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state([
            'status' => 'failed',
            'attempts' => 1,
            'last_error' => 'Previous attempt failed.',
        ]);
    }

    public function forOperation(string $operation): static
    {
        return $this->state(['operation' => $operation]);
    }
}
