<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TodoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'is_completed' => false,
            'sync_status' => 'synced',
            'last_modified_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
        ];
    }

    public function completed(): static
    {
        return $this->state(['is_completed' => true]);
    }

    public function pending(): static
    {
        return $this->state(['sync_status' => 'pending']);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }
}
