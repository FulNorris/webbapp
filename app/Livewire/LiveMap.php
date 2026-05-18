<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class LiveMap extends Component
{
    public array $locations = [];
    public array $drivers = [];

    public function mount(): void
    {
        $this->locations = $this->activeLocations();
        $this->drivers = $this->driverRows();
    }

    #[On('echo:live-map,LocationUpdated')]
    public function onLocationUpdated(array $payload): void
    {
        if (! empty($payload['orderId'])) {
            if (($payload['trackingEnabled'] ?? true) === false) {
                unset($this->locations[$payload['orderId']]);
            } else {
                $this->locations[$payload['orderId']] = $payload;
            }
        }

        $this->dispatch('location-updated', payload: $payload);
    }

    #[On('echo:live-map,DriverVisibilityUpdated')]
    public function onDriverVisibilityUpdated(array $payload): void
    {
        if (! empty($payload['driverId'])) {
            $this->drivers[$payload['driverId']] = $payload;
        }

        $this->dispatch('driver-visibility-updated', payload: $payload);
    }

    public function render()
    {
        return view('livewire.live-map');
    }

    private function activeLocations(): array
    {
        return DB::table('orders')
            ->where('tracking_enabled', true)
            ->whereNotNull('current_location')
            ->orderByDesc('updated_at')
            ->get()
            ->mapWithKeys(function ($order) {
                $location = $this->decodeJson($order->current_location);

                return [$order->id => [
                    'orderId' => $order->id,
                    'recipientName' => $order->mottagare,
                    'deliveryAddress' => $order->adress,
                    'driverId' => $order->driver_id,
                    'driverName' => $order->driver_name ?: $order->assigned_driver_id,
                    'lat' => (float) ($location['lat'] ?? $location['latitude'] ?? 0),
                    'lng' => (float) ($location['lng'] ?? $location['longitude'] ?? 0),
                    'accuracy' => $location['accuracy'] ?? null,
                    'speed' => $location['speed'] ?? null,
                    'heading' => $location['heading'] ?? null,
                    'trackingEnabled' => true,
                    'updatedAt' => $location['updatedAt'] ?? $order->updated_at,
                ]];
            })
            ->filter(fn ($row) => $row['lat'] && $row['lng'])
            ->all();
    }

    private function driverRows(): array
    {
        return DB::table('users')
            ->whereIn('role', ['owner', 'manager', 'admin', 'driver'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn ($user) => [$user->id => [
                'driverId' => $user->id,
                'driverName' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->email,
                'visibility' => $user->visibility ?? 'offline',
                'lastSeenAt' => $user->last_seen_at,
            ]])
            ->all();
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
