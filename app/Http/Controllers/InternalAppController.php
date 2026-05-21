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

    public function productImage(Request $request, string $folder, string $file): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $base = realpath(base_path('produkter'));
        $path = realpath(base_path('produkter/'.$folder.'/'.$file));
        abort_unless($base && $path && str_starts_with($path, $base.DIRECTORY_SEPARATOR) && is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    public function deliveriesPdf(Request $request): \Illuminate\Http\Response
    {
        $user = $this->requireUser($request);
        abort_unless($this->canCreateDeliveryPdf($user), 403);

        $orders = DB::table('orders')->orderByDesc('created_at')->get();
        $lines = [
            'Stuckbema Leveransdokument',
            'PDF skapad: '.now()->format('Y-m-d H:i'),
            'Skapad av: '.trim(($user->first_name ?? '').' '.($user->last_name ?? '')).' ('.$this->roleLabel($user->role).')',
            '',
            'LEVERANSER',
            '',
        ];

        foreach ($orders as $order) {
            $row = $this->orderRow($order);
            $lines[] = 'Leverans: '.$row['id'].' | Status: '.$this->statusText($row['status']);
            $lines[] = 'Mottagare: '.$row['mottagare'].' | Telefon: '.($row['tele'] ?: '-');
            $lines[] = 'Adress: '.$row['adress'];
            $lines[] = 'Onskat: '.trim(($row['desiredDeliveryDate'] ?: '-').' '.($row['desiredDeliveryTime'] ?: ''));
            if ($row['notes']) {
                $lines[] = 'Anteckning: '.$row['notes'];
            }

            foreach ($row['items'] as $item) {
                $product = $item['product'] ?? null;
                $lines[] = '  Artikel: '.$item['artikel']
                    .' | Arbetsorder: '.($item['workOrderNumber'] ?: '-')
                    .' | Antal: '.($item['antal'] ?: '0')
                    .' | Levererat: '.$item['deliveredQuantity']
                    .' | Kvar: '.$item['remainingQuantity'];
                if ($product) {
                    $lines[] = '    Produkt: '.$product['sku'].' - '.$product['title'].' | Lager kvar: '.$product['stockRemaining'].' av '.$product['stockTotal'];
                }
            }
            $lines[] = '';
        }

        $lines[] = '';
        $lines[] = 'SAMMANFATTNING ARTIKLAR EFTER LEVERERAD UTKORNING';
        $lines[] = '';
        foreach ($this->productRows() as $product) {
            $lines[] = $product['sku'].' | '.$product['title'].' | Totalt: '.$product['stockTotal'].' | Levererat: '.$product['stockDelivered'].' | Kvar: '.$product['stockRemaining'];
        }

        $pdf = $this->simplePdf($lines);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="stuckbema-leveranser-'.now()->format('Ymd-His').'.pdf"',
            'Cache-Control' => 'no-store',
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
            $this->markOrderItemsDelivered($id, $user);
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

    public function deliverOrderItem(Request $request, int $itemId): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'deliveries.update_status');
        $delivered = $this->markOrderItemDelivered($itemId, $user, $request->input('quantity'));

        return back()->with('success', $delivered > 0 ? 'Artikeln bockades av och lagret uppdaterades.' : 'Artikeln var redan avbockad.');
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

    public function updateVisibility(Request $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
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

        $updatedUser = DB::table('users')->where('id', $user->id)->first();
        $message = $data['visibility'] === 'online' ? 'Synlighet är Online.' : 'Synlighet är Offline.';

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'visibility' => $data['visibility'],
                'driverId' => $updatedUser?->id,
                'user' => $updatedUser ? $this->userRow($updatedUser) : null,
            ]);
        }

        return back()->with('success', $message);
    }

    public function storePushSubscription(Request $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $user = $this->requireUser($request);
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'permission' => ['nullable', 'string', 'max:40'],
            'userAgent' => ['nullable', 'string', 'max:500'],
        ]);

        $existingSubscription = DB::table('push_subscriptions')->where('endpoint', $data['endpoint'])->first();
        $subscriptionId = $request->input('id') ?: $existingSubscription?->id ?: 'psh_'.Str::uuid();

        DB::table('push_subscriptions')->updateOrInsert(['endpoint' => $data['endpoint']], [
            'id' => $subscriptionId,
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

        $result = app(WebPushNotifier::class)->sendToUsers([$user->id], [
            'notification' => [
                'title' => 'Pushnotiser aktiva',
                'body' => 'Den här enheten kan nu ta emot leveransnotiser.',
                'tag' => 'push-enabled',
            ],
            'data' => ['url' => '/'],
        ]);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Pushnotiser aktiverades.',
                'subscriptionId' => $subscriptionId,
                'result' => $result,
            ]);
        }

        return back()->with('success', 'Pushnotiser aktiverades.');
    }

    public function deletePushSubscription(Request $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
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

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Pushnotiser stängdes av för enheten.']);
        }

        return back()->with('success', 'Pushnotiser stängdes av för enheten.');
    }

    public function pushTest(Request $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
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

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Testnotis skickad: '.$result['sent'], 'result' => $result]);
        }

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
            'role' => ['required', Rule::in($this->allowedRoleInputs())],
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
            'role' => ['required', Rule::in($this->allowedRoleInputs())],
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
            'recipients' => $this->recipientRows(),
            'products' => $this->productRows(),
            'settings' => $settings,
            'roles' => $this->roleOptions(),
            'permissions' => $this->permissionsFor($user->role),
            'admin' => $this->adminPanelData($orders, $users, $drivers, $user),
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

    private function roleOptions(): array
    {
        return collect($this->roles)
            ->map(fn (string $role) => [
                'label' => $this->roleLabels[$role],
                'value' => $role,
            ])
            ->values()
            ->all();
    }

    private function allowedRoleInputs(): array
    {
        return array_values(array_unique([...$this->roles, ...array_keys($this->roleAliases)]));
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

    private function canCreateDeliveryPdf(object $user): bool
    {
        return in_array($this->normalizedRole($user->role), ['firmatecknare', 'admin', 'arbetsledare', 'personal', 'forare'], true);
    }

    private function statusText(string $status): string
    {
        return [
            'created' => 'Skapad',
            'assigned' => 'Tilldelad',
            'ongoing' => 'Pagar',
            'paused' => 'Pausad',
            'delivered' => 'Levererad',
            'cancelled' => 'Avbruten',
        ][$status] ?? $status;
    }

    private function allPermissions(): array
    {
        return [
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.change_password',
            'users.change_role',
            'roles.view',
            'roles.update',
            'deliveries.view_all',
            'deliveries.view_own',
            'deliveries.create',
            'deliveries.update',
            'deliveries.delete',
            'deliveries.assign_driver',
            'deliveries.update_status',
            'drivers.view',
            'drivers.create',
            'drivers.update',
            'drivers.delete',
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',
            'articles.view',
            'articles.create',
            'articles.update',
            'articles.delete',
            'work_orders.view',
            'work_orders.create',
            'work_orders.update',
            'work_orders.delete',
            'tracking.view',
            'tracking.create',
            'tracking.update',
            'tracking.send',
            'settings.view',
            'settings.update',
            'logs.view',
            'logs.export',
            'logs.security_view',
            'system.view_status',
            'system.manage_api',
            'system.full_access',
        ];
    }

    private function permissionMatrix(): array
    {
        return [
            'admin' => [
                'users.view',
                'users.create',
                'users.update',
                'users.delete',
                'users.change_password',
                'users.change_role',
                'roles.view',
                'roles.update',
                'deliveries.view_all',
                'deliveries.create',
                'deliveries.update',
                'deliveries.delete',
                'deliveries.assign_driver',
                'deliveries.update_status',
                'drivers.view',
                'drivers.create',
                'drivers.update',
                'drivers.delete',
                'customers.view',
                'customers.create',
                'customers.update',
                'customers.delete',
                'articles.view',
                'articles.create',
                'articles.update',
                'articles.delete',
                'work_orders.view',
                'work_orders.create',
                'work_orders.update',
                'work_orders.delete',
                'tracking.view',
                'tracking.create',
                'tracking.update',
                'tracking.send',
                'settings.view',
                'settings.update',
                'logs.view',
                'logs.export',
                'system.view_status',
                'system.manage_api',
            ],
            'arbetsledare' => [
                'deliveries.view_all',
                'deliveries.create',
                'deliveries.update',
                'deliveries.assign_driver',
                'deliveries.update_status',
                'drivers.view',
                'customers.view',
                'customers.create',
                'customers.update',
                'articles.view',
                'articles.create',
                'articles.update',
                'work_orders.view',
                'work_orders.create',
                'work_orders.update',
                'tracking.view',
                'tracking.create',
                'tracking.send',
                'logs.view',
            ],
            'personal' => [
                'deliveries.view_all',
                'deliveries.create',
                'deliveries.update',
                'customers.view',
                'customers.create',
                'customers.update',
                'articles.view',
                'work_orders.view',
                'work_orders.create',
                'tracking.view',
            ],
            'support' => [
                'users.view',
                'deliveries.view_all',
                'drivers.view',
                'customers.view',
                'articles.view',
                'work_orders.view',
                'tracking.view',
                'logs.view',
                'system.view_status',
            ],
            'forare' => [
                'deliveries.view_own',
                'deliveries.update_status',
                'tracking.view',
            ],
            'viewer' => [
                'deliveries.view_all',
                'customers.view',
                'articles.view',
                'work_orders.view',
                'tracking.view',
            ],
            'kund' => [
                'tracking.view',
            ],
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

    private function roleMatrix(): array
    {
        return collect($this->roles)
            ->map(function (string $role, int $index) {
                $permissions = $this->permissionsFor($role);

                return [
                    'role' => $role,
                    'label' => $this->roleLabels[$role],
                    'description' => $this->roleDescriptions[$role],
                    'level' => $index + 1,
                    'permissions' => $permissions,
                    'allowedPermissions' => array_values(array_filter(
                        array_keys($permissions),
                        fn (string $permission) => $permissions[$permission]
                    )),
                ];
            })
            ->values()
            ->all();
    }

    private function hasPermission(object $user, string $permission): bool
    {
        $permissions = $this->permissionsFor($user->role);

        return (bool) ($permissions['system.full_access'] ?? false) || (bool) ($permissions[$permission] ?? false);
    }

    private function requirePermission(Request $request, string $permission): object
    {
        $user = $this->requireUser($request);
        abort_unless($this->hasPermission($user, $permission), 403);

        return $user;
    }

    private function guardUserMutation(object $actor, object $target, ?string $newRole = null): void
    {
        $actorIsFirmatecknare = $this->hasPermission($actor, 'system.full_access');
        $targetIsFirmatecknare = $this->normalizedRole($target->role) === 'firmatecknare';
        $newRoleIsFirmatecknare = $newRole === 'firmatecknare';

        abort_if(($targetIsFirmatecknare || $newRoleIsFirmatecknare) && ! $actorIsFirmatecknare, 403);
    }

    private function adminPanelData($orders, $users, $drivers, object $user): array
    {
        return [
            'roleMatrix' => $this->hasPermission($user, 'roles.view') ? $this->roleMatrix() : [],
            'logs' => $this->hasPermission($user, 'logs.view') ? $this->logRows() : [],
            'workOrders' => $this->hasPermission($user, 'work_orders.view') ? $this->workOrderRows() : [],
            'articles' => $this->hasPermission($user, 'articles.view') ? $this->articleRows() : [],
            'customers' => $this->hasPermission($user, 'customers.view') ? $this->customerRows() : [],
            'drivers' => $this->hasPermission($user, 'drivers.view') ? $drivers : [],
            'trackingLinks' => $this->hasPermission($user, 'tracking.view') ? $this->trackingLinkRows() : [],
            'pushSubscriptions' => $this->hasPermission($user, 'settings.view') ? $this->pushSubscriptionRows() : [],
            'systemStatus' => $this->hasPermission($user, 'system.view_status') ? $this->systemStatus($orders, $users) : [],
            'apiEndpoints' => $this->hasPermission($user, 'system.view_status') ? $this->apiEndpoints() : [],
        ];
    }

    private function workOrderRows(): array
    {
        $events = Schema::hasTable('work_order_delivery_events')
            ? DB::table('work_order_delivery_events')->get()->groupBy('work_order_number')
            : collect();

        return DB::table('external_work_orders')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(function ($row) use ($events) {
                $workOrderEvents = $events->get($row->work_order_number, collect());

                return [
                    'workOrderNumber' => $row->work_order_number,
                    'source' => $row->source,
                    'recipientName' => $row->recipient_name,
                    'recipientPhone' => $row->recipient_phone,
                    'deliveryAddress' => $row->delivery_address,
                    'status' => $row->status,
                    'deliveredQuantity' => $workOrderEvents->sum(fn ($event) => $this->quantityValue($event->delivered_antal)),
                    'deliveryEvents' => $workOrderEvents->count(),
                    'lastDeliveredAt' => $workOrderEvents->max('delivered_at'),
                    'receivedAt' => $row->received_at,
                    'createdAt' => $row->created_at,
                    'updatedAt' => $row->updated_at,
                ];
            })
            ->values()
            ->all();
    }

    private function productRows(): array
    {
        if (! Schema::hasTable('products')) {
            return [];
        }

        return DB::table('products')
            ->orderBy('sku')
            ->get()
            ->map(fn ($row) => [
                'sku' => $row->sku,
                'title' => $row->title,
                'folder' => $row->folder,
                'imageUrl' => $row->primary_image_url ?: $this->imageUrl($row->primary_image_path),
                'imagePath' => $row->primary_image_path,
                'imageCount' => (int) $row->image_count,
                'stockTotal' => (int) $row->stock_total,
                'stockDelivered' => (int) $row->stock_delivered,
                'stockRemaining' => max(0, (int) $row->stock_total - (int) $row->stock_delivered),
            ])
            ->values()
            ->all();
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
            abort_if(! $item, 404);

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

    private function simplePdf(array $rawLines): string
    {
        $lines = [];
        foreach ($rawLines as $line) {
            $text = preg_replace('/\s+/', ' ', trim((string) $line));
            if ($text === '') {
                $lines[] = '';
                continue;
            }
            while (mb_strlen($text, 'UTF-8') > 96) {
                $lines[] = mb_substr($text, 0, 96, 'UTF-8');
                $text = mb_substr($text, 96, null, 'UTF-8');
            }
            $lines[] = $text;
        }

        $pages = array_chunk($lines, 54);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $kids = [];
        $objectId = 4;

        foreach ($pages as $pageLines) {
            $pageId = $objectId++;
            $contentId = $objectId++;
            $kids[] = "{$pageId} 0 R";

            $content = "BT\n/F1 9 Tf\n40 802 Td\n13 TL\n";
            foreach ($pageLines as $line) {
                $content .= '('.$this->pdfText($line).") Tj\nT*\n";
            }
            $content .= "ET";

            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = "<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_keys($objects) as $id) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function pdfText(string $value): string
    {
        $text = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function articleRows(): array
    {
        if (Schema::hasTable('products')) {
            return $this->productRows();
        }

        return DB::table('order_items')
            ->select('artikel', DB::raw('count(*) as usage_count'), DB::raw('max(updated_at) as updated_at'))
            ->whereNotNull('artikel')
            ->where('artikel', '<>', '')
            ->groupBy('artikel')
            ->orderBy('artikel')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'artikel' => $row->artikel,
                'usageCount' => (int) $row->usage_count,
                'updatedAt' => $row->updated_at,
            ])
            ->values()
            ->all();
    }

    private function customerRows(): array
    {
        return DB::table('orders')
            ->select('mottagare', 'tele', 'adress', DB::raw('count(*) as orders_count'), DB::raw('max(updated_at) as last_order_at'))
            ->whereNotNull('mottagare')
            ->groupBy('mottagare', 'tele', 'adress')
            ->orderByDesc('last_order_at')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->mottagare,
                'phone' => $row->tele,
                'address' => $row->adress,
                'ordersCount' => (int) $row->orders_count,
                'lastOrderAt' => $row->last_order_at,
            ])
            ->values()
            ->all();
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
                    'name' => $name,
                    'value' => $name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'address' => $addressByName[$key] ?? null,
                    'source' => 'användare',
                    'sourceLabel' => $this->roleLabel($user->role),
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
                    'name' => $name,
                    'value' => $name,
                    'phone' => $person->phone,
                    'email' => $person->email,
                    'address' => $addressByName[$key] ?? null,
                    'source' => $person->source ?: 'person',
                    'sourceLabel' => $person->role ? $this->roleLabel($person->role) : 'Mottagare',
                    'updatedAt' => $person->updated_at,
                ]);
            });

        $queryKey = $this->recipientKey($query);

        $deduplicated = $rows
            ->filter(function (array $row) use ($queryKey) {
                if ($queryKey === '') {
                    return true;
                }

                return str_contains($this->recipientKey($row['name']), $queryKey);
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
            ->all();
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

    private function trackingLinkRows(): array
    {
        return DB::table('tracking_links')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'token' => $row->token,
                'orderId' => $row->order_id,
                'active' => (bool) $row->active,
                'expiresAt' => $row->expires_at,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ])
            ->values()
            ->all();
    }

    private function pushSubscriptionRows(): array
    {
        return DB::table('push_subscriptions')
            ->leftJoin('users', 'users.id', '=', 'push_subscriptions.user_id')
            ->orderByDesc('push_subscriptions.updated_at')
            ->limit(100)
            ->get([
                'push_subscriptions.*',
                'users.email as user_email',
                'users.first_name as user_first_name',
                'users.last_name as user_last_name',
            ])
            ->map(fn ($row) => [
                'id' => $row->id,
                'user' => trim(($row->user_first_name ?? '').' '.($row->user_last_name ?? '')) ?: $row->user_email,
                'platform' => $row->platform,
                'provider' => $row->provider,
                'permission' => $row->permission,
                'enabled' => (bool) $row->enabled,
                'failureCount' => (int) $row->failure_count,
                'lastSeenAt' => $row->last_seen_at,
                'updatedAt' => $row->updated_at,
            ])
            ->values()
            ->all();
    }

    private function systemStatus($orders, $users): array
    {
        return [
            'environment' => app()->environment(),
            'laravelVersion' => app()->version(),
            'phpVersion' => PHP_VERSION,
            'database' => config('database.default'),
            'broadcasting' => config('broadcasting.default'),
            'queue' => config('queue.default'),
            'ordersTotal' => $orders->count(),
            'usersTotal' => $users->count(),
            'activeUsers' => $users->where('active', true)->count(),
        ];
    }

    private function apiEndpoints(): array
    {
        return [
            ['method' => 'GET', 'path' => '/api/orders', 'module' => 'Leveranser', 'status' => 'Aktiv'],
            ['method' => 'POST', 'path' => '/api/orders', 'module' => 'Leveranser', 'status' => 'Aktiv'],
            ['method' => 'PUT', 'path' => '/api/orders/{id}', 'module' => 'Leveranser', 'status' => 'Aktiv'],
            ['method' => 'DELETE', 'path' => '/api/orders/{id}', 'module' => 'Leveranser', 'status' => 'Aktiv'],
            ['method' => 'POST', 'path' => '/api/orders/{id}/location', 'module' => 'Tracking', 'status' => 'Aktiv'],
            ['method' => 'POST', 'path' => '/api/external/work-orders', 'module' => 'Arbetsordrar', 'status' => 'Aktiv'],
            ['method' => 'POST', 'path' => '/users', 'module' => 'Användare', 'status' => 'Aktiv'],
            ['method' => 'PUT', 'path' => '/users/{id}', 'module' => 'Användare', 'status' => 'Aktiv'],
            ['method' => 'PATCH', 'path' => '/users/{id}/password', 'module' => 'Användare', 'status' => 'Aktiv'],
            ['method' => 'DELETE', 'path' => '/users/{id}', 'module' => 'Användare', 'status' => 'Aktiv'],
            ['method' => 'PUT', 'path' => '/settings', 'module' => 'Inställningar', 'status' => 'Aktiv'],
            ['method' => 'POST', 'path' => '/push/subscription', 'module' => 'Pushnotiser', 'status' => 'Aktiv'],
        ];
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
                $rows[array_key_last($rows)]['details'] = Str::limit($rows[array_key_last($rows)]['details']."\n".$line, 1600, '');
            }

            if (count($rows) >= 200) {
                break;
            }
        }

        return $rows;
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
            $product = $this->productForArticle($article);
            $requestedQuantity = $this->quantityValue($quantity);

            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'artikel' => $article,
                'article' => $article,
                'description' => $article,
                'antal' => $quantity,
                'quantity' => $quantity,
                'sort_order' => $index,
                'work_order_number' => $item['workOrderNumber'] ?? null,
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

    private function orderRow(object $row): array
    {
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
                    'antal' => $item->antal,
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
                        'imagePath' => $product->primary_image_path,
                        'imageCount' => (int) $product->image_count,
                        'stockTotal' => (int) $product->stock_total,
                        'stockDelivered' => (int) $product->stock_delivered,
                        'stockRemaining' => max(0, (int) $product->stock_total - (int) $product->stock_delivered),
                    ] : null,
                ];
            })
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
            'desiredDeliveryDate' => $row->desired_delivery_date,
            'desiredDeliveryTime' => $row->desired_delivery_time ? substr((string) $row->desired_delivery_time, 0, 5) : null,
            'driverId' => $row->driver_id,
            'driverName' => $row->driver_name ?: $row->assigned_driver_id,
            'status' => $status,
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
