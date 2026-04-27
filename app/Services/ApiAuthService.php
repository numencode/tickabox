<?php

namespace App\Services;

use App\Models\SyncMeta;
use App\Models\SyncOperation;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            ->timeout(config('services.tickabox_api.auth_timeout', 15))
            ->connectTimeout(config('services.tickabox_api.connect_timeout', 5))
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
            ->timeout(config('services.tickabox_api.auth_timeout', 15))
            ->connectTimeout(config('services.tickabox_api.connect_timeout', 5))
            ->acceptJson()
            ->post('/api/login', [
                'email' => $email,
                'password' => $password,
            ])
            ->throw()
            ->json();

        return $this->persistAuthenticatedUser($response);
    }

    public function logoutAllDevices(User $user): void
    {
        $this->logout($user, '/api/logout/all');
    }

    public function logout(User $user, string $endpoint = '/api/logout'): void
    {
        try {
            $token = SecureStorage::get('sanctum_token') ?: $user->sanctum_token;
        } catch (\Throwable) {
            $token = $user->sanctum_token;
        }

        if ($token) {
            try {
                Http::baseUrl($this->baseUrl)
                    ->timeout(15)
                    ->connectTimeout(5)
                    ->acceptJson()
                    ->withToken($token)
                    ->post($endpoint);
            } catch (\Throwable $e) {
                Log::warning('ApiAuthService: remote logout failed, proceeding with local cleanup.', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        SyncOperation::where('user_id', $user->id)->delete();
        SyncMeta::deleteForUser($user->id);
        Todo::where('user_id', $user->id)->forceDelete();

        try {
            SecureStorage::delete('sanctum_token');
        } catch (\Throwable) {
            // SecureStorage plugin unavailable; DB column cleared below
        }

        $user->update([
            'remote_id' => null,
            'sanctum_token' => null,
        ]);
    }

    protected function persistAuthenticatedUser(array $response): User
    {
        if (empty($response['token']) || ! is_array($response['user'] ?? null)) {
            throw new \UnexpectedValueException('Auth API response missing token or user.');
        }

        $userData = $response['user'];

        if (empty($userData['id']) || empty($userData['email']) || empty($userData['name'])) {
            throw new \UnexpectedValueException('Auth API response has incomplete user data.');
        }

        $token = $response['token'];

        try {
            SecureStorage::set('sanctum_token', $token);
        } catch (\Throwable) {
            Log::warning('ApiAuthService: SecureStorage unavailable, token stored in local DB only.');
        }

        return User::query()->updateOrCreate(
            ['email' => $userData['email']],
            [
                'remote_id' => $userData['id'],
                'name' => $userData['name'],
                'sanctum_token' => $token,
            ]
        );
    }
}
