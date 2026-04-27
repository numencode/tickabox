<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'remote_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'sanctum_token' => null,
            'is_active' => true,
        ];
    }

    public function withToken(string $token = 'test-token'): static
    {
        return $this->state(['sanctum_token' => $token]);
    }

    public function withRemoteId(int $remoteId = 1): static
    {
        return $this->state(['remote_id' => $remoteId]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
