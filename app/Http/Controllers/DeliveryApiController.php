<?php

namespace App\Http\Controllers;

use App\Events\DriverVisibilityUpdated;
use App\Events\LocationUpdated;
use App\Services\WebPushNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeliveryApiController extends Controller
{
    private array $roles = ['firmatecknare', 'admin', 'arbetsledare', 'personal', 'support', 'forare', 'viewer', 'kund'];

    private array $roleAliases = [
        'owner' => 'firmatecknare',
        'firmatecknare' => 'firmatecknare',
        'admin' => 'admin',
        'manager' => 'arbetsledare',
        'supervisor' => 'arbetsledare',
        'arbetsledare' => 'arbetsledare',
        'worker' => 'personal',
        'personal' => 'personal',
        'support' => 'support',
        'driver' => 'forare',
        'forare' => 'forare',
        'förare' => 'forare',
        'viewer' => 'viewer',
        'kund' => 'kund',
        'customer' => 'kund',
    ];

    private array $roleLabels = [
        'firmatecknare' => 'Firmatecknare',
        'admin' => 'Admin',
        'arbetsledare' => 'Arbetsledare / Driftansvarig',
        'personal' => 'Personal / Kontor',
        'support' => 'Support',
        'forare' => 'Förare',
        'viewer' => 'Läsbehörighet',
        'kund' => 'Kund / Extern mottagare',
    ];

    private function json($data, int $status = 200)
    {
        return response()->json($data, $status);
    }

    private function decodeJson($value, $default = null)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_array($value) || is_object($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function normalizeStatus($status, $deliveredAt = null): string
    {
        if ($deliveredAt) {
            return 'delivered';
        }

        return match (strtolower(trim((string) $status))) {
            'ongoing', 'started', 'på_väg', 'pa_vag' => 'ongoing',
            'paused', 'pausad' => 'paused',
            'delivered', 'levererad' => 'delivered',
            'cancelled', 'canceled', 'avbruten' => 'cancelled',
            'assigned' => 'assigned',
            default => 'created',
        };
    }

    private function passwordHashForPhp(string $hash): string
    {
        if (str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
            return '$2y$'.substr($hash, 4);
        }

        return $hash;
    }

    private function verifyPassword(string $plainPassword, ?string $passwordHash): bool
    {
        if ($plainPassword === '' || ! $passwordHash) {
            return false;
        }

        if (password_verify($plainPassword, $this->passwordHashForPhp($passwordHash))) {
            return true;
        }

        try {
            return Hash::check($plainPassword, $passwordHash);
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function userFromToken(Request $request)
    {
        $header = $request->header('Authorization', '');
        if (! preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return null;
        }

        $token = DB::table('api_tokens')
            ->where('token', $matches[1])
            ->where('type', 'access')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        return $token ? DB::table('users')->where('id', $token->user_id)->first() : null;
    }

    private function requireUser(Request $request)
    {
        $user = $this->userFromToken($request);
        abort_if(! $user, 401, 'Ogiltig eller saknad token');

        return $user;
    }

    private function normalizedRole(?string $role): string
    {
        $key = Str::of((string) $role)->lower()->ascii()->replace(' ', '_')->toString();

        return $this->roleAliases[$key] ?? (in_array($key, $this->roles, true) ? $key : 'viewer');
    }

    private function roleLabel(?string $role): string
    {
        return $this->roleLabels[$this->normalizedRole($role)] ?? (string) $role;
    }

    private function allPermissions(): array
    {
        return [
            'users.view', 'users.create', 'users.update', 'users.delete', 'users.change_password', 'users.change_role',
            'roles.view', 'roles.update',
            'deliveries.view_all', 'deliveries.view_own', 'deliveries.create', 'deliveries.update', 'deliveries.delete', 'deliveries.assign_driver', 'deliveries.update_status',
            'drivers.view', 'drivers.create', 'drivers.update', 'drivers.delete',
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'articles.view', 'articles.create', 'articles.update', 'articles.delete',
            'work_orders.view', 'work_orders.create', 'work_orders.update', 'work_orders.delete',
            'tracking.view', 'tracking.create', 'tracking.update', 'tracking.send',
            'settings.view', 'settings.update',
            'logs.view', 'logs.export', 'logs.security_view',
            'system.view_status', 'system.manage_api', 'system.full_access',
        ];
    }

    private function permissionMatrix(): array
    {
        return [
            'admin' => [
                'users.view', 'users.create', 'users.update', 'users.delete', 'users.change_password', 'users.change_role',
                'roles.view', 'roles.update',
                'deliveries.view_all', 'deliveries.create', 'deliveries.update', 'deliveries.delete', 'deliveries.assign_driver', 'deliveries.update_status',
                'drivers.view', 'drivers.create', 'drivers.update', 'drivers.delete',
                'customers.view', 'customers.create', 'customers.update', 'customers.delete',
                'articles.view', 'articles.create', 'articles.update', 'articles.delete',
                'work_orders.view', 'work_orders.create', 'work_orders.update', 'work_orders.delete',
                'tracking.view', 'tracking.create', 'tracking.update', 'tracking.send',
                'settings.view', 'settings.update',
                'logs.view', 'logs.export',
                'system.view_status', 'system.manage_api',
            ],
            'arbetsledare' => [
                'deliveries.view_all', 'deliveries.create', 'deliveries.update', 'deliveries.assign_driver', 'deliveries.update_status',
                'drivers.view', 'customers.view', 'customers.create', 'customers.update',
                'articles.view', 'articles.create', 'articles.update',
                'work_orders.view', 'work_orders.create', 'work_orders.update',
                'tracking.view', 'tracking.create', 'tracking.send',
                'logs.view',
            ],
            'personal' => [
                'deliveries.view_all', 'deliveries.create', 'deliveries.update',
                'customers.view', 'customers.create', 'customers.update',
                'articles.view', 'work_orders.view', 'work_orders.create', 'tracking.view',
            ],
            'support' => [
                'users.view', 'deliveries.view_all', 'drivers.view', 'customers.view', 'articles.view',
                'work_orders.view', 'tracking.view', 'logs.view', 'system.view_status',
            ],
            'forare' => ['deliveries.view_own', 'deliveries.update_status', 'tracking.view'],
            'viewer' => ['deliveries.view_all', 'customers.view', 'articles.view', 'work_orders.view', 'tracking.view'],
            'kund' => ['tracking.view'],
        ];
    }

    private function permissionsFor(?string $role): array
    {
        $normalized = $this->normalizedRole($role);
        $permissions = array_fill_keys($this->allPermissions(), false);

        if ($normalized === 'firmatecknare') {
            return array_fill_keys($this->allPermissions(), true);
        }

        foreach ($this->permissionMatrix()[$normalized] ?? [] as $permission) {
            $permissions[$permission] = true;
        }

        return $permissions;
    }

    private function hasPermission($user, string $permission): bool
    {
        $permissions = $this->permissionsFor($user->role ?? null);

        return (bool) ($permissions['system.full_access'] ?? false) || (bool) ($permissions[$permission] ?? false);
    }

    private function requirePermission(Request $request, string $permission)
    {
        $user = $this->requireUser($request);
        abort_unless($this->hasPermission($user, $permission), 403, 'Saknar behörighet');

        return $user;
    }

    private function requireAnyPermission(Request $request, array $permissions)
    {
        $user = $this->requireUser($request);
        $allowed = collect($permissions)->contains(fn (string $permission) => $this->hasPermission($user, $permission));
        abort_unless($allowed, 403, 'Saknar behörighet');

        return $user;
    }

    private function roleOptions(): array
    {
        return collect($this->roles)
            ->map(fn (string $role) => ['label' => $this->roleLabels[$role], 'value' => $role])
            ->values()
            ->all();
    }

    private function roleMatrix(): array
    {
        return collect($this->roles)
            ->map(function (string $role, int $index) {
                $permissions = $this->permissionsFor($role);

                return [
                    'role' => $role,
                    'label' => $this->roleLabels[$role],
                    'level' => $index + 1,
                    'permissions' => $permissions,
                    'allowedPermissions' => array_values(array_filter(array_keys($permissions), fn (string $permission) => $permissions[$permission])),
                ];
            })
            ->values()
            ->all();
    }

    private function allowedRoleInputs(): array
    {
        return array_values(array_unique([...$this->roles, ...array_keys($this->roleAliases)]));
    }

    private function guardUserMutation($actor, $target, ?string $newRole = null): void
    {
        $actorIsFirmatecknare = $this->hasPermission($actor, 'system.full_access');
        $targetIsFirmatecknare = $this->normalizedRole($target->role ?? null) === 'firmatecknare';
        $newRoleIsFirmatecknare = $newRole === 'firmatecknare';

        abort_if(($targetIsFirmatecknare || $newRoleIsFirmatecknare) && ! $actorIsFirmatecknare, 403, 'Endast firmatecknare får ändra firmatecknare.');
    }

    private function guardOrderAccess($user, $order): void
    {
        if ($this->hasPermission($user, 'deliveries.view_all')) {
            return;
        }

        abort_unless(
            ($order->driver_id ?? null) === $user->id || ($order->assigned_driver_id ?? null) === $user->id,
            403,
            'Du har inte åtkomst till den leveransen.'
        );
    }

    private function publicUser($user)
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'email' => $user->email,
            'emailKey' => $user->email_key,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'role' => $user->role,
            'roleKey' => $this->normalizedRole($user->role),
            'roleLabel' => $this->roleLabel($user->role),
            'permissions' => $this->permissionsFor($user->role),
            'active' => (bool) $user->active,
            'phone' => $user->phone,
            'imagePath' => $user->image_path,
            'photoUrl' => $user->photo_url,
            'personId' => $user->person_id,
            'visibility' => $user->visibility,
            'lastSeenAt' => $user->last_seen_at,
            'isFirstLogin' => (bool) $user->is_first_login,
            'createdAt' => $user->created_at,
            'updatedAt' => $user->updated_at,
        ];
    }

    private function productForArticle(?string $article): ?object
    {
        $article = trim((string) $article);
        if ($article === '' || ! Schema::hasTable('products')) {
            return null;
        }

        $fallback = null;

        foreach ($this->productSkuCandidates($article) as $candidate) {
            $product = DB::table('products')
                ->whereRaw('lower(sku) = ?', [$candidate])
                ->first();

            if (! $product) {
                continue;
            }

            if ($this->productHasImage($product)) {
                return $product;
            }

            $fallback ??= $product;
        }

        foreach ($this->productSkuCandidates($article) as $candidate) {
            if (mb_strlen($candidate, 'UTF-8') < 3) {
                continue;
            }

            $product = DB::table('products')
                ->whereRaw('lower(title) like ?', ['%'.$candidate.'%'])
                ->where(function ($query) {
                    $query->whereNotNull('primary_image_path')
                        ->orWhereNotNull('primary_image_url');
                })
                ->orderBy('sku')
                ->first();

            if ($product) {
                return $product;
            }
        }

        return $fallback;
    }

    private function productSkuCandidates(string $article): array
    {
        $key = mb_strtolower(trim($article), 'UTF-8');
        $firstToken = mb_strtolower(strtok($article, " \t\r\n-") ?: $article, 'UTF-8');
        $compact = preg_replace('/[^a-z0-9]/', '', $key) ?: '';
        $candidates = [$key, $firstToken, $compact];

        if (preg_match('/^tlp?(\d+)/', $compact, $matches)) {
            $digits = $matches[1];
            $listNumber = substr($digits, 0, 2);
            array_push($candidates, 'sl'.$digits, 'sl'.$listNumber, 'tl'.$digits);

            if (str_starts_with($compact, 'tlp')) {
                array_push($candidates, 'tlp'.$digits, 'rp7-'.$digits.'0', 'rp7'.$digits.'0');
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function productHasImage(object $product): bool
    {
        return filled($product->primary_image_path ?? null) || filled($product->primary_image_url ?? null);
    }

    private function quantityValue($value): int
    {
        if (preg_match('/\d+(?:[,.]\d+)?/', (string) $value, $matches)) {
            return max(0, (int) ceil((float) str_replace(',', '.', $matches[0])));
        }

        return 0;
    }

    private function imageUrl(?string $imagePath): ?string
    {
        $imagePath = trim((string) $imagePath);
        $base = rtrim(str_replace('\\', '/', base_path('produkter')), '/').'/';
        $path = str_replace('\\', '/', $imagePath);
        if ($imagePath === '' || ! str_starts_with($path, $base)) {
            return null;
        }

        return '/produkter/'.implode('/', array_map('rawurlencode', explode('/', substr($path, strlen($base)))));
    }

    private function markOrderItemsDelivered(string $orderId, object $user): int
    {
        return DB::table('order_items')
            ->where('order_id', $orderId)
            ->pluck('id')
            ->sum(fn ($itemId) => $this->markOrderItemDelivered((int) $itemId, $user));
    }

    private function markOrderItemDelivered(int $itemId, object $user, $quantity = null): int
    {
        return DB::transaction(function () use ($itemId, $user, $quantity) {
            $item = DB::table('order_items')->where('id', $itemId)->lockForUpdate()->first();
            if (! $item) {
                return 0;
            }

            $requested = max(1, (int) ($item->requested_quantity ?? $this->quantityValue($item->antal)));
            $current = (int) ($item->delivered_quantity ?? $this->quantityValue($item->delivered_antal ?? 0));
            $quantityToDeliver = $quantity !== null && $quantity !== ''
                ? max(1, $this->quantityValue($quantity))
                : max(0, $requested - $current);
            $target = min($requested, $current + $quantityToDeliver);
            $delta = max(0, $target - $current);
            if ($delta === 0) {
                return 0;
            }

            $itemProductSku = ($item->product_sku ?? null) ?: null;
            $product = $this->productForArticle($itemProductSku ?: $item->artikel);
            $productSku = $product?->sku ?: $itemProductSku;

            DB::table('order_items')->where('id', $itemId)->update([
                'product_sku' => $productSku,
                'product_title' => $product?->title,
                'product_image_path' => $product?->primary_image_path,
                'requested_quantity' => $requested,
                'delivered_quantity' => $target,
                'remaining_quantity' => max(0, $requested - $target),
                'delivered_antal' => (string) $target,
                'delivered_at' => now(),
                'updated_at' => now(),
            ]);

            if ($productSku && Schema::hasTable('products')) {
                $lockedProduct = DB::table('products')->where('sku', $productSku)->lockForUpdate()->first();
                if ($lockedProduct) {
                    $stockDelivered = max(0, (int) $lockedProduct->stock_delivered + $delta);
                    $stockTotal = max(0, (int) $lockedProduct->stock_total);
                    DB::table('products')->where('sku', $productSku)->update([
                        'stock_delivered' => $stockDelivered,
                        'stock_available' => max(0, $stockTotal - $stockDelivered),
                        'updated_at' => now(),
                    ]);

                    if (Schema::hasTable('stock_movements')) {
                        DB::table('stock_movements')->insert([
                            'product_sku' => $productSku,
                            'order_id' => $item->order_id,
                            'order_item_id' => $itemId,
                            'work_order_number' => $item->work_order_number,
                            'quantity_delta' => -$delta,
                            'type' => 'delivered',
                            'created_by' => $user->id,
                            'payload' => json_encode(['artikel' => $item->artikel, 'delivered' => $delta]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            if ($item->work_order_number && Schema::hasTable('work_order_delivery_events')) {
                DB::table('work_order_delivery_events')->insert([
                    'id' => 'evt_'.Str::uuid(),
                    'work_order_number' => $item->work_order_number,
                    'order_id' => $item->order_id,
                    'item_index' => (int) ($item->sort_order ?? 0),
                    'artikel' => $item->artikel,
                    'delivered_antal' => (string) $delta,
                    'delivered_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $delta;
        });
    }

    private function orderRow($row)
    {
        if (! $row) {
            return null;
        }

        $items = DB::table('order_items')
            ->where('order_id', $row->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function ($item) {
                $product = $this->productForArticle(($item->product_sku ?? null) ?: $item->artikel);
                $requestedQuantity = (int) ($item->requested_quantity ?? $this->quantityValue($item->antal));
                $deliveredQuantity = (int) ($item->delivered_quantity ?? $this->quantityValue($item->delivered_antal ?? 0));

                return [
                    'id' => $item->id,
                    'artikel' => $item->artikel,
                    'article' => ($item->article ?? null) ?: $item->artikel,
                    'benamning' => $item->benamning ?? null,
                    'description' => ($item->description ?? null) ?: ($item->benamning ?? null) ?: $item->artikel,
                    'antal' => $item->antal,
                    'quantity' => ($item->quantity ?? null) ?: $item->antal,
                    'workOrderNumber' => $item->work_order_number,
                    'deliveredAntal' => $item->delivered_antal,
                    'deliveredAt' => $item->delivered_at,
                    'requestedQuantity' => $requestedQuantity,
                    'deliveredQuantity' => $deliveredQuantity,
                    'remainingQuantity' => max(0, $requestedQuantity - $deliveredQuantity),
                    'deliveredComplete' => $requestedQuantity > 0 && $deliveredQuantity >= $requestedQuantity,
                    'product' => $product ? [
                        'sku' => $product->sku,
                        'title' => $product->title,
                        'imageUrl' => $product->primary_image_url ?: $this->imageUrl($product->primary_image_path),
                        'stockTotal' => (int) $product->stock_total,
                        'stockDelivered' => (int) $product->stock_delivered,
                        'stockRemaining' => max(0, (int) $product->stock_total - (int) $product->stock_delivered),
                    ] : null,
                    'payload' => $this->decodeJson($item->payload ?? null, new \stdClass()),
                ];
            })
            ->values();

        $address = $row->adress ?? $row->delivery_address ?? null;
        $phone = $row->tele ?? $row->recipient_phone ?? $row->customer_phone ?? null;
        $recipient = $row->mottagare ?? $row->recipient_name ?? $row->customer_name ?? null;
        $notes = $row->notes ?? null;
        $status = $this->normalizeStatus($row->status ?? null, $row->delivered_at ?? null);
        $currentLocation = $this->decodeJson($row->current_location ?? null);

        return [
            'id' => $row->id,
            'orderNumber' => $row->order_number ?? null,
            'adress' => $address,
            'deliveryAddress' => $address,
            'tele' => $phone,
            'recipientPhone' => $phone,
            'mottagare' => $recipient,
            'recipientName' => $recipient,
            'customerName' => $row->customer_name ?? $recipient,
            'customerPhone' => $row->customer_phone ?? $phone,
            'customerEmail' => $row->customer_email ?? null,
            'recipientEmail' => $row->recipient_email ?? null,
            'desiredDeliveryDate' => $row->desired_delivery_date ?? null,
            'desiredDeliveryTime' => isset($row->desired_delivery_time) ? substr((string) $row->desired_delivery_time, 0, 5) : null,
            'assignedDriverId' => $row->assigned_driver_id ?? $row->driver_id ?? null,
            'driverId' => $row->driver_id ?? null,
            'driverName' => $row->driver_name ?? $row->assigned_driver_id ?? null,
            'status' => $status,
            'priority' => $row->priority ?? null,
            'notes' => $notes,
            'ovrigt' => $notes,
            'otherInfo' => $notes,
            'internalComment' => $row->internal_comment ?? null,
            'items' => $items,
            'trackingEnabled' => (bool) ($row->tracking_enabled ?? false),
            'liveTrackingEnabled' => (bool) ($row->tracking_enabled ?? false),
            'trackingToken' => $row->tracking_token ?? null,
            'trackingUrl' => $row->tracking_url ?? null,
            'trackingSessionId' => $row->tracking_session_id ?? null,
            'startedAt' => $row->started_at ?? null,
            'lastStoppedAt' => $row->last_stopped_at ?? null,
            'deliveredBy' => $row->delivered_by ?? null,
            'deliveredNote' => $row->delivered_note ?? null,
            'deliveredAt' => $row->delivered_at ?? null,
            'currentLocation' => $currentLocation,
            'liveLocation' => $currentLocation,
            'externalWorkOrderNumber' => $row->external_work_order_number ?? null,
            'originalWorkOrderSnapshot' => $this->decodeJson($row->original_work_order_snapshot ?? null),
            'smsSentAt' => $row->sms_sent_at ?? null,
            'smsStatus' => $row->sms_status ?? null,
            'smsError' => $row->sms_error ?? null,
            'createdAt' => $row->created_at ?? null,
            'updatedAt' => $row->updated_at ?? null,
        ];
    }

    private function locationPayload($order): array
    {
        $location = $this->decodeJson($order->current_location ?? null, []);

        return [
            'orderId' => $order->id,
            'recipientName' => $order->mottagare ?? $order->recipient_name ?? $order->customer_name ?? 'Leverans',
            'deliveryAddress' => $order->adress ?? $order->delivery_address ?? '',
            'driverId' => $order->driver_id ?? null,
            'driverName' => $order->driver_name ?? $order->assigned_driver_id ?? null,
            'lat' => isset($location['lat']) ? (float) $location['lat'] : (isset($location['latitude']) ? (float) $location['latitude'] : null),
            'lng' => isset($location['lng']) ? (float) $location['lng'] : (isset($location['longitude']) ? (float) $location['longitude'] : null),
            'accuracy' => $location['accuracy'] ?? null,
            'speed' => $location['speed'] ?? null,
            'heading' => $location['heading'] ?? null,
            'trackingEnabled' => (bool) ($order->tracking_enabled ?? false),
            'updatedAt' => $location['updatedAt'] ?? now()->toIso8601String(),
        ];
    }

    private function settingsRow(): array
    {
        $settings = DB::table('system_settings')->where('id', 1)->first();
        if (! $settings) {
            return [];
        }

        return [
            'appTitle' => $settings->app_title,
            'companyName' => $settings->company_name,
            'deliveryTitle' => $settings->delivery_title,
            'supportEmail' => $settings->support_email,
            'supportPhone' => $settings->support_phone,
            'orderNumberPrefix' => $settings->order_number_prefix,
            'allowPushNotifications' => (bool) $settings->allow_push_notifications,
            'adminMessage' => $settings->admin_message,
            'updatedAt' => $settings->updated_at,
            'updatedBy' => $settings->updated_by,
        ];
    }

    private function recipientKey(?string $value): string
    {
        return Str::of((string) $value)->squish()->lower()->ascii()->toString();
    }

    private function isValidRecipientName(?string $name): bool
    {
        $clean = Str::of((string) $name)->squish()->toString();
        if (strlen($clean) < 2 || filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $ascii = Str::of($clean)->lower()->ascii()->toString();
        if (in_array($ascii, ['anvandare', 'user', 'unknown', 'okand'], true)) {
            return false;
        }

        return ! preg_match('/\b(test|codex|example|demo)\b/i', $ascii);
    }

    private function recipientRows(?string $query = null): array
    {
        $addressByName = DB::table('orders')
            ->whereNotNull('mottagare')
            ->where('mottagare', '<>', '')
            ->whereNotNull('adress')
            ->where('adress', '<>', '')
            ->orderByDesc('updated_at')
            ->limit(1000)
            ->get(['mottagare', 'adress'])
            ->reduce(function (array $carry, object $row) {
                $key = $this->recipientKey($row->mottagare);
                if ($key !== '' && ! isset($carry[$key])) {
                    $carry[$key] = $row->adress;
                }

                return $carry;
            }, []);

        $rows = collect();

        DB::table('users')
            ->where('active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'phone', 'email', 'role', 'updated_at'])
            ->each(function (object $user) use ($rows, $addressByName) {
                $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
                $key = $this->recipientKey($name);
                if (! $this->isValidRecipientName($name)) {
                    return;
                }

                $rows->push([
                    'id' => $user->id,
                    'type' => 'user',
                    'label' => $name,
                    'value' => $name,
                    'name' => $name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'role' => $this->normalizedRole($user->role),
                    'source' => 'användare',
                    'sourceLabel' => $this->roleLabel($user->role),
                    'address' => $addressByName[$key] ?? null,
                    'updatedAt' => $user->updated_at,
                ]);
            });

        DB::table('people')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email', 'role', 'source', 'updated_at'])
            ->each(function (object $person) use ($rows, $addressByName) {
                $name = trim((string) $person->name);
                $key = $this->recipientKey($name);
                if (! $this->isValidRecipientName($name)) {
                    return;
                }

                $rows->push([
                    'id' => $person->id,
                    'type' => 'person',
                    'label' => $name,
                    'value' => $name,
                    'name' => $name,
                    'phone' => $person->phone,
                    'email' => $person->email,
                    'role' => $this->normalizedRole($person->role),
                    'source' => $person->source ?: 'person',
                    'sourceLabel' => $person->role ? $this->roleLabel($person->role) : 'Mottagare',
                    'address' => $addressByName[$key] ?? null,
                    'updatedAt' => $person->updated_at,
                ]);
            });

        $queryKey = $this->recipientKey($query);
        $deduplicated = $rows
            ->filter(function (array $row) use ($queryKey) {
                return $queryKey === '' || str_contains($this->recipientKey($row['name']), $queryKey);
            })
            ->reduce(function (array $carry, array $row) {
                $key = $this->recipientKey($row['name']);
                $existing = $carry[$key] ?? null;

                if (! $existing || (! $existing['phone'] && $row['phone']) || ($row['type'] === 'person' && $existing['type'] !== 'person')) {
                    $carry[$key] = $row;
                }

                return $carry;
            }, []);

        return collect($deduplicated)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->take(50)
            ->all();
    }

    private function logRows(): array
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return [];
        }

        $lines = array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -600);
        $rows = [];

        foreach (array_reverse($lines) as $line) {
            if (preg_match('/^\[(?<time>.+?)\]\s+(?<env>[^.]+)\.(?<level>[^:]+):\s+(?<message>.*)$/', $line, $matches)) {
                $rows[] = [
                    'time' => $matches['time'],
                    'user' => 'System',
                    'role' => 'system',
                    'event' => Str::limit($matches['message'], 140, ''),
                    'module' => 'Laravel',
                    'ip' => '-',
                    'status' => strtolower($matches['level']),
                    'details' => Str::limit($line, 1000, ''),
                ];
            } elseif ($rows) {
                $last = array_key_last($rows);
                $rows[$last]['details'] = Str::limit($rows[$last]['details']."\n".$line, 1600, '');
            }

            if (count($rows) >= 200) {
                break;
            }
        }

        return $rows;
    }

    private function fieldSearch(string $field, string $query, bool $driversOnly = false): array
    {
        $field = in_array($field, ['name', 'email', 'phone', 'driver'], true) ? $field : 'name';
        if ($field === 'name' && ! $driversOnly) {
            return $this->recipientRows($query);
        }

        $like = '%'.strtolower(trim($query)).'%';
        $phoneLike = '%'.preg_replace('/[^0-9+]/', '', $query).'%';
        $condition = match ($field) {
            'email' => "lower(coalesce(email, '')) like ?",
            'phone' => "lower(regexp_replace(coalesce(phone, ''), '[^0-9+]', '', 'g')) like ?",
            default => "lower(coalesce(name, '')) like ?",
        };
        $param = $field === 'phone' ? $phoneLike : $like;
        $driverFilter = $driversOnly ? "and lower(coalesce(role, '')) in ('driver','forare','förare','chaufför','admin','manager','owner','firmatecknare','arbetsledare')" : '';

        $rows = DB::select(
            "with searchable as (
                select id, trim(first_name || ' ' || coalesce(last_name, '')) as name, phone, email, role, active, null::text as address from users where active=true
                union all
                select id, name, phone, email, role, active, null::text as address from people where active=true
             )
             select id, name, phone, email, role, active, address
             from searchable
             where {$condition} {$driverFilter}
             order by name asc
             limit 20",
            [$param]
        );

        return array_map(function ($row) use ($field) {
            $value = match ($field) {
                'email' => $row->email,
                'phone' => $row->phone,
                'driver' => $row->id,
                default => $row->name,
            };

            return [
                'id' => $row->id,
                'type' => $field,
                'label' => $field === 'driver' ? $row->name : $value,
                'value' => $value,
                'name' => $row->name,
                'phone' => $row->phone,
                'email' => $row->email,
                'role' => $row->role,
                'active' => (bool) $row->active,
                'address' => $row->address,
            ];
        }, $rows);
    }

    public function health()
    {
        return $this->json(['ok' => true, 'service' => 'laravel', 'time' => now()->toIso8601String()]);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = DB::table('users')->where('email_key', strtolower(trim($data['email'])))->first();

        if (! $user || ! (bool) $user->active || ! $this->verifyPassword($data['password'], $user->password_hash)) {
            return $this->json(['error' => 'Felaktig e-post eller lösenord'], 401);
        }

        if (str_starts_with($user->password_hash, '$2a$') || str_starts_with($user->password_hash, '$2b$')) {
            DB::table('users')->where('id', $user->id)->update([
                'password_hash' => Hash::make($data['password']),
                'updated_at' => now(),
            ]);
            $user = DB::table('users')->where('id', $user->id)->first();
        }

        $accessToken = Str::random(80);
        $refreshToken = Str::random(80);
        DB::table('api_tokens')->insert([
            [
                'user_id' => $user->id,
                'token' => $accessToken,
                'type' => 'access',
                'expires_at' => now()->addHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'token' => $refreshToken,
                'type' => 'refresh',
                'expires_at' => now()->addDays(7),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return $this->json(['accessToken' => $accessToken, 'refreshToken' => $refreshToken, 'user' => $this->publicUser($user)]);
    }

    public function refresh(Request $request)
    {
        $refreshToken = $request->input('refreshToken');
        $row = DB::table('api_tokens')
            ->where('token', $refreshToken)
            ->where('type', 'refresh')
            ->where('expires_at', '>', now())
            ->first();

        if (! $row) {
            return $this->json(['error' => 'Ogiltig refresh token'], 401);
        }

        $accessToken = Str::random(80);
        DB::table('api_tokens')->insert([
            'user_id' => $row->user_id,
            'token' => $accessToken,
            'type' => 'access',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->json(['accessToken' => $accessToken]);
    }

    public function logout(Request $request)
    {
        if ($token = $request->input('refreshToken')) {
            DB::table('api_tokens')->where('token', $token)->delete();
        }

        return $this->json(['success' => true, 'message' => 'Utloggad']);
    }

    public function changePassword(Request $request)
    {
        $user = $this->requireUser($request);
        $data = $request->validate(['currentPassword' => 'required|string', 'newPassword' => 'required|string|min:8']);

        if (! $this->verifyPassword($data['currentPassword'], $user->password_hash)) {
            return $this->json(['error' => 'Nuvarande lösenord är fel'], 400);
        }

        DB::table('users')->where('id', $user->id)->update([
            'password_hash' => Hash::make($data['newPassword']),
            'is_first_login' => false,
            'updated_at' => now(),
        ]);

        return $this->json(['success' => true, 'message' => 'Lösenord uppdaterat']);
    }

    public function forgotPassword(Request $request)
    {
        $email = strtolower(trim($request->input('email', '')));
        $user = DB::table('users')->where('email_key', $email)->first();
        $response = ['success' => true];

        if ($user) {
            $token = Str::random(64);
            DB::table('users')->where('id', $user->id)->update([
                'reset_token' => $token,
                'reset_token_expires' => now()->addHour(),
                'updated_at' => now(),
            ]);

            if (filter_var(env('ALLOW_RESET_TOKEN_RESPONSE', false), FILTER_VALIDATE_BOOL)) {
                $response['resetToken'] = $token;
            }
        }

        return $this->json($response);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate(['token' => 'required|string', 'password' => 'required|string|min:8']);
        $user = DB::table('users')
            ->where('reset_token', $data['token'])
            ->where('reset_token_expires', '>', now())
            ->first();

        if (! $user) {
            return $this->json(['error' => 'Ogiltig eller utgången återställningslänk'], 400);
        }

        DB::table('users')->where('id', $user->id)->update([
            'password_hash' => Hash::make($data['password']),
            'is_first_login' => false,
            'reset_token' => null,
            'reset_token_expires' => null,
            'updated_at' => now(),
        ]);

        return $this->json(['success' => true]);
    }

    public function me(Request $request)
    {
        return $this->json($this->publicUser($this->requireUser($request)));
    }

    public function users(Request $request)
    {
        $this->requirePermission($request, 'users.view');

        return $this->json(DB::table('users')->orderByDesc('created_at')->get()->map(fn ($user) => $this->publicUser($user)));
    }

    public function createUser(Request $request)
    {
        $actor = $this->requirePermission($request, 'users.create');
        $data = $request->validate([
            'email' => ['required', 'email'],
            'firstName' => ['nullable', 'string', 'max:120'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'lastName' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:240'],
            'phone' => ['nullable', 'string', 'max:80'],
            'role' => ['nullable', Rule::in($this->allowedRoleInputs())],
            'password' => ['nullable', 'string', 'min:8'],
            'tempPassword' => ['nullable', 'string', 'min:8'],
            'active' => ['boolean'],
        ]);

        $email = strtolower(trim($data['email']));
        $password = $request->input('password') ?: $request->input('tempPassword') ?: Str::password(12);
        $id = 'usr_'.Str::uuid();
        $name = trim((string) ($data['name'] ?? ''));
        $firstName = $data['firstName'] ?? $data['first_name'] ?? (explode(' ', $name)[0] ?: 'Användare');
        $lastName = $data['lastName'] ?? $data['last_name'] ?? trim(substr($name, strlen(explode(' ', $name)[0] ?? '')));
        $role = $this->normalizedRole($data['role'] ?? 'personal');
        abort_if($role === 'firmatecknare' && ! $this->hasPermission($actor, 'system.full_access'), 403, 'Endast firmatecknare får skapa firmatecknare.');

        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'email_key' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => $role,
            'password_hash' => Hash::make($password),
            'active' => $request->boolean('active', true),
            'phone' => $data['phone'] ?? null,
            'visibility' => 'offline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = DB::table('users')->where('id', $id)->first();

        return $this->json(['user' => $this->publicUser($user), 'tempPassword' => $password], 201);
    }

    public function updateUser(Request $request, $id)
    {
        $actor = $this->requirePermission($request, 'users.update');
        $target = DB::table('users')->where('id', $id)->first();
        abort_if(! $target, 404, 'Användaren hittades inte');

        $map = [
            'email' => 'email',
            'firstName' => 'first_name',
            'first_name' => 'first_name',
            'lastName' => 'last_name',
            'last_name' => 'last_name',
            'role' => 'role',
            'active' => 'active',
            'phone' => 'phone',
            'visibility' => 'visibility',
        ];
        $updates = [];

        foreach ($map as $input => $column) {
            if ($request->has($input)) {
                $updates[$column] = $request->input($input);
            }
        }

        if (isset($updates['email'])) {
            $updates['email_key'] = strtolower(trim($updates['email']));
        }

        if (isset($updates['role'])) {
            $updates['role'] = $this->normalizedRole($updates['role']);
        }

        $this->guardUserMutation($actor, $target, $updates['role'] ?? null);

        $updates['updated_at'] = now();
        DB::table('users')->where('id', $id)->update($updates);

        return $this->json($this->publicUser(DB::table('users')->where('id', $id)->first()));
    }

    public function updateUserPassword(Request $request, $id)
    {
        $actor = $this->requirePermission($request, 'users.change_password');
        $target = DB::table('users')->where('id', $id)->first();
        abort_if(! $target, 404, 'Användaren hittades inte');
        $this->guardUserMutation($actor, $target);

        DB::table('users')->where('id', $id)->update([
            'password_hash' => Hash::make($request->input('password', $request->input('newPassword', 'ChangeMe123!'))),
            'is_first_login' => true,
            'updated_at' => now(),
        ]);

        return $this->json(['success' => true]);
    }

    public function deleteUser(Request $request, $id)
    {
        $actor = $this->requirePermission($request, 'users.delete');
        $target = DB::table('users')->where('id', $id)->first();
        abort_if(! $target, 404, 'Användaren hittades inte');
        abort_if($target->id === $actor->id, 422, 'Du kan inte ta bort ditt eget konto.');
        $this->guardUserMutation($actor, $target);

        DB::table('users')->where('id', $id)->delete();

        return $this->json(['success' => true]);
    }

    public function settings(Request $request)
    {
        $this->requirePermission($request, 'settings.view');

        return $this->json($this->settingsRow());
    }

    public function updateSettings(Request $request)
    {
        $user = $this->requirePermission($request, 'settings.update');
        $data = $request->all();

        DB::table('system_settings')->updateOrInsert(['id' => 1], [
            'app_title' => $data['appTitle'] ?? $data['app_title'] ?? 'Stuckbema Leveransdokument',
            'company_name' => $data['companyName'] ?? $data['company_name'] ?? 'Stuckbema',
            'delivery_title' => $data['deliveryTitle'] ?? $data['delivery_title'] ?? 'Leveransdokument',
            'support_email' => $data['supportEmail'] ?? $data['support_email'] ?? null,
            'support_phone' => $data['supportPhone'] ?? $data['support_phone'] ?? null,
            'order_number_prefix' => $data['orderNumberPrefix'] ?? $data['order_number_prefix'] ?? 'LEV',
            'allow_push_notifications' => $data['allowPushNotifications'] ?? true,
            'admin_message' => $data['adminMessage'] ?? $data['admin_message'] ?? null,
            'updated_by' => $user->id,
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return $this->json($this->settingsRow());
    }

    public function adminSummary(Request $request)
    {
        $this->requirePermission($request, 'system.view_status');
        $roles = DB::table('users')->select('role', DB::raw('count(*) as count'))->groupBy('role')->pluck('count', 'role');
        $statuses = collect(DB::select("
            select normalized_status as status, count(*) as count
            from (
                select case
                    when delivered_at is not null or status in ('delivered','levererad') then 'delivered'
                    when status in ('ongoing','på_väg','pa_vag','started') then 'ongoing'
                    when status in ('paused','pausad') then 'paused'
                    when status in ('cancelled','canceled','avbruten') then 'cancelled'
                    else status
                end as normalized_status
                from orders
            ) normalized_orders
            group by normalized_status
        "))->pluck('count', 'status');

        return $this->json([
            'users' => DB::table('users')->count(),
            'orders' => $statuses,
            'settings' => $this->settingsRow(),
            'counts' => [
                'users' => DB::table('users')->count(),
                'activeUsers' => DB::table('users')->where('active', true)->count(),
                'roles' => $roles,
                'orders' => $statuses,
                'orderTotal' => DB::table('orders')->count(),
                'pendingOrders' => (int) ($statuses['created'] ?? 0) + (int) ($statuses['assigned'] ?? 0) + (int) ($statuses['ongoing'] ?? 0) + (int) ($statuses['paused'] ?? 0),
            ],
            'roleCounts' => $roles,
            'database' => ['connected' => true, 'provider' => 'postgresql', 'schemaVersion' => 'laravel_compat'],
            'generatedAt' => now()->toIso8601String(),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    public function adminOverview(Request $request)
    {
        return $this->adminSummary($request);
    }

    public function roles(Request $request)
    {
        $this->requirePermission($request, 'roles.view');

        return $this->json([
            'roles' => $this->roleOptions(),
            'matrix' => $this->roleMatrix(),
        ]);
    }

    public function adminLogs(Request $request)
    {
        $this->requirePermission($request, 'logs.view');

        return $this->json([
            'results' => $this->logRows(),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    public function recipients(Request $request)
    {
        $this->requireAnyPermission($request, ['customers.view', 'deliveries.create', 'deliveries.update']);

        return $this->json([
            'type' => 'recipients',
            'results' => $this->recipientRows($request->query('q', '')),
        ]);
    }

    public function products(Request $request)
    {
        $this->requireAnyPermission($request, ['articles.view', 'deliveries.create', 'deliveries.update', 'work_orders.view']);
        if (! Schema::hasTable('products')) {
            return $this->json(['results' => []]);
        }

        $query = mb_strtolower(trim((string) $request->query('q', '')), 'UTF-8');

        return $this->json([
            'results' => DB::table('products')
                ->when($query !== '', fn ($builder) => $builder->whereRaw('lower(sku) like ? or lower(title) like ?', ["%{$query}%", "%{$query}%"]))
                ->orderBy('sku')
                ->limit(300)
                ->get()
                ->map(fn ($row) => [
                    'sku' => $row->sku,
                    'title' => $row->title,
                    'imageUrl' => $row->primary_image_url ?: $this->imageUrl($row->primary_image_path),
                    'imageCount' => (int) $row->image_count,
                    'stockTotal' => (int) $row->stock_total,
                    'stockDelivered' => (int) $row->stock_delivered,
                    'stockRemaining' => max(0, (int) $row->stock_total - (int) $row->stock_delivered),
                ])
                ->values(),
        ]);
    }

    public function recipientPhone(Request $request)
    {
        $this->requireAnyPermission($request, ['customers.view', 'deliveries.create', 'deliveries.update']);
        $name = $request->query('name', $request->query('q', ''));
        $match = collect($this->recipientRows($name))
            ->first(fn (array $row) => $this->recipientKey($row['name']) === $this->recipientKey($name));

        return $this->json([
            'name' => $name,
            'phone' => $match['phone'] ?? null,
            'recipient' => $match,
        ]);
    }

    public function drivers(Request $request)
    {
        $this->requireAnyPermission($request, ['drivers.view', 'deliveries.assign_driver']);

        return $this->json($this->fieldSearch('driver', $request->query('q', ''), true));
    }

    public function searchPeople(Request $request)
    {
        $this->requireAnyPermission($request, ['customers.view', 'deliveries.create', 'deliveries.update']);
        $field = $request->query('field', 'name');
        $results = $this->fieldSearch($field, $request->query('q', ''));

        return $this->json(['type' => $field, 'results' => $results]);
    }

    public function suggest(Request $request, $field)
    {
        $this->requireAnyPermission($request, ['customers.view', 'deliveries.create', 'deliveries.update']);
        $type = ['names' => 'name', 'emails' => 'email', 'phones' => 'phone'][$field] ?? 'name';
        $results = $this->fieldSearch($type, $request->query('q', ''));

        return $this->json(['type' => $type, 'results' => $results]);
    }

    public function searchDrivers(Request $request)
    {
        $this->requireAnyPermission($request, ['drivers.view', 'deliveries.assign_driver']);

        return $this->json(['type' => 'driver', 'results' => $this->fieldSearch('driver', $request->query('q', ''), true)]);
    }

    public function driverVisibility(Request $request)
    {
        $user = $this->requireUser($request);
        $visibility = $request->input('visibility') === 'online' ? 'online' : 'offline';

        DB::table('users')->where('id', $user->id)->update([
            'visibility' => $visibility,
            'last_seen_at' => now(),
            'updated_at' => now(),
        ]);

        $updatedUser = DB::table('users')->where('id', $user->id)->first();
        if ($updatedUser) {
            DriverVisibilityUpdated::dispatch([
                'driverId' => $updatedUser->id,
                'driverName' => trim(($updatedUser->first_name ?? '').' '.($updatedUser->last_name ?? '')) ?: $updatedUser->email,
                'visibility' => $visibility,
                'lastSeenAt' => now()->toIso8601String(),
            ]);
        }

        if ($visibility === 'offline') {
            $orders = DB::table('orders')
                ->where('driver_id', $user->id)
                ->where('tracking_enabled', true)
                ->get();

            DB::table('orders')->whereIn('id', $orders->pluck('id'))->update([
                'tracking_enabled' => false,
                'last_stopped_at' => now(),
                'updated_by' => $user->id,
                'updated_at' => now(),
            ]);
            DB::table('tracking_links')->whereIn('order_id', $orders->pluck('id'))->update(['active' => false, 'updated_at' => now()]);

            foreach ($orders as $order) {
                $order->tracking_enabled = false;
                LocationUpdated::dispatch($this->locationPayload($order));
            }
        }

        return $this->json([
            'success' => true,
            'visibility' => $visibility,
            'driverId' => $updatedUser?->id,
            'user' => $this->publicUser($updatedUser),
        ]);
    }

    private function saveItems($orderId, $items): void
    {
        DB::table('order_items')->where('order_id', $orderId)->delete();

        foreach (array_values($items ?: []) as $index => $item) {
            $article = (string) ($item['artikel'] ?? $item['article'] ?? $item['model'] ?? '');
            $quantity = (string) ($item['antal'] ?? $item['quantity'] ?? '');
            $product = $this->productForArticle($article);
            $requestedQuantity = $this->quantityValue($quantity);

            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'artikel' => $article,
                'article' => $item['article'] ?? $article,
                'benamning' => $item['benamning'] ?? $item['description'] ?? null,
                'description' => $item['description'] ?? $item['benamning'] ?? $article,
                'antal' => $quantity,
                'quantity' => $item['quantity'] ?? $quantity,
                'sort_order' => $index,
                'work_order_number' => $item['workOrderNumber'] ?? $item['work_order_number'] ?? null,
                'product_sku' => $product?->sku,
                'product_title' => $product?->title,
                'product_image_path' => $product?->primary_image_path,
                'requested_quantity' => $requestedQuantity,
                'delivered_quantity' => 0,
                'remaining_quantity' => $requestedQuantity,
                'payload' => json_encode($item),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function orderInput(Request $request, $id = null): array
    {
        $data = $request->all();
        $address = $data['adress'] ?? $data['address'] ?? $data['deliveryAddress'] ?? $data['delivery_address'] ?? '';
        $phone = $data['tele'] ?? $data['phone'] ?? $data['recipientPhone'] ?? $data['recipient_phone'] ?? $data['customerPhone'] ?? $data['customer_phone'] ?? '';
        $recipient = $data['mottagare'] ?? $data['receiver'] ?? $data['recipientName'] ?? $data['recipient_name'] ?? $data['customerName'] ?? $data['customer_name'] ?? '';
        $assignedDriverId = $data['assignedDriverId'] ?? $data['assigned_driver_id'] ?? $data['driverId'] ?? $data['driver_id'] ?? null;
        $notes = $data['notes'] ?? $data['otherInfo'] ?? $data['ovrigt'] ?? $data['ovrligt'] ?? null;

        return [
            'id' => $id ?: ($data['id'] ?? 'ord_'.Str::uuid()),
            'order_number' => $data['orderNumber'] ?? $data['order_number'] ?? null,
            'adress' => $address,
            'tele' => $phone,
            'mottagare' => $recipient,
            'customer_name' => $data['customerName'] ?? $data['customer_name'] ?? $recipient,
            'customer_phone' => $data['customerPhone'] ?? $data['customer_phone'] ?? $phone,
            'customer_email' => $data['customerEmail'] ?? $data['customer_email'] ?? $data['recipientEmail'] ?? $data['recipient_email'] ?? null,
            'recipient_name' => $data['recipientName'] ?? $data['recipient_name'] ?? $recipient,
            'recipient_phone' => $data['recipientPhone'] ?? $data['recipient_phone'] ?? $phone,
            'recipient_email' => $data['recipientEmail'] ?? $data['recipient_email'] ?? $data['customerEmail'] ?? $data['customer_email'] ?? null,
            'delivery_address' => $address,
            'desired_delivery_date' => $data['desiredDeliveryDate'] ?? $data['desired_delivery_date'] ?? null,
            'desired_delivery_time' => $data['desiredDeliveryTime'] ?? $data['desired_delivery_time'] ?? null,
            'assigned_driver_id' => $assignedDriverId,
            'driver_id' => $data['driverId'] ?? $data['driver_id'] ?? null,
            'driver_name' => $data['driverName'] ?? $data['driver_name'] ?? null,
            'status' => $this->normalizeStatus($data['status'] ?? 'created'),
            'priority' => $data['priority'] ?? null,
            'notes' => $notes,
            'internal_comment' => $data['internalComment'] ?? $data['internal_comment'] ?? null,
            'external_work_order_number' => $data['externalWorkOrderNumber'] ?? $data['external_work_order_number'] ?? null,
            'original_work_order_snapshot' => isset($data['originalWorkOrderSnapshot'])
                ? json_encode($data['originalWorkOrderSnapshot'])
                : (isset($data['original_work_order_snapshot']) ? json_encode($data['original_work_order_snapshot']) : null),
            'updated_at' => now(),
        ];
    }

    public function orders(Request $request)
    {
        $user = $this->requireAnyPermission($request, ['deliveries.view_all', 'deliveries.view_own']);
        $query = DB::table('orders')->orderByDesc('created_at');

        if (! $this->hasPermission($user, 'deliveries.view_all')) {
            $query->where(function ($builder) use ($user) {
                $builder->where('driver_id', $user->id)
                    ->orWhere('assigned_driver_id', $user->id);
            });
        }

        return $this->json($query->get()->map(fn ($order) => $this->orderRow($order)));
    }

    public function createOrder(Request $request)
    {
        $user = $this->requirePermission($request, 'deliveries.create');
        $data = $this->orderInput($request);
        $data['created_by'] = $user->id;
        $data['updated_by'] = $user->id;
        $data['created_at'] = now();

        DB::table('orders')->insert($data);

        $items = $request->input('items', []);
        if (! count($items)) {
            $items = [['artikel' => $request->input('artikel', $request->input('model', '')), 'antal' => $request->input('antal', '')]];
        }

        $this->saveItems($data['id'], $items);

        return $this->json($this->orderRow(DB::table('orders')->where('id', $data['id'])->first()), 201);
    }

    public function updateOrder(Request $request, $id)
    {
        $user = $this->requirePermission($request, 'deliveries.update');
        $data = $this->orderInput($request, $id);
        unset($data['id']);
        $data['updated_by'] = $user->id;

        DB::table('orders')->where('id', $id)->update($data);

        if ($request->has('items')) {
            $this->saveItems($id, $request->input('items', []));
        }

        return $this->json($this->orderRow(DB::table('orders')->where('id', $id)->first()));
    }

    public function startOrder(Request $request, $id)
    {
        $user = $this->requirePermission($request, 'deliveries.update_status');
        $order = DB::table('orders')->where('id', $id)->first();
        if (! $order) {
            return $this->json(['error' => 'Leveransen hittades inte'], 404);
        }
        $this->guardOrderAccess($user, $order);

        $token = $order->tracking_token ?: Str::random(64);
        $sessionId = $order->tracking_session_id ?: Str::random(48);
        $trackingUrl = rtrim(env('PUBLIC_APP_URL', config('app.url')), '/').'/track/'.rawurlencode($token);

        DB::table('orders')->where('id', $id)->update([
            'status' => 'ongoing',
            'tracking_enabled' => true,
            'tracking_token' => $token,
            'tracking_url' => $trackingUrl,
            'tracking_session_id' => $sessionId,
            'started_at' => $order->started_at ?: now(),
            'driver_id' => $user->id,
            'assigned_driver_id' => $order->assigned_driver_id ?: $user->id,
            'driver_name' => trim($user->first_name.' '.$user->last_name),
            'updated_by' => $user->id,
            'updated_at' => now(),
        ]);

        DB::table('tracking_links')->updateOrInsert(['token' => $token], [
            'order_id' => $id,
            'active' => true,
            'expires_at' => now()->addDay(),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        app(WebPushNotifier::class)->sendToUsers(array_filter([$user->id, $order->assigned_driver_id]), [
            'notification' => [
                'title' => 'Leverans aktiv',
                'body' => trim(($order->mottagare ?? 'Leverans').' är startad. Livekartan uppdateras nu.'),
                'tag' => 'order-'.$order->id,
            ],
            'data' => [
                'url' => '/',
                'orderId' => $order->id,
            ],
        ]);

        return $this->json([
            'success' => true,
            'order' => $this->orderRow(DB::table('orders')->where('id', $id)->first()),
            'trackingUrl' => $trackingUrl,
            'trackingToken' => $token,
        ]);
    }

    public function stopOrder(Request $request, $id)
    {
        $user = $this->requirePermission($request, 'deliveries.update_status');
        $order = DB::table('orders')->where('id', $id)->first();
        if (! $order) {
            return $this->json(['error' => 'Leveransen hittades inte'], 404);
        }
        $this->guardOrderAccess($user, $order);

        DB::table('orders')->where('id', $id)->update([
            'status' => 'paused',
            'tracking_enabled' => false,
            'last_stopped_at' => now(),
            'updated_by' => $user->id,
            'updated_at' => now(),
        ]);
        DB::table('tracking_links')->where('order_id', $id)->update(['active' => false, 'updated_at' => now()]);
        $this->markOrderItemsDelivered($id, $user);

        $order = DB::table('orders')->where('id', $id)->first();
        LocationUpdated::dispatch($this->locationPayload($order));

        return $this->json(['success' => true, 'order' => $this->orderRow($order)]);
    }

    public function delivered(Request $request, $id)
    {
        $user = $this->requirePermission($request, 'deliveries.update_status');
        $existing = DB::table('orders')->where('id', $id)->first();
        if (! $existing) {
            return $this->json(['error' => 'Leveransen hittades inte'], 404);
        }
        $this->guardOrderAccess($user, $existing);

        DB::table('orders')->where('id', $id)->update([
            'status' => 'delivered',
            'delivered_by' => $user->id,
            'delivered_note' => $request->input('deliveredNote', $request->input('note', $request->input('delivered_note', ''))),
            'delivered_at' => now(),
            'tracking_enabled' => false,
            'updated_by' => $user->id,
            'updated_at' => now(),
        ]);
        DB::table('tracking_links')->where('order_id', $id)->update(['active' => false, 'updated_at' => now()]);

        $order = DB::table('orders')->where('id', $id)->first();
        LocationUpdated::dispatch($this->locationPayload($order));

        return $this->json($this->orderRow($order));
    }

    public function location(Request $request, $id)
    {
        $user = $this->requirePermission($request, 'deliveries.update_status');
        $order = DB::table('orders')->where('id', $id)->first();
        if (! $order) {
            return $this->json(['error' => 'Leveransen hittades inte'], 404);
        }
        $this->guardOrderAccess($user, $order);

        $lat = (float) $request->input('lat', $request->input('latitude'));
        $lng = (float) $request->input('lng', $request->input('longitude'));
        $location = [
            'lat' => $lat,
            'lng' => $lng,
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => $request->input('accuracy'),
            'speed' => $request->input('speed'),
            'heading' => $request->input('heading'),
            'trackingSessionId' => $request->input('trackingSessionId'),
            'updatedAt' => now()->toIso8601String(),
        ];

        DB::table('orders')->where('id', $id)->update([
            'current_location' => json_encode($location),
            'updated_by' => $user->id,
            'updated_at' => now(),
        ]);

        $order = DB::table('orders')->where('id', $id)->first();
        LocationUpdated::dispatch($this->locationPayload($order));

        return $this->json(['success' => true, 'location' => $location, 'order' => $this->orderRow($order)]);
    }

    public function resendSms(Request $request, $id)
    {
        $this->requirePermission($request, 'tracking.send');
        DB::table('orders')->where('id', $id)->update([
            'sms_sent_at' => now(),
            'sms_status' => 'logged',
            'updated_at' => now(),
        ]);

        return $this->json(['success' => true, 'status' => 'logged']);
    }

    public function deleteOrder(Request $request, $id)
    {
        $this->requirePermission($request, 'deliveries.delete');
        DB::table('order_items')->where('order_id', $id)->delete();
        DB::table('orders')->where('id', $id)->delete();

        return $this->json(['success' => true]);
    }

    public function clearOrders(Request $request)
    {
        $this->requirePermission($request, 'deliveries.delete');
        DB::table('order_items')->delete();
        DB::table('orders')->delete();

        return $this->json(['success' => true]);
    }

    public function workOrderSuggestions(Request $request)
    {
        $this->requirePermission($request, 'work_orders.view');
        $query = strtolower($request->query('q', ''));

        return $this->json(DB::table('external_work_orders')
            ->select('work_order_number')
            ->when($query, fn ($builder) => $builder->whereRaw('lower(work_order_number) like ?', ["%{$query}%"]))
            ->orderByDesc('updated_at')
            ->limit(20)
            ->pluck('work_order_number'));
    }

    public function externalWorkOrders(Request $request)
    {
        if (env('EXTERNAL_API_KEY') && $request->header('X-API-Key') !== env('EXTERNAL_API_KEY')) {
            return $this->json(['error' => 'Ogiltig API-nyckel'], 401);
        }

        $rawOrders = $request->input('workOrders', $request->input('orders', $request->input('arbetsordrar', [$request->all()])));
        $orders = [];

        foreach ($rawOrders as $rawOrder) {
            $number = trim((string) ($rawOrder['workOrderNumber'] ?? $rawOrder['work_order_number'] ?? $rawOrder['arbetsorderNummer'] ?? $rawOrder['arbetsorder_nr'] ?? $rawOrder['number'] ?? $rawOrder['id'] ?? ''));
            if ($number === '') {
                return $this->json(['error' => 'Arbetsordernummer krävs'], 400);
            }

            $items = array_values(array_filter(array_map(fn ($item) => [
                'artikel' => trim((string) ($item['artikel'] ?? $item['article'] ?? $item['name'] ?? $item['model'] ?? '')),
                'antal' => trim((string) ($item['antal'] ?? $item['quantity'] ?? $item['qty'] ?? '')),
                'workOrderNumber' => $number,
            ], $rawOrder['items'] ?? []), fn ($item) => $item['artikel'] !== '' && $item['antal'] !== ''));

            if (! count($items)) {
                return $this->json(['error' => 'Minst en artikel krävs'], 400);
            }

            $address = $rawOrder['deliveryAddress'] ?? $rawOrder['delivery_address'] ?? $rawOrder['adress'] ?? $rawOrder['address'] ?? '';
            $phone = $rawOrder['recipientPhone'] ?? $rawOrder['recipient_phone'] ?? $rawOrder['tele'] ?? $rawOrder['phone'] ?? '';
            $recipient = $rawOrder['recipientName'] ?? $rawOrder['recipient_name'] ?? $rawOrder['mottagare'] ?? $rawOrder['receiver'] ?? '';
            $payload = [...$rawOrder, 'workOrderNumber' => $number, 'items' => $items];

            DB::table('external_work_orders')->updateOrInsert(['work_order_number' => $number], [
                'source' => $rawOrder['source'] ?? 'external-api',
                'recipient_name' => $recipient,
                'recipient_phone' => $phone,
                'delivery_address' => $address,
                'original_payload' => json_encode($payload),
                'status' => $rawOrder['status'] ?? 'open',
                'updated_at' => now(),
                'created_at' => now(),
            ]);

            $existing = DB::table('orders')->where('external_work_order_number', $number)->orderByDesc('created_at')->first();
            $orderRequest = new Request([
                'adress' => $address,
                'tele' => $phone,
                'mottagare' => $recipient,
                'status' => 'assigned',
                'externalWorkOrderNumber' => $number,
                'originalWorkOrderSnapshot' => $payload,
                'items' => $items,
            ]);

            if ($existing) {
                $data = $this->orderInput($orderRequest, $existing->id);
                unset($data['id']);
                DB::table('orders')->where('id', $existing->id)->update($data);
                $this->saveItems($existing->id, $items);
                $orders[] = $this->orderRow(DB::table('orders')->where('id', $existing->id)->first());
            } else {
                $data = $this->orderInput($orderRequest);
                $data['created_at'] = now();
                DB::table('orders')->insert($data);
                $this->saveItems($data['id'], $items);
                $orders[] = $this->orderRow(DB::table('orders')->where('id', $data['id'])->first());
            }
        }

        return $this->json(['success' => true, 'count' => count($orders), 'orders' => $orders]);
    }

    public function track(Request $request, $token)
    {
        $link = DB::table('tracking_links')
            ->where('token', $token)
            ->where('active', true)
            ->where('expires_at', '>', now())
            ->first();
        $order = $link
            ? DB::table('orders')->where('id', $link->order_id)->first()
            : DB::table('orders')->where('tracking_token', $token)->first();

        if (! $order) {
            return $this->json(['error' => 'Spårningslänken hittades inte'], 404);
        }

        $row = $this->orderRow($order);

        return $this->json([
            'status' => $row['status'],
            'active' => (bool) ($row['trackingEnabled'] && $row['currentLocation'] && ! $row['deliveredAt']),
            'currentLocation' => $row['currentLocation'],
            'liveLocation' => $row['currentLocation'],
            'liveTrackingEnabled' => $row['trackingEnabled'],
            'deliveryAddress' => $row['adress'],
            'recipientName' => $row['mottagare'],
            'deliveredAt' => $row['deliveredAt'],
            'updatedAt' => $row['updatedAt'],
            'order' => $row,
            'tracking' => ['active' => (bool) $row['trackingEnabled'], 'currentLocation' => $row['currentLocation']],
        ]);
    }

    public function trackStream(Request $request, $token)
    {
        return response()->stream(function () use ($token) {
            echo "event: snapshot\n";
            echo 'data: '.json_encode(['token' => $token, 'time' => now()->toIso8601String()])."\n\n";
            @ob_flush();
            flush();
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
    }

    public function notificationConfig(Request $request)
    {
        $this->requireUser($request);

        $notifier = app(WebPushNotifier::class);

        return $this->json([
            'publicKey' => env('VAPID_PUBLIC_KEY'),
            'configured' => $notifier->configured(),
            'enabled' => $notifier->enabled(),
        ]);
    }

    public function pushSubscribe(Request $request)
    {
        $user = $this->requireUser($request);
        $endpoint = $request->input('endpoint') ?: $request->input('subscription.endpoint');
        if (! $endpoint) {
            return $this->json(['error' => 'endpoint saknas'], 400);
        }

        $p256dh = $request->input('keys.p256dh', $request->input('subscription.keys.p256dh', ''));
        $auth = $request->input('keys.auth', $request->input('subscription.keys.auth', ''));
        if ($p256dh === '' || $auth === '') {
            return $this->json(['error' => 'push-nycklar saknas'], 422);
        }

        $existingSubscription = DB::table('push_subscriptions')->where('endpoint', $endpoint)->first();
        $subscriptionId = $request->input('id') ?: $existingSubscription?->id ?: 'psh_'.Str::uuid();

        DB::table('push_subscriptions')->updateOrInsert(['endpoint' => $endpoint], [
            'id' => $subscriptionId,
            'user_id' => $user->id,
            'platform' => 'web',
            'provider' => 'webpush',
            'p256dh' => $p256dh,
            'auth' => $auth,
            'permission' => $request->input('permission'),
            'user_agent' => $request->input('userAgent', $request->userAgent()),
            'payload' => json_encode($request->all()),
            'enabled' => true,
            'failure_count' => 0,
            'revoked_at' => null,
            'last_seen_at' => now(),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $result = app(WebPushNotifier::class)->sendToUsers([$user->id], [
            'notification' => [
                'title' => 'Pushnotiser aktiva',
                'body' => 'Den här enheten kan nu ta emot leveransnotiser.',
                'tag' => 'push-enabled',
            ],
            'data' => ['url' => '/'],
        ]);

        return $this->json(['success' => true, 'subscriptionId' => $subscriptionId, 'result' => $result]);
    }

    public function pushDelete(Request $request)
    {
        $user = $this->requireUser($request);
        DB::table('push_subscriptions')
            ->where('user_id', $user->id)
            ->where('endpoint', $request->input('endpoint'))
            ->delete();

        return $this->json(['success' => true]);
    }

    public function mySubscriptions(Request $request)
    {
        $user = $this->requireUser($request);

        return $this->json(DB::table('push_subscriptions')->where('user_id', $user->id)->get());
    }

    public function allSubscriptions(Request $request)
    {
        $this->requireUser($request);

        return $this->json(DB::table('push_subscriptions')->get());
    }

    public function pushTest(Request $request)
    {
        $user = $this->requireUser($request);
        $result = app(WebPushNotifier::class)->sendToUsers([$user->id], [
            'notification' => [
                'title' => 'Testnotis',
                'body' => 'Pushnotiser fungerar för Stuckbema Leverans.',
                'tag' => 'push-test',
            ],
            'data' => ['url' => '/'],
        ]);

        return $this->json(['success' => true, 'message' => 'Push-test skickad', 'result' => $result]);
    }
}
