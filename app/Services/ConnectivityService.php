<?php

namespace App\Services;

use Native\Mobile\Facades\Device;
use Native\Mobile\Facades\Network;

class ConnectivityService
{
    public function isVirtualDevice(): bool
    {
        try {
            $result = json_decode(Device::getInfo(), true);

            return (bool) ($result['isVirtual'] ?? false);
        } catch (\Throwable $e) {
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

            return [
                'connected' => (bool) ($status->connected ?? false),
                'type' => (string) ($status->type ?? 'unknown'),
                'isExpensive' => (bool) ($status->isExpensive ?? false),
                'isConstrained' => (bool) ($status->isConstrained ?? false),
                'isVirtual' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'type' => 'unknown',
                'isExpensive' => false,
                'isConstrained' => false,
                'isVirtual' => false,
            ];
        }
    }

    public function isOnline(): bool
    {
        return (bool) ($this->status()['connected'] ?? false);
    }
}
