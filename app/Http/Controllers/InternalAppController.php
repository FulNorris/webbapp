<?php

namespace App\Http\Controllers;

use App\Events\DriverVisibilityUpdated;
use App\Events\LocationUpdated;
use App\Services\WebPushNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InternalAppController extends Controller
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
        'lasbehorighet' => 'viewer',
        'läsbehörighet' => 'viewer',
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

    private array $roleDescriptions = [
        'firmatecknare' => 'Fullständig åtkomst till hela systemet.',
        'admin' => 'Administratör med bred åtkomst, men utan rätt att ändra firmatecknare.',
        'arbetsledare' => 'Operativ roll för daglig leverans- och arbetsorderhantering.',
        'personal' => 'Intern användare för registrering och uppföljning.',
        'support' => 'Felsökning, status och begränsade loggar.',
        'forare' => 'Förare som hanterar egna uppdrag och status.',
        'viewer' => 'Intern läsbehörighet utan ändringsrätt.',
        'kund' => 'Extern tracking-åtkomst utan adminfunktioner.',
    ];

    public function login(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        if ($this->activeUser($request)) {
            return redirect()->route('internal.dashboard');
        }

        return Inertia::render('Login', [
            'appName' => config('app.name'),
        ]);
    }

    public function authenticate(Request $request): \Illuminate\Http\RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = DB::table('users')
            ->where('email_key', strtolower(trim($credentials['email'])))
            ->first();

        if (! $user || ! (bool) $user->active || ! $this->verifyPassword($credentials['password'], $user->password_hash)) {
            return back()->withErrors(['email' => 'Felaktig e-post eller lösenord'])->onlyInput('email');
        }

        if (str_starts_with($user->password_hash, '$2a$') || str_starts_with($user->password_hash, '$2b$')) {
            DB::table('users')->where('id', $user->id)->update([
                'password_hash' => Hash::make($credentials['password']),
                'updated_at' => now(),
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('internal_user_id', $user->id);

        return redirect()->route('internal.dashboard');
    }

    public function logout(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->session()->forget('internal_user_id');
        $request->session()->regenerateToken();

        return redirect()->route('internal.login');
    }

    public function dashboard(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $user = $this->activeUser($request);
        if (! $user) {
            return redirect()->route('internal.login');
        }

        return Inertia::render('Dashboard', $this->dashboardProps($user));
    }

    public function liveMap(Request $request): \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse
    {
        $user = $this->activeUser($request);
        if (! $user) {
            return redirect()->route('internal.login');
        }

        return view('live-map', [
            'user' => $this->userRow($user),
            'settings' => $this->settings(),
        ]);
    }

    public function createOrder(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'deliveries.create');
        $data = $this->validateOrder($request);
        $id = 'ord_'.Str::uuid();

        DB::transaction(function () use ($data, $id, $user) {
            DB::table('orders')->insert([
                ...$this->orderPayload($data),
                'id' => $id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->replaceOrderItems($id, $data['items'] ?? []);
        });

        return back()->with('success', 'Leveransen skapades.');
    }

    public function updateOrder(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'deliveries.update');
        $data = $this->validateOrder($request);

        DB::transaction(function () use ($data, $id, $user) {
            $payload = $this->orderPayload($data);
            unset($payload['status']);

            DB::table('orders')->where('id', $id)->update([
                ...$payload,
                'updated_by' => $user->id,
                'updated_at' => now(),
            ]);
            $this->replaceOrderItems($id, $data['items'] ?? []);
        });

        return back()->with('success', 'Leveransen uppdaterades.');
    }

    public function updateOrderStatus(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'deliveries.update_status');
        $data = $request->validate([
            'status' => ['required', Rule::in(['created', 'assigned', 'ongoing', 'paused', 'delivered', 'cancelled'])],
        ]);

        $updates = [
            'status' => $data['status'],
            'updated_by' => $user->id,
            'updated_at' => now(),
        ];

        if ($data['status'] === 'ongoing') {
            $order = DB::table('orders')->where('id', $id)->first();
            $token = $order?->tracking_token ?: Str::random(64);
            $sessionId = $order?->tracking_session_id ?: Str::random(48);
            $trackingUrl = rtrim(env('PUBLIC_APP_URL', config('app.url')), '/').'/track/'.$token;
            $updates = [
                ...$updates,
                'tracking_enabled' => true,
                'tracking_token' => $token,
                'tracking_url' => $trackingUrl,
                'tracking_session_id' => $sessionId,
                'started_at' => $order?->started_at ?: now(),
                'driver_id' => $user->id,
                'assigned_driver_id' => $order?->assigned_driver_id ?: $user->id,
                'driver_name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            ];
            DB::table('tracking_links')->updateOrInsert(['token' => $token], [
                'order_id' => $id,
                'active' => true,
                'expires_at' => now()->addDay(),
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        if ($data['status'] === 'paused' || $data['status'] === 'cancelled') {
            $updates['tracking_enabled'] = false;
            $updates['last_stopped_at'] = now();
            DB::table('tracking_links')->where('order_id', $id)->update(['active' => false, 'updated_at' => now()]);
        }

        if ($data['status'] === 'delivered') {
            $updates['tracking_enabled'] = false;
            $updates['delivered_at'] = now();
            $updates['delivered_by'] = $user->id;
            DB::table('tracking_links')->where('order_id', $id)->update(['active' => false, 'updated_at' => now()]);
        }

        DB::table('orders')->where('id', $id)->update($updates);
        $updatedOrder = DB::table('orders')->where('id', $id)->first();
        if ($updatedOrder) {
            $this->broadcastOrderLocation($updatedOrder);
        }

        if ($data['status'] === 'ongoing' && $updatedOrder) {
            app(WebPushNotifier::class)->sendToUsers(
                array_filter([$user->id, $updatedOrder->assigned_driver_id]),
                [
                    'notification' => [
                        'title' => 'Leverans aktiv',
                        'body' => trim(($updatedOrder->mottagare ?? 'Leverans').' är startad. Livekartan uppdateras nu.'),
                        'tag' => 'order-'.$updatedOrder->id,
                    ],
                    'data' => [
                        'url' => '/',
                        'orderId' => $updatedOrder->id,
                    ],
                ],
            );
        }

        return back()->with('success', 'Status uppdaterades.');
    }

    public function deleteOrder(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $this->requirePermission($request, 'deliveries.delete');
        DB::table('orders')->where('id', $id)->delete();

        return back()->with('success', 'Leveransen togs bort.');
    }

    public function updateOrderLocation(Request $request, string $id): \Illuminate\Http\Response
    {
        $user = $this->requireUser($request);
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
            'heading' => ['nullable', 'numeric'],
        ]);

        $order = DB::table('orders')->where('id', $id)->first();
        abort_if(! $order, 404);

        $location = [
            'lat' => (float) $data['lat'],
            'lng' => (float) $data['lng'],
            'latitude' => (float) $data['lat'],
            'longitude' => (float) $data['lng'],
            'accuracy' => $data['accuracy'] ?? null,
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'updatedAt' => now()->toIso8601String(),
        ];

        DB::table('orders')->where('id', $id)->update([
            'current_location' => json_encode($location),
            'driver_id' => $order->driver_id ?: $user->id,
            'driver_name' => $order->driver_name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'updated_by' => $user->id,
            'updated_at' => now(),
        ]);

        $this->broadcastOrderLocation(DB::table('orders')->where('id', $id)->first());

        return response()->noContent();
    }

    public function updateVisibility(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requireUser($request);
        $data = $request->validate([
            'visibility' => ['required', Rule::in(['online', 'offline'])],
        ]);

        DB::table('users')->where('id', $user->id)->update([
            'visibility' => $data['visibility'],
            'last_seen_at' => now(),
            'updated_at' => now(),
        ]);

        DriverVisibilityUpdated::dispatch([
            'driverId' => $user->id,
            'driverName' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->email,
            'visibility' => $data['visibility'],
            'lastSeenAt' => now()->toIso8601String(),
        ]);

        if ($data['visibility'] === 'offline') {
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
                $this->broadcastOrderLocation($order);
            }
        }

        return back()->with('success', $data['visibility'] === 'online' ? 'Synlighet är Online.' : 'Synlighet är Offline.');
    }

    public function storePushSubscription(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requireUser($request);
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'permission' => ['nullable', 'string', 'max:40'],
            'userAgent' => ['nullable', 'string', 'max:500'],
        ]);

        DB::table('push_subscriptions')->updateOrInsert(['endpoint' => $data['endpoint']], [
            'id' => $request->input('id', 'psh_'.Str::uuid()),
            'user_id' => $user->id,
            'platform' => 'web',
            'provider' => 'webpush',
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'permission' => $data['permission'] ?? null,
            'user_agent' => $data['userAgent'] ?? $request->userAgent(),
            'payload' => json_encode($request->all()),
            'enabled' => true,
            'failure_count' => 0,
            'revoked_at' => null,
            'last_seen_at' => now(),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        app(WebPushNotifier::class)->sendToUsers([$user->id], [
            'notification' => [
                'title' => 'Pushnotiser aktiva',
                'body' => 'Den här enheten kan nu ta emot leveransnotiser.',
                'tag' => 'push-enabled',
            ],
            'data' => ['url' => '/'],
        ]);

        return back()->with('success', 'Pushnotiser aktiverades.');
    }

    public function deletePushSubscription(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requireUser($request);
        $endpoint = (string) $request->input('endpoint');

        DB::table('push_subscriptions')
            ->where('user_id', $user->id)
            ->when($endpoint !== '', fn ($query) => $query->where('endpoint', $endpoint))
            ->update([
                'enabled' => false,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        return back()->with('success', 'Pushnotiser stängdes av för enheten.');
    }

    public function pushTest(Request $request): \Illuminate\Http\RedirectResponse
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

        return back()->with('success', 'Testnotis skickad: '.$result['sent']);
    }

    public function createUser(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'users.create');
        $data = $request->validate([
            'email' => ['required', 'email'],
            'firstName' => ['required', 'string', 'max:120'],
            'lastName' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:80'],
            'role' => ['required', Rule::in($this->roles)],
            'active' => ['boolean'],
        ]);

        $email = strtolower(trim($data['email']));
        $role = $this->normalizedRole($data['role']);
        abort_if($role === 'firmatecknare' && ! $this->hasPermission($user, 'system.full_access'), 403);

        $password = Str::password(12);

        DB::table('users')->insert([
            'id' => 'usr_'.Str::uuid(),
            'email' => $email,
            'email_key' => $email,
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'] ?? '',
            'phone' => $data['phone'] ?? null,
            'role' => $role,
            'active' => $request->boolean('active', true),
            'password_hash' => Hash::make($password),
            'is_first_login' => true,
            'visibility' => 'offline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Användaren skapades.')->with('tempPassword', $password);
    }

    public function updateUser(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'users.update');
        $data = $request->validate([
            'email' => ['required', 'email'],
            'firstName' => ['required', 'string', 'max:120'],
            'lastName' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:80'],
            'role' => ['required', Rule::in($this->roles)],
            'active' => ['boolean'],
        ]);

        $target = DB::table('users')->where('id', $id)->first();
        abort_if(! $target, 404);
        $role = $this->normalizedRole($data['role']);
        $this->guardUserMutation($user, $target, $role);

        $email = strtolower(trim($data['email']));
        DB::table('users')->where('id', $id)->update([
            'email' => $email,
            'email_key' => $email,
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'] ?? '',
            'phone' => $data['phone'] ?? null,
            'role' => $role,
            'active' => $request->boolean('active'),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Användaren uppdaterades.');
    }

    public function resetUserPassword(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'users.change_password');
        $target = DB::table('users')->where('id', $id)->first();
        abort_if(! $target, 404);
        $this->guardUserMutation($user, $target);

        $password = Str::password(12);

        DB::table('users')->where('id', $id)->update([
            'password_hash' => Hash::make($password),
            'is_first_login' => true,
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Lösenordet återställdes.')->with('tempPassword', $password);
    }

    public function deleteUser(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'users.delete');
        $target = DB::table('users')->where('id', $id)->first();
        abort_if(! $target, 404);
        abort_if($target->id === $user->id, 422, 'Du kan inte ta bort ditt eget konto.');
        $this->guardUserMutation($user, $target);

        DB::table('users')->where('id', $id)->delete();

        return back()->with('success', 'Användaren togs bort.');
    }

    public function updateSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'settings.update');
        $data = $request->validate([
            'appTitle' => ['required', 'string', 'max:160'],
            'companyName' => ['required', 'string', 'max:160'],
            'deliveryTitle' => ['required', 'string', 'max:160'],
            'supportEmail' => ['nullable', 'email'],
            'supportPhone' => ['nullable', 'string', 'max:80'],
            'orderNumberPrefix' => ['required', 'string', 'max:20'],
            'adminMessage' => ['nullable', 'string', 'max:2000'],
            'allowPushNotifications' => ['boolean'],
        ]);

        DB::table('system_settings')->updateOrInsert(['id' => 1], [
            'app_title' => $data['appTitle'],
            'company_name' => $data['companyName'],
            'delivery_title' => $data['deliveryTitle'],
            'support_email' => $data['supportEmail'] ?? null,
            'support_phone' => $data['supportPhone'] ?? null,
            'order_number_prefix' => $data['orderNumberPrefix'],
            'admin_message' => $data['adminMessage'] ?? null,
            'allow_push_notifications' => $request->boolean('allowPushNotifications'),
            'updated_by' => $user->id,
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return back()->with('success', 'Inställningarna sparades.');
    }

    public function track(string $token): Response
    {
        $link = DB::table('tracking_links')
            ->where('token', $token)
            ->where('active', true)
            ->where('expires_at', '>', now())
            ->first();
        $order = $link
            ? DB::table('orders')->where('id', $link->order_id)->first()
            : DB::table('orders')->where('tracking_token', $token)->first();

        return Inertia::render('Track', [
            'tracking' => $order ? $this->orderRow($order) : null,
        ]);
    }

    private function dashboardProps(object $user): array
    {
        $orders = DB::table('orders')->orderByDesc('created_at')->limit(300)->get()->map(fn ($row) => $this->orderRow($row))->values();
        $users = DB::table('users')->orderBy('first_name')->orderBy('last_name')->get()->map(fn ($row) => $this->userRow($row))->values();
        $settings = $this->settings();
        $statusCounts = $orders->countBy('status');
        $roleCounts = $users->countBy('roleKey');
        $drivers = $users->filter(fn ($row) => in_array($row['roleKey'], ['forare', 'admin', 'arbetsledare', 'firmatecknare'], true) && $row['active'])->values();

        return [
            'user' => $this->userRow($user),
            'orders' => $orders,
            'users' => $users,
            'drivers' => $drivers,
            'settings' => $settings,
            'roles' => $this->roleOptions(),
            'permissions' => $this->permissionsFor($user->role),
            'admin' => $this->adminPanelData($orders, $users, $drivers),
            'push' => [
                'enabled' => (bool) ($settings['allowPushNotifications'] && env('VAPID_PUBLIC_KEY') && env('VAPID_PRIVATE_KEY')),
                'publicKey' => env('VAPID_PUBLIC_KEY'),
                'subscriptionCount' => DB::table('push_subscriptions')
                    ->where('user_id', $user->id)
                    ->where('enabled', true)
                    ->whereNull('revoked_at')
                    ->count(),
            ],
            'stats' => [
                'ordersTotal' => $orders->count(),
                'activeOrders' => $orders->whereIn('status', ['created', 'assigned', 'ongoing', 'paused'])->count(),
                'deliveredOrders' => (int) ($statusCounts['delivered'] ?? 0),
                'usersTotal' => $users->count(),
                'statusCounts' => $statusCounts,
                'roleCounts' => $roleCounts,
            ],
        ];
    }

    private function activeUser(Request $request): ?object
    {
        $id = $request->session()->get('internal_user_id');
        if (! $id) {
            return null;
        }

        $user = DB::table('users')->where('id', $id)->where('active', true)->first();
        if (! $user) {
            $request->session()->forget('internal_user_id');
        }

        return $user;
    }

    private function requireUser(Request $request): object
    {
        $user = $this->activeUser($request);
        abort_if(! $user, 403);

        return $user;
    }

    private function validateOrder(Request $request): array
    {
        return $request->validate([
            'adress' => ['required', 'string', 'max:500'],
            'tele' => ['nullable', 'string', 'max:80'],
            'mottagare' => ['required', 'string', 'max:180'],
            'desiredDeliveryDate' => ['nullable', 'date'],
            'desiredDeliveryTime' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'internalComment' => ['nullable', 'string', 'max:3000'],
            'items' => ['array'],
            'items.*.artikel' => ['nullable', 'string', 'max:200'],
            'items.*.antal' => ['nullable', 'string', 'max:80'],
            'items.*.workOrderNumber' => ['nullable', 'string', 'max:160'],
        ]);
    }

    private function orderPayload(array $data): array
    {
        return [
            'adress' => $data['adress'],
            'tele' => $data['tele'] ?? '',
            'mottagare' => $data['mottagare'],
            'delivery_address' => $data['adress'],
            'recipient_name' => $data['mottagare'],
            'recipient_phone' => $data['tele'] ?? '',
            'recipient_email' => null,
            'customer_name' => $data['mottagare'],
            'customer_phone' => $data['tele'] ?? '',
            'desired_delivery_date' => $data['desiredDeliveryDate'] ?? null,
            'desired_delivery_time' => $data['desiredDeliveryTime'] ?? null,
            'assigned_driver_id' => null,
            'driver_name' => null,
            'priority' => null,
            'notes' => $data['notes'] ?? null,
            'internal_comment' => $data['internalComment'] ?? null,
            'status' => 'created',
        ];
    }

    private function replaceOrderItems(string $orderId, array $items): void
    {
        DB::table('order_items')->where('order_id', $orderId)->delete();

        foreach (array_values($items) as $index => $item) {
            $article = trim((string) ($item['artikel'] ?? ''));
            $quantity = trim((string) ($item['antal'] ?? ''));
            if ($article === '' && $quantity === '') {
                continue;
            }

            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'artikel' => $article,
                'article' => $article,
                'description' => $article,
                'antal' => $quantity,
                'quantity' => $quantity,
                'sort_order' => $index,
                'work_order_number' => $item['workOrderNumber'] ?? null,
                'payload' => json_encode($item),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function orderRow(object $row): array
    {
        $items = DB::table('order_items')
            ->where('order_id', $row->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'artikel' => $item->artikel,
                'antal' => $item->antal,
                'workOrderNumber' => $item->work_order_number,
                'deliveredAntal' => $item->delivered_antal,
                'deliveredAt' => $item->delivered_at,
            ])
            ->values();

        $status = $row->delivered_at ? 'delivered' : match (strtolower((string) $row->status)) {
            'ongoing', 'started', 'på_väg', 'pa_vag' => 'ongoing',
            'paused', 'pausad' => 'paused',
            'delivered', 'levererad' => 'delivered',
            'cancelled', 'canceled', 'avbruten' => 'cancelled',
            'assigned' => 'assigned',
            default => 'created',
        };

        return [
            'id' => $row->id,
            'adress' => $row->adress,
            'tele' => $row->tele,
            'mottagare' => $row->mottagare,
            'recipientEmail' => $row->recipient_email,
            'desiredDeliveryDate' => $row->desired_delivery_date,
            'desiredDeliveryTime' => $row->desired_delivery_time ? substr((string) $row->desired_delivery_time, 0, 5) : null,
            'assignedDriverId' => $row->assigned_driver_id,
            'driverId' => $row->driver_id,
            'driverName' => $row->driver_name ?: $row->assigned_driver_id,
            'status' => $status,
            'priority' => $row->priority,
            'notes' => $row->notes,
            'internalComment' => $row->internal_comment,
            'items' => $items,
            'trackingEnabled' => (bool) $row->tracking_enabled,
            'trackingUrl' => $row->tracking_url,
            'trackingToken' => $row->tracking_token,
            'currentLocation' => $this->decodeJson($row->current_location),
            'deliveredAt' => $row->delivered_at,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }

    private function broadcastOrderLocation(?object $order): void
    {
        if (! $order) {
            return;
        }

        LocationUpdated::dispatch($this->locationPayload($order));
    }

    private function locationPayload(object $order): array
    {
        $location = $this->decodeJson($order->current_location) ?: [];

        return [
            'orderId' => $order->id,
            'recipientName' => $order->mottagare,
            'deliveryAddress' => $order->adress,
            'driverId' => $order->driver_id,
            'driverName' => $order->driver_name ?: $order->assigned_driver_id,
            'lat' => isset($location['lat']) ? (float) $location['lat'] : (isset($location['latitude']) ? (float) $location['latitude'] : null),
            'lng' => isset($location['lng']) ? (float) $location['lng'] : (isset($location['longitude']) ? (float) $location['longitude'] : null),
            'accuracy' => $location['accuracy'] ?? null,
            'speed' => $location['speed'] ?? null,
            'heading' => $location['heading'] ?? null,
            'trackingEnabled' => (bool) $order->tracking_enabled,
            'updatedAt' => $location['updatedAt'] ?? now()->toIso8601String(),
        ];
    }

    private function userRow(object $row): array
    {
        return [
            'id' => $row->id,
            'email' => $row->email,
            'firstName' => $row->first_name,
            'lastName' => $row->last_name,
            'name' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
            'role' => $row->role,
            'roleKey' => $this->normalizedRole($row->role),
            'roleLabel' => $this->roleLabel($row->role),
            'active' => (bool) $row->active,
            'phone' => $row->phone,
            'visibility' => $row->visibility,
            'lastSeenAt' => $row->last_seen_at,
            'isFirstLogin' => (bool) $row->is_first_login,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }

    private function settings(): array
    {
        $row = DB::table('system_settings')->where('id', 1)->first();

        return [
            'appTitle' => $row?->app_title ?? 'Stuckbema Leveransdokument',
            'companyName' => $row?->company_name ?? 'Stuckbema',
            'deliveryTitle' => $row?->delivery_title ?? 'Leveransdokument',
            'supportEmail' => $row?->support_email,
            'supportPhone' => $row?->support_phone,
            'orderNumberPrefix' => $row?->order_number_prefix ?? 'LEV',
            'allowPushNotifications' => (bool) ($row?->allow_push_notifications ?? true),
            'adminMessage' => $row?->admin_message,
        ];
    }

    private function decodeJson($value)
    {
        if (! $value) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
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
}
