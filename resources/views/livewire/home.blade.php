<?php

use App\Jobs\SyncPendingOperationsJob;
use App\Models\SyncMeta;
use App\Models\SyncOperation;
use App\Models\Todo;
use App\Models\User;
use App\Services\ApiAuthService;
use App\Services\ConnectivityService;
use App\Services\TodoService;
use Illuminate\Support\Carbon;
use Livewire\Volt\Component;

new class extends Component {
    public string $mode = 'login';

    public string $name = '';
    public string $email = '';
    public string $password = '';

    public string $title = '';

    public ?int $userId = null;
    public ?string $errorMessage = null;
    public ?string $syncMessage = null;
    public bool $isSyncing = false;

    public array $network = [
        'connected' => false,
        'type' => 'unknown',
        'isExpensive' => false,
        'isConstrained' => false,
        'isVirtual' => false,
    ];

    public function mount(ConnectivityService $connectivityService): void
    {
        $this->refreshNetwork($connectivityService);

        $user = User::query()
            ->whereNotNull('remote_id')
            ->first();

        $this->userId = $user?->id;

        if ($user && $this->isOnline()) {
            $this->runSync();
        }
    }

    public function refreshNetwork(ConnectivityService $connectivityService): void
    {
        $this->network = $connectivityService->status();
    }

    public function user(): ?User
    {
        return $this->userId
            ? User::query()->find($this->userId)
            : null;
    }

    public function todos()
    {
        $user = $this->user();

        if (! $user) {
            return collect();
        }

        return Todo::query()
            ->where('user_id', $user->id)
            ->orderBy('is_completed')
            ->orderByDesc('created_at')
            ->get();
    }

    public function pendingSyncCount(): int
    {
        $user = $this->user();

        if (! $user) {
            return 0;
        }

        return SyncOperation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->count();
    }

    public function failedSyncCount(): int
    {
        $user = $this->user();

        if (! $user) {
            return 0;
        }

        return SyncOperation::query()
            ->where('user_id', $user->id)
            ->where('status', 'failed')
            ->count();
    }

    public function lastSyncAt(): ?string
    {
        $value = SyncMeta::getValue('last_pulled_at');

        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('d.m.Y H:i:s');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    public function isOnline(): bool
    {
        return (bool) ($this->network['connected'] ?? false);
    }

    public function networkType(): string
    {
        return (string) ($this->network['type'] ?? 'unknown');
    }

    public function networkLabel(): string
    {
        if (! $this->isOnline()) {
            return 'Offline';
        }

        return match ($this->networkType()) {
            'wifi' => 'Online · Wi-Fi',
            'cellular' => 'Online · Cellular',
            'ethernet' => 'Online · Ethernet',
            'emulator' => 'Online · Emulator',
            default => 'Online',
        };
    }

    public function networkSubLabel(): string
    {
        if (($this->network['isVirtual'] ?? false) === true) {
            return 'Connectivity check is bypassed on the emulator.';
        }

        if (! $this->isOnline()) {
            return 'Changes are saved locally and will sync when connection is available.';
        }

        if (($this->network['isExpensive'] ?? false) === true) {
            return 'Metered connection detected.';
        }

        return 'Ready to sync.';
    }

    public function syncState(): string
    {
        if (! $this->isOnline()) {
            return 'offline';
        }

        if ($this->isSyncing) {
            return 'syncing';
        }

        if ($this->failedSyncCount() > 0) {
            return 'failed';
        }

        if ($this->pendingSyncCount() > 0) {
            return 'pending';
        }

        if ($this->lastSyncAt()) {
            return 'synced';
        }

        return 'idle';
    }

    public function syncLabel(): string
    {
        return match ($this->syncState()) {
            'offline' => 'Offline',
            'syncing' => 'Syncing…',
            'failed' => 'Sync failed',
            'pending' => 'Pending sync',
            'synced' => 'Synced',
            default => 'Not synced yet',
        };
    }

    public function syncSubLabel(): string
    {
        if (! $this->isOnline()) {
            return 'Tasks are stored locally until you reconnect.';
        }

        if ($this->failedSyncCount() > 0) {
            return $this->failedSyncCount() . ' failed operation(s)';
        }

        if ($this->pendingSyncCount() > 0) {
            return $this->pendingSyncCount() . ' pending operation(s)';
        }

        if ($this->lastSyncAt()) {
            return 'Last sync: ' . $this->lastSyncAt();
        }

        return 'No sync activity yet';
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['login', 'register'], true) ? $mode : 'login';
        $this->errorMessage = null;
    }

    public function register(ApiAuthService $authService, ConnectivityService $connectivityService): void
    {
        $this->errorMessage = null;

        try {
            $user = $authService->register(
                trim($this->name),
                trim($this->email),
                $this->password
            );

            $this->userId = $user->id;
            $this->resetAuthForm();
            $this->refreshNetwork($connectivityService);

            if ($this->isOnline()) {
                $this->runSync();
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Registration failed. Please check your details and try again.';
        }
    }

    public function login(ApiAuthService $authService, ConnectivityService $connectivityService): void
    {
        $this->errorMessage = null;

        try {
            $user = $authService->login(
                trim($this->email),
                $this->password
            );

            $this->userId = $user->id;
            $this->resetAuthForm();
            $this->refreshNetwork($connectivityService);

            if ($this->isOnline()) {
                $this->runSync();
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Login failed. Please check your email and password.';
        }
    }

    public function logout(ApiAuthService $authService): void
    {
        $user = $this->user();

        if ($user) {
            $authService->logout($user);
        }

        $this->userId = null;
        $this->title = '';
        $this->errorMessage = null;
        $this->syncMessage = null;
        $this->password = '';
    }

    public function addTodo(TodoService $todoService, ConnectivityService $connectivityService): void
    {
        $user = $this->user();

        if (! $user) {
            return;
        }

        if (trim($this->title) === '') {
            return;
        }

        $todoService->create($user, $this->title);
        $this->title = '';
        $this->refreshNetwork($connectivityService);

        $this->syncMessage = $this->isOnline()
            ? 'Task saved and sync triggered.'
            : 'Task saved locally. It will sync when you are online.';
    }

    public function toggleTodo(int $id, TodoService $todoService, ConnectivityService $connectivityService): void
    {
        $user = $this->user();

        if (! $user) {
            return;
        }

        $todo = Todo::query()
            ->where('user_id', $user->id)
            ->findOrFail($id);

        $todoService->toggle($todo);
        $this->refreshNetwork($connectivityService);

        $this->syncMessage = $this->isOnline()
            ? 'Task updated and sync triggered.'
            : 'Task updated locally. It will sync when you are online.';
    }

    public function deleteTodo(int $id, TodoService $todoService, ConnectivityService $connectivityService): void
    {
        $user = $this->user();

        if (! $user) {
            return;
        }

        $todo = Todo::query()
            ->where('user_id', $user->id)
            ->findOrFail($id);

        $todoService->delete($todo);
        $this->refreshNetwork($connectivityService);

        $this->syncMessage = $this->isOnline()
            ? 'Task removed and sync triggered.'
            : 'Task removed locally. It will sync when you are online.';
    }

    public function syncNow(ConnectivityService $connectivityService): void
    {
        $this->refreshNetwork($connectivityService);

        if (! $this->isOnline()) {
            $this->syncMessage = 'You are offline. Reconnect to sync changes.';
            return;
        }

        $this->runSync();
        $this->syncMessage = 'Manual sync completed.';
    }

    protected function runSync(): void
    {
        if (! $this->isOnline()) {
            return;
        }

        $this->isSyncing = true;

        try {
            SyncPendingOperationsJob::dispatchSync();
        } finally {
            $this->isSyncing = false;
        }
    }

    protected function resetAuthForm(): void
    {
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->errorMessage = null;
    }
};
?>

<div class="tk-app-shell">
    <div class="tk-bg-orb tk-bg-orb--one"></div>
    <div class="tk-bg-orb tk-bg-orb--two"></div>

    <main class="tk-app">
        @if (! $this->user())
            <section class="tk-auth-card">
                <div class="tk-brand">
                    <div class="tk-brand__mark">
                        <span></span>
                    </div>

                    <div>
                        <h1 class="tk-brand__title">Tickabox</h1>
                        <p class="tk-brand__subtitle">Simple tasks, synced when you're online.</p>
                    </div>
                </div>

                <div class="tk-auth-switch">
                    <button
                            type="button"
                            wire:click="setMode('login')"
                            class="tk-auth-switch__button {{ $mode === 'login' ? 'is-active' : '' }}"
                    >
                        Login
                    </button>

                    <button
                            type="button"
                            wire:click="setMode('register')"
                            class="tk-auth-switch__button {{ $mode === 'register' ? 'is-active' : '' }}"
                    >
                        Register
                    </button>
                </div>

                @if ($errorMessage)
                    <div class="tk-alert">
                        {{ $errorMessage }}
                    </div>
                @endif

                <div class="tk-form">
                    @if ($mode === 'register')
                        <div class="tk-field">
                            <label class="tk-label">Name</label>
                            <input wire:model="name" type="text" class="tk-input" placeholder="Your name">
                        </div>
                    @endif

                    <div class="tk-field">
                        <label class="tk-label">Email</label>
                        <input wire:model="email" type="email" class="tk-input" placeholder="you@example.com">
                    </div>

                    <div class="tk-field">
                        <label class="tk-label">Password</label>
                        <input
                                wire:model="password"
                                type="password"
                                class="tk-input"
                                placeholder="••••••••"
                                wire:keydown.enter="{{ $mode === 'login' ? 'login' : 'register' }}"
                        >
                    </div>

                    @if ($mode === 'login')
                        <button type="button" wire:click="login" class="tk-button tk-button--primary">
                            Login
                        </button>
                    @else
                        <button type="button" wire:click="register" class="tk-button tk-button--primary">
                            Create account
                        </button>
                    @endif
                </div>
            </section>
        @else
            <section class="tk-panel">
                <header class="tk-header">
                    <div class="tk-brand tk-brand--compact">
                        <div class="tk-brand__mark">
                            <span></span>
                        </div>

                        <div>
                            <h1 class="tk-brand__title">Tickabox</h1>
                            <p class="tk-brand__subtitle">
                                {{ $this->user()->name }} · {{ $this->user()->email }}
                            </p>
                        </div>
                    </div>

                    <button wire:click="logout" class="tk-button tk-button--ghost" type="button">
                        Logout
                    </button>
                </header>

                <section class="tk-network-bar tk-network-bar--{{ $this->isOnline() ? 'online' : 'offline' }}">
                    <div class="tk-network-indicator">
                        <span class="tk-network-dot"></span>

                        <div class="tk-network-text">
                            <strong>{{ $this->networkLabel() }}</strong>
                            <span>{{ $this->networkSubLabel() }}</span>
                        </div>
                    </div>
                </section>

                <section class="tk-sync-bar">
                    <div class="tk-sync-indicator tk-sync-indicator--{{ $this->syncState() }}">
                        <span class="tk-sync-dot"></span>

                        <div class="tk-sync-text">
                            <strong>{{ $this->syncLabel() }}</strong>
                            <span>{{ $this->syncSubLabel() }}</span>
                        </div>
                    </div>

                    <button
                            wire:click="syncNow"
                            class="tk-button tk-button--secondary"
                            type="button"
                            @disabled($isSyncing || ! $this->isOnline())
                    >
                        {{ $isSyncing ? 'Syncing…' : 'Sync now' }}
                    </button>
                </section>

                @if ($syncMessage)
                    <div class="tk-note">
                        {{ $syncMessage }}
                    </div>
                @endif

                <section class="tk-composer">
                    <input
                            wire:model="title"
                            wire:keydown.enter="addTodo"
                            type="text"
                            placeholder="Add a task..."
                            class="tk-input tk-input--composer"
                    >

                    <button wire:click="addTodo" class="tk-button tk-button--primary" type="button">
                        Add
                    </button>
                </section>

                <section class="tk-list-wrap">
                    <div class="tk-list-head">
                        <h2 class="tk-section-title">Your tasks</h2>
                        <span class="tk-badge">{{ $this->todos()->count() }}</span>
                    </div>

                    <ul class="tk-list">
                        @forelse ($this->todos() as $item)
                            <li wire:key="todo-{{ $item->id }}" class="tk-item {{ $item->is_completed ? 'is-complete' : '' }}">
                                <label class="tk-item__main">
                                    <input
                                            type="checkbox"
                                            class="tk-checkbox"
                                            @checked($item->is_completed)
                                            wire:click="toggleTodo({{ $item->id }})"
                                    >

                                    <span class="tk-item__text">
                                        {{ $item->title }}
                                    </span>
                                </label>

                                <button wire:click="deleteTodo({{ $item->id }})" class="tk-delete" type="button">
                                    Delete
                                </button>
                            </li>
                        @empty
                            <li class="tk-empty">
                                No tasks yet. Add your first one above.
                            </li>
                        @endforelse
                    </ul>
                </section>
            </section>
        @endif
    </main>
</div>