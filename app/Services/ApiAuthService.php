<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;

class ApiAuthService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.tickabox_api.base_url'), '/');
    }

    public function register(string $name, string $email, string $password): User
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('/api/register', [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ])
            ->throw()
            ->json();

        return $this->persistAuthenticatedUser($response);
    }

    public function login(string $email, string $password): User
    {
        $response = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->post('/api/login', [
                'email' => $email,
                'password' => $password,
            ])
            ->throw()
            ->json();

        return $this->persistAuthenticatedUser($response);
    }

    public function logout(User $user): void
    {
        $token = SecureStorage::get('sanctum_token') ?: $user->sanctum_token;

        if ($token) {
            Http::baseUrl($this->baseUrl)
                ->acceptJson()
                ->withToken($token)
                ->post('/api/logout')
                ->throw();
        }

        SecureStorage::delete('sanctum_token');

        $user->update([
            'remote_id' => null,
            'sanctum_token' => null,
        ]);
    }

    protected function persistAuthenticatedUser(array $response): User
    {
        $userData = $response['user'];
        $token = $response['token'];

        logger()->info('AUTH: before secure storage set', [
            'token_prefix' => substr($token, 0, 8),
            'token_length' => strlen($token),
        ]);

        SecureStorage::set('sanctum_token', $token);

        $storedToken = SecureStorage::get('sanctum_token');

        logger()->info('AUTH: after secure storage set', [
            'stored' => ! empty($storedToken),
            'stored_prefix' => $storedToken ? substr($storedToken, 0, 8) : null,
        ]);

        return User::query()->updateOrCreate(
            ['email' => $userData['email']],
            [
                'remote_id' => $userData['id'],
                'name' => $userData['name'],
                'password' => null,
                'sanctum_token' => $token,
            ]
        );
    }
}
