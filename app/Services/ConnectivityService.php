<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Facades\Device;
use Native\Mobile\Facades\Network;

class ConnectivityService
{
    public function isVirtualDevice(): bool
    {
        try {
            $result = json_decode(Device::getInfo(), true, 512, JSON_THROW_ON_ERROR);

            return (bool) ($result['isVirtual'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('ConnectivityService: could not determine device type.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function status(): array
    {
        if ($this->isVirtualDevice()) {
            return [
                'connected' => true,
                'type' => 'emulator',
                'isExpensive' => false,
                'isConstrained' => false,
                'isVirtual' => true,
            ];
        }

        try {
            $status = Network::status();

            $connected = (bool) ($status->connected ?? false);

            // Fallback if plugin says we are offline but internet might be available
            if (! $connected && $this->canReachInternet()) {
                Log::debug('ConnectivityService: Network plugin reported offline, but internet is reachable.');
                $connected = true;
            }

            return [
                'connected' => $connected,
                'type' => (string) ($status->type ?? 'unknown'),
                'isExpensive' => (bool) ($status->isExpensive ?? false),
                'isConstrained' => (bool) ($status->isConstrained ?? false),
                'isVirtual' => false,
            ];
        } catch (\Throwable $e) {
            Log::error('ConnectivityService error: '.$e->getMessage());

            $canReach = $this->canReachInternet();
            Log::debug('ConnectivityService: Plugin failed, manual reach check: '.($canReach ? 'online' : 'offline'));

            return [
                'connected' => $canReach,
                'type' => 'unknown',
                'isExpensive' => false,
                'isConstrained' => false,
                'isVirtual' => false,
            ];
        }
    }

    protected function canReachInternet(): bool
    {
        try {
            // We check the API URL specifically as it's what matters for syncing
            $url = rtrim(config('services.tickabox_api.base_url'), '/');

            if (! $url) {
                return false;
            }

            $response = Http::timeout(2)->get($url.'/up');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::debug('ConnectivityService: internet reachability check failed.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function isOnline(): bool
    {
        return (bool) ($this->status()['connected'] ?? false);
    }
}
