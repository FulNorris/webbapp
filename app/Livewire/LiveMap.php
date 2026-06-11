<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function refreshLocations(): void
    {
        $this->locations = $this->activeLocations();
        $this->drivers = $this->driverRows();

        $this->dispatch(
            'live-map-refreshed',
            locations: array_values($this->locations),
            drivers: array_values($this->drivers),
        );
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
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'current_location')) {
            return [];
        }

        $columns = $this->availableColumns('orders', [
            'id',
            'mottagare',
            'recipient_name',
            'adress',
            'delivery_address',
            'assigned_driver_id',
            'driver_id',
            'driver_name',
            'tracking_enabled',
            'current_location',
            'updated_at',
        ]);

        if (! in_array('id', $columns, true)) {
            return [];
        }

        $query = DB::table('orders')
            ->select($columns)
            ->whereNotNull('current_location');

        if (Schema::hasColumn('orders', 'tracking_enabled')) {
            $query->where('tracking_enabled', true);
        }

        if (Schema::hasColumn('orders', 'updated_at')) {
            $query->orderByDesc('updated_at');
        }

        $orderLocations = $query->get()
            ->mapWithKeys(function ($order) {
                $location = $this->decodeJson($order->current_location);
                $lat = $this->coordinate($location, ['lat', 'latitude']);
                $lng = $this->coordinate($location, ['lng', 'longitude']);

                return [$order->id => [
                    'orderId' => $order->id,
                    'recipientName' => $order->recipient_name ?? $order->mottagare ?? 'Leverans',
                    'deliveryAddress' => $order->delivery_address ?? $order->adress ?? '',
                    'driverId' => $order->driver_id ?? $order->assigned_driver_id ?? null,
                    'driverName' => ($order->driver_name ?? null) ?: ($order->assigned_driver_id ?? null),
                    'lat' => $lat,
                    'lng' => $lng,
                    'accuracy' => $location['accuracy'] ?? null,
                    'speed' => $location['speed'] ?? null,
                    'heading' => $location['heading'] ?? null,
                    'trackingEnabled' => (bool) ($order->tracking_enabled ?? true),
                    'updatedAt' => $location['updatedAt'] ?? $order->updated_at ?? now()->toIso8601String(),
                ]];
            })
            ->filter(fn ($row) => $row['lat'] !== null && $row['lng'] !== null)
            ->all();

        return array_replace($orderLocations, $this->userLocations());
    }

    private function userLocations(): array
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'current_location')) {
            return [];
        }

        $columns = $this->availableColumns('users', [
            'id',
            'email',
            'first_name',
            'last_name',
            'role',
            'visibility',
            'current_location',
            'location_updated_at',
            'last_seen_at',
        ]);

        if (! in_array('id', $columns, true) || ! in_array('current_location', $columns, true)) {
            return [];
        }

        $query = DB::table('users')
            ->select($columns)
            ->where('visibility', 'online')
            ->whereNotNull('current_location');

        if (Schema::hasColumn('users', 'location_updated_at')) {
            $query->where('location_updated_at', '>=', now()->subMinutes(5))
                ->orderByDesc('location_updated_at');
        }

        return $query->get()
            ->mapWithKeys(function ($user) {
                $location = $this->decodeJson($user->current_location);
                $lat = $this->coordinate($location, ['lat', 'latitude']);
                $lng = $this->coordinate($location, ['lng', 'longitude']);
                $driverName = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->email ?? 'Förare');

                return ['driver-'.$user->id => [
                    'type' => 'driver',
                    'orderId' => 'driver-'.$user->id,
                    'recipientName' => $driverName,
                    'deliveryAddress' => 'Synlig online',
                    'driverId' => $user->id,
                    'driverName' => $driverName,
                    'lat' => $lat,
                    'lng' => $lng,
                    'accuracy' => $location['accuracy'] ?? null,
                    'speed' => $location['speed'] ?? null,
                    'heading' => $location['heading'] ?? null,
                    'trackingEnabled' => true,
                    'updatedAt' => $location['updatedAt'] ?? $user->location_updated_at ?? $user->last_seen_at ?? now()->toIso8601String(),
                ]];
            })
            ->filter(fn ($row) => $row['lat'] !== null && $row['lng'] !== null)
            ->all();
    }

    private function driverRows(): array
    {
        if (! Schema::hasTable('users')) {
            return [];
        }

        $columns = $this->availableColumns('users', [
            'id',
            'email',
            'first_name',
            'last_name',
            'role',
            'visibility',
            'last_seen_at',
        ]);

        if (! in_array('id', $columns, true)) {
            return [];
        }

        $query = DB::table('users')->select($columns);

        if (Schema::hasColumn('users', 'role')) {
            $query->whereIn('role', ['owner', 'firmatecknare', 'manager', 'arbetsledare', 'admin', 'driver', 'forare']);
        }

        foreach (['first_name', 'last_name', 'email'] as $orderColumn) {
            if (Schema::hasColumn('users', $orderColumn)) {
                $query->orderBy($orderColumn);
            }
        }

        return $query->get()
            ->mapWithKeys(fn ($user) => [$user->id => [
                'driverId' => $user->id,
                'driverName' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->email ?? 'Förare'),
                'visibility' => $user->visibility ?? 'offline',
                'lastSeenAt' => $user->last_seen_at ?? null,
            ]])
            ->all();
    }

    private function availableColumns(string $table, array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column),
        ));
    }

    private function coordinate(array $location, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($location[$key]) && is_numeric($location[$key])) {
                return (float) $location[$key];
            }
        }

        return null;
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
