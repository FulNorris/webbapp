<?php

namespace App\Http\Controllers;

use App\Events\DriverVisibilityUpdated;
use App\Events\LocationUpdated;
use App\Services\WebPushNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DeliveryApiController extends Controller
{
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
            ->map(fn ($item) => [
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
                'payload' => $this->decodeJson($item->payload ?? null, new \stdClass()),
            ])
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

    private function fieldSearch(string $field, string $query, bool $driversOnly = false): array
    {
        $field = in_array($field, ['name', 'email', 'phone', 'driver'], true) ? $field : 'name';
        $like = '%'.strtolower(trim($query)).'%';
        $phoneLike = '%'.preg_replace('/[^0-9+]/', '', $query).'%';
        $condition = match ($field) {
            'email' => "lower(coalesce(email, '')) like ?",
            'phone' => "lower(regexp_replace(coalesce(phone, ''), '[^0-9+]', '', 'g')) like ?",
            default => "lower(coalesce(name, '')) like ?",
        };
        $param = $field === 'phone' ? $phoneLike : $like;
        $driverFilter = $driversOnly ? "and lower(coalesce(role, '')) in ('driver','chaufför','admin','manager','owner')" : '';

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
        $this->requireUser($request);

        return $this->json(DB::table('users')->orderByDesc('created_at')->get()->map(fn ($user) => $this->publicUser($user)));
    }

    public function createUser(Request $request)
    {
        $this->requireUser($request);
        $email = strtolower(trim($request->input('email')));
        $password = $request->input('password') ?: $request->input('tempPassword') ?: Str::password(12);
        $id = 'usr_'.Str::uuid();
        $name = trim((string) $request->input('name', ''));

        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'email_key' => $email,
            'first_name' => $request->input('firstName', $request->input('first_name', explode(' ', $name)[0] ?: 'Användare')),
            'last_name' => $request->input('lastName', $request->input('last_name', trim(substr($name, strlen(explode(' ', $name)[0] ?? ''))))),
            'role' => $request->input('role', 'worker'),
            'password_hash' => Hash::make($password),
            'active' => $request->boolean('active', true),
            'phone' => $request->input('phone'),
            'visibility' => 'offline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = DB::table('users')->where('id', $id)->first();

        return $this->json(['user' => $this->publicUser($user), 'tempPassword' => $password], 201);
    }

    public function updateUser(Request $request, $id)
    {
        $this->requireUser($request);
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

        $updates['updated_at'] = now();
        DB::table('users')->where('id', $id)->update($updates);

        return $this->json($this->publicUser(DB::table('users')->where('id', $id)->first()));
    }

    public function updateUserPassword(Request $request, $id)
    {
        $this->requireUser($request);
        DB::table('users')->where('id', $id)->update([
            'password_hash' => Hash::make($request->input('password', $request->input('newPassword', 'ChangeMe123!'))),
            'is_first_login' => true,
            'updated_at' => now(),
        ]);

        return $this->json(['success' => true]);
    }

    public function deleteUser(Request $request, $id)
    {
        $this->requireUser($request);
        DB::table('users')->where('id', $id)->delete();

        return $this->json(['success' => true]);
    }

    public function settings(Request $request)
    {
        $this->requireUser($request);

        return $this->json($this->settingsRow());
    }

    public function updateSettings(Request $request)
    {
        $user = $this->requireUser($request);
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
        $this->requireUser($request);
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

    public function drivers(Request $request)
    {
        $this->requireUser($request);

        return $this->json($this->fieldSearch('driver', $request->query('q', ''), true));
    }

    public function searchPeople(Request $request)
    {
        $this->requireUser($request);
        $field = $request->query('field', 'name');
        $results = $this->fieldSearch($field, $request->query('q', ''));

        return $this->json(['type' => $field, 'results' => $results]);
    }

    public function suggest(Request $request, $field)
    {
        $this->requireUser($request);
        $type = ['names' => 'name', 'emails' => 'email', 'phones' => 'phone'][$field] ?? 'name';
        $results = $this->fieldSearch($type, $request->query('q', ''));

        return $this->json(['type' => $type, 'results' => $results]);
    }

    public function searchDrivers(Request $request)
    {
        $this->requireUser($request);

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
        DriverVisibilityUpdated::dispatch([
            'driverId' => $updatedUser->id,
            'driverName' => trim(($updatedUser->first_name ?? '').' '.($updatedUser->last_name ?? '')) ?: $updatedUser->email,
            'visibility' => $visibility,
            'lastSeenAt' => now()->toIso8601String(),
        ]);

        return $this->json(['success' => true, 'visibility' => $visibility, 'user' => $this->publicUser($updatedUser)]);
    }

    private function saveItems($orderId, $items): void
    {
        DB::table('order_items')->where('order_id', $orderId)->delete();

        foreach (array_values($items ?: []) as $index => $item) {
            $article = (string) ($item['artikel'] ?? $item['article'] ?? $item['model'] ?? '');
            $quantity = (string) ($item['antal'] ?? $item['quantity'] ?? '');

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
        $this->requireUser($request);

        return $this->json(DB::table('orders')->orderByDesc('created_at')->get()->map(fn ($order) => $this->orderRow($order)));
    }

    public function createOrder(Request $request)
    {
        $user = $this->requireUser($request);
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
        $user = $this->requireUser($request);
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
        $user = $this->requireUser($request);
        $order = DB::table('orders')->where('id', $id)->first();
        if (! $order) {
            return $this->json(['error' => 'Leveransen hittades inte'], 404);
        }

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
        $user = $this->requireUser($request);
        DB::table('orders')->where('id', $id)->update([
            'status' => 'paused',
            'tracking_enabled' => false,
            'last_stopped_at' => now(),
            'updated_by' => $user->id,
            'updated_at' => now(),
        ]);
        DB::table('tracking_links')->where('order_id', $id)->update(['active' => false, 'updated_at' => now()]);

        $order = DB::table('orders')->where('id', $id)->first();
        LocationUpdated::dispatch($this->locationPayload($order));

        return $this->json(['success' => true, 'order' => $this->orderRow($order)]);
    }

    public function delivered(Request $request, $id)
    {
        $user = $this->requireUser($request);
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
        $user = $this->requireUser($request);
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
        $this->requireUser($request);
        DB::table('orders')->where('id', $id)->update([
            'sms_sent_at' => now(),
            'sms_status' => 'logged',
            'updated_at' => now(),
        ]);

        return $this->json(['success' => true, 'status' => 'logged']);
    }

    public function deleteOrder(Request $request, $id)
    {
        $this->requireUser($request);
        DB::table('order_items')->where('order_id', $id)->delete();
        DB::table('orders')->where('id', $id)->delete();

        return $this->json(['success' => true]);
    }

    public function clearOrders(Request $request)
    {
        $this->requireUser($request);
        DB::table('order_items')->delete();
        DB::table('orders')->delete();

        return $this->json(['success' => true]);
    }

    public function workOrderSuggestions(Request $request)
    {
        $this->requireUser($request);
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

        return $this->json(['publicKey' => env('VAPID_PUBLIC_KEY'), 'enabled' => ! empty(env('VAPID_PUBLIC_KEY'))]);
    }

    public function pushSubscribe(Request $request)
    {
        $user = $this->requireUser($request);
        $endpoint = $request->input('endpoint') ?: $request->input('subscription.endpoint');
        if (! $endpoint) {
            return $this->json(['error' => 'endpoint saknas'], 400);
        }

        DB::table('push_subscriptions')->updateOrInsert(['endpoint' => $endpoint], [
            'id' => $request->input('id', 'psh_'.Str::uuid()),
            'user_id' => $user->id,
            'platform' => 'web',
            'provider' => 'webpush',
            'p256dh' => $request->input('keys.p256dh', $request->input('subscription.keys.p256dh', '')),
            'auth' => $request->input('keys.auth', $request->input('subscription.keys.auth', '')),
            'payload' => json_encode($request->all()),
            'enabled' => true,
            'last_seen_at' => now(),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return $this->json(['success' => true]);
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
