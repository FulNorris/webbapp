<?php

namespace App\Http\Controllers;

use App\Events\DriverVisibilityUpdated;
use App\Events\LocationUpdated;
use App\Services\ArbetsorderParser;
use App\Services\DeliveryOrderItemService;
use App\Services\LeveransKalkylService;
use App\Services\ProductResolver;
use App\Services\WorkOrderArticleService;
use App\Services\WebPushNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Dompdf\Dompdf;
use Dompdf\Options;

class InternalAppController extends Controller
{
    private const DASHBOARD_CACHE_SECONDS = 120;
    private const PRODUCT_CACHE_SECONDS = 600;
    private const SETTINGS_CACHE_SECONDS = 300;
    private const PROTECTED_ADMIN_EMAIL = 'admin@stuckbema.se';

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
        abort_unless($base && preg_match('/^[A-Za-z0-9_-]+$/', $folder) && preg_match('/^[A-Za-z0-9_.-]+$/', $file), 404);
        abort_unless(preg_match('/\.(?:jpg|jpeg|png|webp)$/i', $file), 404);

        [$folder, $file] = $this->normalizeProductImagePath($folder, $file);
        $path = realpath(base_path('produkter/'.$folder.'/'.$file));
        abort_unless($base && $path && str_starts_with($path, $base.DIRECTORY_SEPARATOR) && is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function normalizeProductImagePath(string $folder, string $file): array
    {
        if (strcasecmp($folder, 'L') === 0 && preg_match('/^TL\d+[A-Z]?(?:-\d+)?(?:_\d+)?\.(?:jpg|jpeg|png|webp)$/i', $file)) {
            return ['TL', $file];
        }

        return [$folder, $file];
    }

    public function deliveriesPdf(Request $request): \Illuminate\Http\Response
    {
        $user = $this->requireUser($request);
        abort_unless($this->canCreateDeliveryPdf($user), 403);

        $view = $request->query('view', 'work-orders');
        $status = $request->query('status', 'active');
        $period = $request->query('period', 'all');
        abort_unless(in_array($view, ['work-orders', 'deliveries'], true), 422);
        abort_unless(in_array($status, ['active', 'packed', 'delivered', 'all'], true), 422);
        abort_unless(in_array($period, ['day', 'week', 'month', 'quarter', 'year', 'all'], true), 422);

        [$periodStart, $periodEnd] = $this->pdfPeriodRange($period);

        $orders = DB::table('orders')
            ->leftJoin('users as creator_users', 'creator_users.id', '=', 'orders.created_by')
            ->select([
                'orders.*',
                DB::raw("trim(coalesce(creator_users.first_name, '') || ' ' || coalesce(creator_users.last_name, '')) as created_by_name"),
                'creator_users.email as created_by_email',
                'creator_users.role as created_by_role',
            ])
            ->when($status === 'active', fn ($query) => $query->whereIn('orders.status', ['created', 'assigned', 'ongoing', 'paused', 'packed']))
            ->when($status === 'packed', fn ($query) => $query->where('orders.status', 'packed'))
            ->when($status === 'delivered', fn ($query) => $query->where('orders.status', 'delivered'))
            ->when($periodStart, fn ($query) => $query->where('orders.created_at', '>=', $periodStart))
            ->when($periodEnd, fn ($query) => $query->where('orders.created_at', '<=', $periodEnd))
            ->orderByDesc('orders.created_at')
            ->limit(300)
            ->get();
        $orderRows = $orders->map(fn ($order) => $this->orderRow($order))->values();
        $document = $this->deliveryPdfDocumentData($orderRows, $user, $view, $status, $period, $periodStart, $periodEnd);
        $html = view('pdf.deliveries', $document)->render();
        $pdf = $this->renderHtmlPdf($html);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="stuckbema-'.$view.'-'.$status.'-'.now()->format('Ymd-His').'.pdf"',
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

        $this->forgetDashboardCache();
        $this->notifyDeliveryCreated($id);

        return back()->with('success', 'Leveransen skapades.');
    }

    public function workOrderLookup(Request $request, int $arbetsorderNr): \Illuminate\Http\JsonResponse
    {
        $this->requireAnyPermission($request, ['work_orders.view', 'deliveries.create', 'deliveries.update']);
        $orderQuery = DB::table('arbetsordrar')->where('arbetsorder_nr', $arbetsorderNr);
        if (Schema::hasColumn('arbetsordrar', 'hidden_at')) {
            $orderQuery->whereNull('hidden_at');
        }

        $order = $orderQuery->first();

        if (! $order) {
            return response()->json([
                'found' => false,
                'message' => 'Arbetsordern hittades inte.',
            ], 404);
        }

        $rows = DB::table('arbetsorder_rader')
            ->where('arbetsorder_id', $order->id)
            ->orderBy('rad_nr')
            ->orderBy('id')
            ->get();
        $article = trim((string) $request->query('artikel', ''));
        $match = $article !== ''
            ? app(LeveransKalkylService::class)->matchArbetsorderRad($arbetsorderNr, $article)
            : null;

        return response()->json([
            'found' => true,
            'arbetsorder' => [
                'id' => $order->id,
                'arbetsorderNr' => $order->arbetsorder_nr,
                'kontaktperson' => $order->kontaktperson,
                'telefon' => $order->telefon,
                'arbetsplats' => $order->arbetsplats,
                'postadress' => $order->postadress,
            ],
            'rows' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'artikel' => $row->artikel,
                'artikelNormalized' => $row->artikel_normalized,
                'antal' => $row->antal === null ? null : (float) $row->antal,
                'enhet' => $row->enhet,
                'rawLine' => $row->raw_line,
            ])->values(),
            'match' => $match ? [
                'id' => $match->id,
                'artikel' => $match->artikel,
                'artikelNormalized' => $match->artikel_normalized,
                'antal' => $match->antal === null ? null : (float) $match->antal,
                'enhet' => $match->enhet,
                'rawLine' => $match->raw_line,
            ] : null,
        ]);
    }

    public function workOrderArticles(Request $request, int $arbetsorderNr, WorkOrderArticleService $service): \Illuminate\Http\JsonResponse
    {
        $this->requireAnyPermission($request, ['work_orders.view', 'deliveries.create', 'deliveries.update']);

        return response()->json($service->responseFor($arbetsorderNr));
    }

    public function bulkDeleteWorkOrders(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'work_orders.delete');
        abort_unless(
            in_array($this->normalizedRole($user->role), ['firmatecknare', 'admin'], true) || $this->hasPermission($user, 'system.full_access'),
            403,
            'Endast admin och firmatecknare får radera arbetsordrar.'
        );

        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(['hide', 'delete'])],
            'internalIds' => ['array'],
            'internalIds.*' => ['integer', 'min:1'],
            'externalIds' => ['array'],
            'externalIds.*' => ['string', 'max:120'],
        ]);

        $internalIds = collect($data['internalIds'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
        $externalIds = collect($data['externalIds'] ?? [])
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        abort_if($internalIds->isEmpty() && $externalIds->isEmpty(), 422, 'Välj minst en arbetsorder.');

        DB::transaction(function () use ($data, $internalIds, $externalIds, $user) {
            if ($data['mode'] === 'hide') {
                $this->hideSelectedWorkOrders($internalIds, $externalIds, $user);

                return;
            }

            $this->deleteSelectedWorkOrders($internalIds, $externalIds);
        });

        $this->forgetDashboardCache();

        return back()->with('success', $data['mode'] === 'delete'
            ? 'Valda arbetsordrar raderades helt ur databasen.'
            : 'Valda arbetsordrar doldes från normal användning.');
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

        $this->forgetDashboardCache();

        return back()->with('success', 'Leveransen uppdaterades.');
    }

    public function updateOrderStatus(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requirePermission($request, 'deliveries.update_status');
        $data = $request->validate([
            'status' => ['required', Rule::in(['created', 'assigned', 'ongoing', 'paused', 'packed', 'delivered', 'cancelled'])],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
            'heading' => ['nullable', 'numeric'],
        ]);

        $shouldNotifyPacked = false;
        $shouldNotifyStarted = false;

        DB::transaction(function () use ($data, $id, $user, &$shouldNotifyPacked, &$shouldNotifyStarted) {
            $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $order, 404);

            if ($data['status'] !== 'delivered' && (($order->delivered_at ?? null) || strtolower((string) $order->status) === 'delivered')) {
                $this->reopenDeliveredOrder($order, $data['status'], $user);
            }

            $updates = [
                'status' => $data['status'],
                'updated_by' => $user->id,
                'updated_at' => now(),
            ];

            if ($data['status'] === 'ongoing') {
                $shouldNotifyStarted = strtolower((string) $order->status) !== 'ongoing';
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
                if ($location = $this->locationFromStatusRequest($data)) {
                    $updates['current_location'] = json_encode($location);
                }
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

            if ($data['status'] === 'packed') {
                $shouldNotifyPacked = strtolower((string) $order->status) !== 'packed';
                $updates['packed_at'] = $order->packed_at ?: now();
                $updates['packed_by'] = $order->packed_by ?: $user->id;
            }

            if ($data['status'] === 'delivered') {
                $updates['tracking_enabled'] = false;
                $updates['delivered_at'] = now();
                $updates['delivered_by'] = $user->id;
                DB::table('tracking_links')->where('order_id', $id)->update(['active' => false, 'updated_at' => now()]);
                $this->markOrderItemsDelivered($id, $user);
            } else {
                $updates['delivered_at'] = null;
                $updates['delivered_by'] = null;
            }

            DB::table('orders')->where('id', $id)->update($updates);
        });

        $this->forgetDashboardCache();
        $updatedOrder = DB::table('orders')->where('id', $id)->first();
        if ($updatedOrder) {
            $this->broadcastOrderLocation($updatedOrder);
        }

        if ($data['status'] === 'packed' && $updatedOrder && $shouldNotifyPacked) {
            $this->notifyPackedRecipient($updatedOrder, $user);
        }

        if ($data['status'] === 'ongoing' && $updatedOrder) {
            if ($shouldNotifyStarted) {
                $this->notifyStartedRecipient($updatedOrder, $user);
            }

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
        $this->forgetDashboardCache();

        return back()->with('success', $delivered > 0 ? 'Artikeln bockades av och lagret uppdaterades.' : 'Artikeln var redan avbockad.');
    }

    public function deleteOrder(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $this->requirePermission($request, 'deliveries.delete');
        DB::table('orders')->where('id', $id)->delete();
        $this->forgetDashboardCache();

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
        $this->forgetDashboardCache();

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
        $this->forgetDashboardCache();
        $updatedUser = DB::table('users')->where('id', $user->id)->first();

        DriverVisibilityUpdated::dispatch([
            'driverId' => $user->id,
            'driverName' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->email,
            'visibility' => $data['visibility'],
            'lastSeenAt' => now()->toIso8601String(),
        ]);

        if ($data['visibility'] === 'offline') {
            LocationUpdated::dispatch($this->userLocationPayload($updatedUser, false));

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

    public function updateVisibilityLocation(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->requireUser($request);
        abort_unless(($user->visibility ?? 'offline') === 'online', 409, 'Synlighet måste vara Online.');

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
            'heading' => ['nullable', 'numeric'],
        ]);

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

        DB::table('users')->where('id', $user->id)->update([
            'current_location' => json_encode($location),
            'location_updated_at' => now(),
            'last_seen_at' => now(),
            'updated_at' => now(),
        ]);

        $updatedUser = DB::table('users')->where('id', $user->id)->first();
        LocationUpdated::dispatch($this->userLocationPayload($updatedUser, true));

        return response()->json([
            'success' => true,
            'location' => $location,
        ]);
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
        $this->forgetDashboardCache();

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
        $this->forgetDashboardCache();

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
            'permissionAllow' => ['array'],
            'permissionAllow.*' => ['string', Rule::in($this->allPermissions())],
            'permissionDeny' => ['array'],
            'permissionDeny.*' => ['string', Rule::in($this->allPermissions())],
        ]);

        $email = strtolower(trim($data['email']));
        $role = $this->normalizedRole($data['role']);
        abort_if($role === 'firmatecknare' && ! $this->hasPermission($user, 'system.full_access'), 403);

        $password = Str::password(12);

        $insert = [
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
        ];

        if ($this->hasPermission($user, 'system.full_access') && Schema::hasColumn('users', 'permission_allow')) {
            $insert['permission_allow'] = json_encode($this->cleanPermissionList($data['permissionAllow'] ?? []));
            $insert['permission_deny'] = json_encode($this->cleanPermissionList($data['permissionDeny'] ?? []));
        }

        DB::table('users')->insert($insert);
        $this->forgetDashboardCache();

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
            'permissionAllow' => ['array'],
            'permissionAllow.*' => ['string', Rule::in($this->allPermissions())],
            'permissionDeny' => ['array'],
            'permissionDeny.*' => ['string', Rule::in($this->allPermissions())],
        ]);

        $target = DB::table('users')->where('id', $id)->first();
        abort_if(! $target, 404);
        $role = $this->normalizedRole($data['role']);
        $this->guardUserMutation($user, $target, $role);

        $email = strtolower(trim($data['email']));
        $updates = [
            'email' => $email,
            'email_key' => $email,
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'] ?? '',
            'phone' => $data['phone'] ?? null,
            'role' => $role,
            'active' => $request->boolean('active'),
            'updated_at' => now(),
        ];

        if ($this->hasPermission($user, 'system.full_access') && Schema::hasColumn('users', 'permission_allow')) {
            $updates['permission_allow'] = json_encode($this->cleanPermissionList($data['permissionAllow'] ?? []));
            $updates['permission_deny'] = json_encode($this->cleanPermissionList($data['permissionDeny'] ?? []));
        }

        DB::table('users')->where('id', $id)->update($updates);
        $this->forgetDashboardCache();

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
        $this->forgetDashboardCache();

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
        $this->forgetDashboardCache();

        return back()->with('success', 'Användaren togs bort.');
    }

    public function updateProfile(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requireUser($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id, 'id')],
            'phone' => ['nullable', 'string', 'max:80'],
            'profileImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $email = strtolower(trim($data['email']));
        $updates = [
            'email' => $email,
            'email_key' => $email,
            'phone' => $data['phone'] ?? null,
            'updated_at' => now(),
        ];

        if ($request->hasFile('profileImage')) {
            $image = $request->file('profileImage');
            abort_if(! $image || ! $image->isValid(), 422, 'Profilbilden kunde inte laddas upp.');

            $extension = strtolower($image->getClientOriginalExtension() ?: $image->extension() ?: 'jpg');
            $filename = $user->id.'-'.Str::random(16).'.'.$extension;
            $directory = public_path('profile-images');

            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            $image->move($directory, $filename);
            $this->deletePublicProfileImage($user->photo_url ?? null);

            $updates['image_path'] = $directory.DIRECTORY_SEPARATOR.$filename;
            $updates['photo_url'] = '/profile-images/'.$filename;
        }

        DB::table('users')->where('id', $user->id)->update($updates);
        $this->forgetDashboardCache();

        return back()->with('success', 'Profilen sparades.');
    }

    public function deleteProfileImage(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requireUser($request);
        $this->deletePublicProfileImage($user->photo_url ?? null);

        DB::table('users')->where('id', $user->id)->update([
            'image_path' => null,
            'photo_url' => null,
            'updated_at' => now(),
        ]);
        $this->forgetDashboardCache();

        return back()->with('success', 'Profilbilden togs bort.');
    }

    public function sendInternalMessage(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requireUser($request);
        $data = $request->validate([
            'recipientId' => ['required', 'string'],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        if ($data['recipientId'] === '__all__') {
            abort_unless($this->hasPermission($user, 'system.full_access'), 403, 'Endast firmatecknare får skicka meddelande till alla.');

            $recipientIds = DB::table('users')
                ->where('active', true)
                ->where('id', '<>', $user->id)
                ->pluck('id')
                ->filter()
                ->values();

            abort_if($recipientIds->isEmpty(), 422, 'Det finns inga aktiva mottagare att skicka till.');

            $now = now();
            DB::table('internal_messages')->insert($recipientIds->map(fn ($recipientId) => [
                'sender_id' => $user->id,
                'recipient_id' => $recipientId,
                'subject' => trim($data['subject']),
                'body' => trim($data['body']),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
            $this->sendPrivateMessagePush($recipientIds->all(), trim($data['subject']));

            return back()->with('success', 'Meddelandet skickades till alla aktiva användare.');
        }

        abort_if($data['recipientId'] === $user->id, 422, 'Du kan inte skicka meddelande till dig själv.');
        $recipient = DB::table('users')->where('id', $data['recipientId'])->first();
        abort_if(! $recipient, 404);
        abort_if($this->isProtectedAdminUser($recipient) && ! $this->canSeeProtectedAdmin($user), 404);

        DB::table('internal_messages')->insert([
            'sender_id' => $user->id,
            'recipient_id' => $data['recipientId'],
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->sendPrivateMessagePush([$data['recipientId']], trim($data['subject']));

        return back()->with('success', 'Meddelandet skickades.');
    }

    public function markInternalMessageRead(Request $request, int $message): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requireUser($request);

        DB::table('internal_messages')
            ->where('id', $message)
            ->where('recipient_id', $user->id)
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Meddelandet markerades som läst.');
    }

    public function deleteInternalMessage(Request $request, int $message): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requireUser($request);
        $row = DB::table('internal_messages')
            ->where('id', $message)
            ->where(function ($query) use ($user) {
                $query->where('recipient_id', $user->id)->orWhere('sender_id', $user->id);
            })
            ->first();
        abort_if(! $row, 404);

        $updates = ['updated_at' => now()];
        if ($row->recipient_id === $user->id) {
            $updates['recipient_deleted_at'] = now();
        }
        if ($row->sender_id === $user->id) {
            $updates['sender_deleted_at'] = now();
        }

        DB::table('internal_messages')->where('id', $message)->update($updates);

        return back()->with('success', 'Meddelandet flyttades till raderade.');
    }

    public function restoreInternalMessage(Request $request, int $message): \Illuminate\Http\RedirectResponse
    {
        $user = $this->requireUser($request);
        $row = DB::table('internal_messages')
            ->where('id', $message)
            ->where(function ($query) use ($user) {
                $query->where('recipient_id', $user->id)->orWhere('sender_id', $user->id);
            })
            ->first();
        abort_if(! $row, 404);

        $updates = ['updated_at' => now()];
        if ($row->recipient_id === $user->id) {
            $updates['recipient_deleted_at'] = null;
        }
        if ($row->sender_id === $user->id) {
            $updates['sender_deleted_at'] = null;
        }

        DB::table('internal_messages')->where('id', $message)->update($updates);

        return back()->with('success', 'Meddelandet återställdes.');
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
        $this->forgetDashboardCache();

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
        $orderModels = $this->rememberDashboard('orders', 20, fn () => DB::table('orders')->orderByDesc('created_at')->limit(300)->get());
        $orders = $this->orderRows($orderModels);
        $users = $this->visibleUsersQuery($user)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($row) => $this->userRow($row))
            ->values();
        $purchases = $this->hasPermission($user, 'purchases.view') ? $this->purchaseRows() : [];
        $settings = $this->settings();
        $statusCounts = $orders->countBy('status');
        $roleCounts = $users->countBy('roleKey');
        $drivers = $users->filter(fn ($row) => in_array($row['roleKey'], ['forare', 'admin', 'arbetsledare', 'firmatecknare'], true) && $row['active'])->values();

        return [
            'user' => $this->userRow($user),
            'orders' => $orders,
            'users' => $users,
            'purchases' => $purchases,
            'drivers' => $drivers,
            'recipients' => $this->recipientRows(null, $user),
            'products' => $this->productRows(),
            'settings' => $settings,
            'roles' => $this->roleOptions(),
            'permissions' => $this->permissionsForUser($user),
            'admin' => $this->adminPanelData($orders, $users, $drivers, $user),
            'messages' => $this->messageRows($user),
            'push' => [
                'enabled' => (bool) ($settings['allowPushNotifications'] && config('services.webpush.public_key') && config('services.webpush.private_key')),
                'publicKey' => config('services.webpush.public_key'),
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
                'purchasesTotal' => count($purchases),
                'statusCounts' => $statusCounts,
                'roleCounts' => $roleCounts,
            ],
        ];
    }

    private function rememberDashboard(string $key, int $seconds, callable $callback): mixed
    {
        return Cache::remember("dashboard:{$key}", $seconds, $callback);
    }

    private function forgetDashboardCache(): void
    {
        foreach ([
            'dashboard:settings',
            'dashboard:orders',
            'dashboard:purchases',
            'dashboard:products',
            'dashboard:articles',
            'dashboard:customers',
            'dashboard:recipients:all',
            'dashboard:work_orders',
            'dashboard:push_subscriptions',
        ] as $key) {
            Cache::forget($key);
        }
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
            'ongoing' => 'Pågår',
            'paused' => 'Pausad',
            'packed' => 'Packad',
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
            'purchases.view',
            'purchases.create',
            'purchases.update',
            'purchases.delete',
            'purchases.update_status',
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
                'purchases.view',
                'purchases.create',
                'purchases.update',
                'purchases.delete',
                'purchases.update_status',
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
                'purchases.view',
                'purchases.create',
                'purchases.update',
                'purchases.delete',
                'purchases.update_status',
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
                'purchases.view',
                'purchases.create',
                'purchases.update',
                'purchases.update_status',
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
                'purchases.view',
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

    private function permissionsForUser(object $user): array
    {
        $permissions = $this->permissionsFor($user->role ?? null);
        $allow = $this->cleanPermissionList($this->jsonArray($user->permission_allow ?? []));
        $deny = $this->cleanPermissionList($this->jsonArray($user->permission_deny ?? []));

        foreach ($allow as $permission) {
            $permissions[$permission] = true;
        }

        foreach ($deny as $permission) {
            if ($permission !== 'system.full_access') {
                $permissions[$permission] = false;
            }
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
        $permissions = $this->permissionsForUser($user);

        return (bool) ($permissions['system.full_access'] ?? false) || (bool) ($permissions[$permission] ?? false);
    }

    private function cleanPermissionList(array $permissions): array
    {
        $allowed = array_fill_keys($this->allPermissions(), true);

        return array_values(array_unique(array_filter(
            array_map('strval', $permissions),
            fn (string $permission) => isset($allowed[$permission])
        )));
    }

    private function jsonArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function requirePermission(Request $request, string $permission): object
    {
        $user = $this->requireUser($request);
        abort_unless($this->hasPermission($user, $permission), 403);

        return $user;
    }

    private function requireAnyPermission(Request $request, array $permissions): object
    {
        $user = $this->requireUser($request);
        abort_unless(collect($permissions)->contains(fn (string $permission) => $this->hasPermission($user, $permission)), 403);

        return $user;
    }

    private function guardUserMutation(object $actor, object $target, ?string $newRole = null): void
    {
        abort_if($this->isProtectedAdminUser($target) && ! $this->canSeeProtectedAdmin($actor), 404);

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
            'workOrders' => $this->hasPermission($user, 'work_orders.view') ? $this->workOrderRows($user) : [],
            'articles' => $this->hasPermission($user, 'articles.view') ? $this->articleRows() : [],
            'customers' => $this->hasPermission($user, 'customers.view') ? $this->customerRows() : [],
            'drivers' => $this->hasPermission($user, 'drivers.view') ? $drivers : [],
            'trackingLinks' => $this->hasPermission($user, 'tracking.view') ? $this->trackingLinkRows() : [],
            'pushSubscriptions' => $this->hasPermission($user, 'settings.view') ? $this->pushSubscriptionRows($user) : [],
            'systemStatus' => $this->hasPermission($user, 'system.view_status') ? $this->systemStatus($orders, $users) : [],
            'apiEndpoints' => $this->hasPermission($user, 'system.view_status') ? $this->apiEndpoints() : [],
        ];
    }

    private function workOrderRows(object $user): array
    {
        $canSeeHidden = in_array($this->normalizedRole($user->role), ['firmatecknare', 'admin'], true) || $this->hasPermission($user, 'system.full_access');

        return $this->rememberDashboard('work_orders:'.($canSeeHidden ? 'manage' : 'visible'), self::DASHBOARD_CACHE_SECONDS, function () use ($canSeeHidden) {
            $events = Schema::hasTable('work_order_delivery_events')
                ? DB::table('work_order_delivery_events')->get()->groupBy('work_order_number')
                : collect();

            $externalRows = Schema::hasTable('external_work_orders')
                ? tap(DB::table('external_work_orders'), function ($query) use ($canSeeHidden) {
                    if (! $canSeeHidden && Schema::hasColumn('external_work_orders', 'hidden_at')) {
                        $query->whereNull('hidden_at');
                    }
                })
                    ->orderByDesc('updated_at')
                    ->limit(200)
                    ->get()
                    ->map(function ($row) use ($events) {
                        $workOrderNumber = (string) $row->work_order_number;
                        $workOrderEvents = $events->get($workOrderNumber, collect());

                        return [
                            'id' => $workOrderNumber,
                            'sourceType' => 'external',
                            'isInternal' => false,
                            'workOrderNumber' => $workOrderNumber,
                            'source' => $row->source ?: 'external',
                            'recipientName' => $row->recipient_name,
                            'recipientPhone' => $row->recipient_phone,
                            'deliveryAddress' => $row->delivery_address,
                            'status' => $row->status ?: 'received',
                            'rowCount' => null,
                            'articleSummary' => '',
                            'deliveredQuantity' => $workOrderEvents->sum(fn ($event) => $this->quantityValue($event->delivered_antal)),
                            'deliveryEvents' => $workOrderEvents->count(),
                            'lastDeliveredAt' => $workOrderEvents->max('delivered_at'),
                            'receivedAt' => $row->received_at,
                            'createdAt' => $row->created_at,
                            'updatedAt' => $row->updated_at,
                            'hiddenAt' => property_exists($row, 'hidden_at') ? $row->hidden_at : null,
                        ];
                    })
                : collect();

            $internalWorkOrders = Schema::hasTable('arbetsordrar')
                ? tap(DB::table('arbetsordrar'), function ($query) use ($canSeeHidden) {
                    if (! $canSeeHidden && Schema::hasColumn('arbetsordrar', 'hidden_at')) {
                        $query->whereNull('hidden_at');
                    }
                })
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->limit(200)
                    ->get()
                : collect();

            $articleRowsByWorkOrderId = Schema::hasTable('arbetsorder_rader') && $internalWorkOrders->isNotEmpty()
                ? DB::table('arbetsorder_rader')
                    ->whereIn('arbetsorder_id', $internalWorkOrders->pluck('id')->all())
                    ->orderBy('arbetsorder_id')
                    ->orderBy('rad_nr')
                    ->orderBy('id')
                    ->get(['arbetsorder_id', 'artikel', 'antal', 'enhet'])
                    ->groupBy('arbetsorder_id')
                : collect();

            $internalRows = $internalWorkOrders->map(function ($row) use ($events, $articleRowsByWorkOrderId) {
                $workOrderNumber = (string) $row->arbetsorder_nr;
                $workOrderEvents = $events->get($workOrderNumber, collect());
                $articleRows = $articleRowsByWorkOrderId->get($row->id, collect());

                return [
                    'id' => $row->id,
                    'sourceType' => 'internal',
                    'isInternal' => true,
                    'workOrderNumber' => $workOrderNumber,
                    'source' => $row->is_kopia ? 'intern kopia' : 'intern',
                    'recipientName' => $row->kontaktperson,
                    'recipientPhone' => $row->telefon,
                    'deliveryAddress' => trim(implode(', ', array_filter([$row->arbetsplats, $row->postadress]))),
                    'status' => $this->workOrderStatus($row, $articleRows),
                    'rowCount' => $articleRows->count(),
                    'articleSummary' => $articleRows
                        ->map(fn ($article) => trim(($article->artikel ?: 'Artikel').' '.($article->antal !== null ? $this->pdfQuantity($article->antal) : '').' '.($article->enhet ?: '')))
                        ->filter()
                        ->take(4)
                        ->implode(', '),
                    'deliveredQuantity' => $workOrderEvents->sum(fn ($event) => $this->quantityValue($event->delivered_antal)),
                    'deliveryEvents' => $workOrderEvents->count(),
                    'lastDeliveredAt' => $workOrderEvents->max('delivered_at'),
                    'receivedAt' => $row->utskriftsdatum,
                    'createdAt' => $row->created_at,
                    'updatedAt' => $row->updated_at,
                    'hiddenAt' => property_exists($row, 'hidden_at') ? $row->hidden_at : null,
                ];
            });

            return $externalRows
                ->concat($internalRows)
                ->sortByDesc('updatedAt')
                ->values()
                ->all();
        });
    }

    private function hideSelectedWorkOrders(\Illuminate\Support\Collection $internalIds, \Illuminate\Support\Collection $externalIds, object $user): void
    {
        $timestamp = now();

        if ($internalIds->isNotEmpty() && Schema::hasTable('arbetsordrar') && Schema::hasColumn('arbetsordrar', 'hidden_at')) {
            DB::table('arbetsordrar')
                ->whereIn('id', $internalIds->all())
                ->update([
                    'hidden_at' => $timestamp,
                    'hidden_by' => $user->id,
                    'updated_at' => $timestamp,
                ]);
        }

        if ($externalIds->isNotEmpty() && Schema::hasTable('external_work_orders') && Schema::hasColumn('external_work_orders', 'hidden_at')) {
            DB::table('external_work_orders')
                ->whereIn('work_order_number', $externalIds->all())
                ->update([
                    'hidden_at' => $timestamp,
                    'hidden_by' => $user->id,
                    'updated_at' => $timestamp,
                ]);
        }
    }

    private function deleteSelectedWorkOrders(\Illuminate\Support\Collection $internalIds, \Illuminate\Support\Collection $externalIds): void
    {
        if ($internalIds->isNotEmpty() && Schema::hasTable('arbetsordrar')) {
            $numbers = DB::table('arbetsordrar')
                ->whereIn('id', $internalIds->all())
                ->pluck('arbetsorder_nr')
                ->map(fn ($number) => (string) $number)
                ->filter()
                ->values();

            if ($numbers->isNotEmpty() && Schema::hasTable('work_order_delivery_events')) {
                DB::table('work_order_delivery_events')->whereIn('work_order_number', $numbers->all())->delete();
            }

            DB::table('arbetsordrar')->whereIn('id', $internalIds->all())->delete();
        }

        if ($externalIds->isNotEmpty() && Schema::hasTable('external_work_orders')) {
            if (Schema::hasTable('work_order_delivery_events')) {
                DB::table('work_order_delivery_events')->whereIn('work_order_number', $externalIds->all())->delete();
            }

            DB::table('external_work_orders')->whereIn('work_order_number', $externalIds->all())->delete();
        }
    }

    private function workOrderStatus(object $workOrder, $articleRows): string
    {
        if ($articleRows->isEmpty()) {
            return 'saknar artiklar';
        }

        if (! empty($workOrder->fardig_datum)) {
            return 'planerad';
        }

        return 'mottagen';
    }

    private function purchaseRows(): array
    {
        if (! Schema::hasTable('purchases')) {
            return [];
        }

        return $this->rememberDashboard('purchases', self::DASHBOARD_CACHE_SECONDS, fn () => DB::table('purchases')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(300)
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'quantity' => (int) $row->quantity,
                    'itemName' => $row->item_name,
                    'storeName' => $row->store_name,
                    'supplier' => property_exists($row, 'supplier') ? $row->supplier : null,
                    'store' => property_exists($row, 'store') ? $row->store : null,
                    'city' => property_exists($row, 'city') ? $row->city : null,
                    'sku' => property_exists($row, 'sku') ? $row->sku : null,
                    'productUrl' => property_exists($row, 'product_url') ? $row->product_url : null,
                    'imageUrl' => property_exists($row, 'image_url') ? $row->image_url : null,
                    'thumbnailUrl' => property_exists($row, 'thumbnail_path') && $row->thumbnail_path ? '/'.$row->thumbnail_path : null,
                    'mapsLabel' => property_exists($row, 'maps_label') ? $row->maps_label : null,
                    'mapsUrl' => property_exists($row, 'maps_url') ? $row->maps_url : null,
                    'unitPrice' => property_exists($row, 'unit_price') ? $row->unit_price : null,
                    'currency' => property_exists($row, 'currency') ? $row->currency : 'SEK',
                    'vatRate' => property_exists($row, 'vat_rate') ? $row->vat_rate : 25,
                    'totalNet' => property_exists($row, 'total_net') ? $row->total_net : 0,
                    'totalVat' => property_exists($row, 'total_vat') ? $row->total_vat : 0,
                    'totalGross' => property_exists($row, 'total_gross') ? $row->total_gross : 0,
                    'availabilityAtSelection' => property_exists($row, 'availability_at_selection') ? $row->availability_at_selection : null,
                    'selectedAt' => property_exists($row, 'selected_at') ? $row->selected_at : null,
                    'fetchedAt' => property_exists($row, 'fetched_at') ? $row->fetched_at : null,
                    'deliveryAddress' => $row->delivery_address,
                    'recipientName' => $row->recipient_name,
                    'recipientPhone' => property_exists($row, 'recipient_phone') ? $row->recipient_phone : null,
                    'workOrderNumber' => property_exists($row, 'work_order_number') ? $row->work_order_number : null,
                    'status' => $row->status ?: 'planned',
                    'notes' => $row->notes,
                    'createdAt' => $row->created_at,
                    'updatedAt' => $row->updated_at,
                ])
                ->values()
                ->all());
    }

    private function productRows(): array
    {
        if (! Schema::hasTable('products')) {
            return [];
        }

        return $this->rememberDashboard('products', self::PRODUCT_CACHE_SECONDS, fn () => DB::table('products')
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
                ->all());
    }

    private function productForArticle(?string $article): ?object
    {
        return app(ProductResolver::class)->forArticle($article);
    }

    private function notifyPackedRecipient(object $order, object $actor): void
    {
        $recipient = $this->recipientUserForOrder($order);
        $message = $this->packedNotificationMessage($order);

        if ($recipient) {
            DB::table('internal_messages')->insert([
                'sender_id' => $actor->id,
                'recipient_id' => $recipient->id,
                'order_id' => $order->id,
                'subject' => 'Leveransen är packad',
                'body' => $message,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                $pushBody = $this->packedPushBody($order);
                app(WebPushNotifier::class)->sendToUsers([$recipient->id], [
                    'notification' => [
                        'title' => 'Du har en bokad leverans',
                        'body' => $pushBody,
                        'tag' => 'order-packed-'.$order->id,
                        'requireInteraction' => true,
                    ],
                    'data' => [
                        'url' => '/',
                        'orderId' => $order->id,
                        'type' => 'order_packed',
                        'requireInteraction' => true,
                    ],
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Pushnotis för packad leverans kunde inte skickas.', [
                    'order_id' => $order->id,
                    'recipient_id' => $recipient->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function notifyStartedRecipient(object $order, object $actor): void
    {
        $recipient = $this->recipientUserForOrder($order);
        if (! $recipient) {
            return;
        }

        $eta = $this->estimatedDeliveryTimeText($order);
        $message = $this->startedNotificationMessage($order, $eta);

        DB::table('internal_messages')->insert([
            'sender_id' => $actor->id,
            'recipient_id' => $recipient->id,
            'order_id' => $order->id,
            'subject' => 'Leveransen är på väg',
            'body' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(WebPushNotifier::class)->sendToUsers([$recipient->id], [
                'notification' => [
                    'title' => 'Du har en bokad leverans',
                    'body' => $this->startedPushBody($order, $eta),
                    'tag' => 'order-started-'.$order->id,
                    'requireInteraction' => true,
                ],
                'data' => [
                    'url' => '/',
                    'orderId' => $order->id,
                    'type' => 'order_started',
                    'requireInteraction' => true,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Pushnotis för startad leverans kunde inte skickas.', [
                'order_id' => $order->id,
                'recipient_id' => $recipient->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function sendPrivateMessagePush(array $recipientIds, string $subject): void
    {
        try {
            app(WebPushNotifier::class)->sendToUsers($recipientIds, [
                'notification' => [
                    'title' => 'Du har ett privat meddelande',
                    'body' => $subject !== '' ? $subject : 'Du har fått ett nytt meddelande.',
                    'tag' => 'private-message-'.md5(implode(',', $recipientIds).$subject.now()->timestamp),
                    'requireInteraction' => true,
                ],
                'data' => [
                    'url' => '/',
                    'type' => 'private_message',
                    'requireInteraction' => true,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Pushnotis för privat meddelande kunde inte skickas.', [
                'recipient_count' => count($recipientIds),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function startedNotificationMessage(object $order, string $eta): string
    {
        $items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $recipient = $this->normalizeSwedishText($order->mottagare ?: ($order->recipient_name ?? ''));
        $address = $this->normalizeSwedishText($order->adress ?: ($order->delivery_address ?? '-'));
        $articleRows = $items->isNotEmpty()
            ? $items->values()->map(fn ($item, int $index) => $this->packedNotificationTableLine($item, $index + 1))->implode("\n")
            : 'Inga artiklar registrerade.';

        return trim(implode("\n", [
            'STUCKBEMA',
            'Leveransen är på väg',
            '',
            'Hej '.$recipient,
            '',
            'Din bokade leverans är på väg till dig med en ca tid: '.$eta.'.',
            '',
            'Leverans',
            'Leverans-ID: '.$order->id,
            'Mottagare: '.($recipient !== '' ? $recipient : '-'),
            'Adress: '.$address,
            'Tracking: '.(($order->tracking_url ?? null) ?: '-'),
            '',
            'Inlagda artiklar',
            'Nr | Artikel | Antal | Enhet | Arbetsorder',
            str_repeat('-', 72),
            $articleRows,
        ]));
    }

    private function startedPushBody(object $order, string $eta): string
    {
        $recipient = $order->mottagare ?: ($order->recipient_name ?? 'din leverans');
        $body = 'Hej '.$recipient.'. Leveransen är på väg till dig med ca tid: '.$eta.'.';

        return Str::limit($body, 220, '');
    }

    private function packedNotificationMessage(object $order): string
    {
        $items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $recipient = $this->normalizeSwedishText($order->mottagare ?: ($order->recipient_name ?? '-'));
        $address = $this->normalizeSwedishText($order->adress ?: ($order->delivery_address ?? '-'));
        $time = $this->deliveryTimeText($order);
        $articleRows = $items->isNotEmpty()
            ? $items->values()->map(fn ($item, int $index) => $this->packedNotificationTableLine($item, $index + 1))->implode("\n")
            : 'Inga artiklar registrerade.';

        return trim(implode("\n", [
            'STUCKBEMA',
            'Leveransen är packad',
            '',
            'Hej '.$recipient,
            '',
            'Bilen är packad och leveransen beräknas köras enligt tiden nedan.',
            '',
            'Leverans',
            'Leverans-ID: '.$order->id,
            'Mottagare: '.$recipient,
            'Adress: '.$address,
            'Beräknad leveranstid: '.$time,
            '',
            'Packat i bilen',
            'Nr | Artikel | Antal | Enhet | Arbetsorder',
            $articleRows,
            '',
            'Tack för att du är redo att ta emot leveransen.',
        ]));
    }

    private function packedNotificationItemLine(object $item): string
    {
        $article = Str::of((string) ($item->artikel ?? 'Artikel'))->squish()->toString();
        $quantity = $this->packedNotificationQuantity($item);
        $workOrder = Str::of((string) (($item->work_order_number ?? null) ?: ($item->arbetsorder_nr ?? '')))->squish()->toString();
        $suffix = $workOrder !== '' ? ' (arbetsorder '.$workOrder.')' : '';

        return trim($article.': '.$quantity.$suffix);
    }

    private function packedNotificationTableLine(object $item, int $index): string
    {
        $article = $this->normalizeSwedishText(Str::of((string) ($item->artikel ?? 'Artikel'))->squish()->toString());
        $quantity = Str::of((string) (($item->antal ?? null) ?: ($item->requested_quantity ?? '?')))->squish()->toString();
        $unit = Str::of((string) ($item->enhet ?? '-'))->squish()->toString();
        $workOrder = Str::of((string) (($item->work_order_number ?? null) ?: ($item->arbetsorder_nr ?? '-')))->squish()->toString();

        return implode(' | ', [
            (string) $index,
            $article !== '' ? $article : 'Artikel',
            $quantity !== '' ? $quantity : '?',
            $unit !== '' ? $unit : '-',
            $workOrder !== '' ? $workOrder : '-',
        ]);
    }

    private function packedNotificationQuantity(object $item): string
    {
        $quantity = Str::of((string) (($item->antal ?? null) ?: ($item->requested_quantity ?? '')))->squish()->toString();
        $unit = Str::of((string) ($item->enhet ?? ''))->squish()->toString();

        if ($quantity === '') {
            $quantity = '?';
        }

        return trim($quantity.' '.$unit);
    }

    private function packedPushBody(object $order): string
    {
        $items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->map(fn ($item) => $this->packedNotificationItemLine($item))
            ->filter()
            ->values();

        $recipient = $order->mottagare ?? 'Din leverans';
        $body = trim($recipient.' är packad och beräknas levereras '.$this->deliveryTimeText($order).'.');

        if ($items->isNotEmpty()) {
            $body .= ' Packat: '.$items->implode(', ');
        }

        return Str::limit($body, 220, '');
    }

    private function deliveryTimeText(object $order): string
    {
        $deliveryTime = trim(implode(' ', array_filter([
            $order->desired_delivery_date ?? null,
            $order->desired_delivery_time ? substr((string) $order->desired_delivery_time, 0, 5) : null,
        ])));

        return $deliveryTime !== '' ? $deliveryTime : '-';
    }

    private function estimatedDeliveryTimeText(object $order): string
    {
        $route = $this->routeEstimateForOrder($order);
        if (! $route) {
            return 'uppgift saknas';
        }

        $minutes = max(1, (int) ceil($route['durationSeconds'] / 60));
        $arrival = now()->addMinutes($minutes);
        $distanceKm = $route['distanceMeters'] > 0 ? number_format($route['distanceMeters'] / 1000, 1, ',', '') : null;
        $distance = $distanceKm ? ' ('.$distanceKm.' km)' : '';

        return 'cirka '.$minutes.' minuter, ungefär kl. '.$arrival->format('H:i').$distance;
    }

    private function routeEstimateForOrder(object $order): ?array
    {
        $origin = $this->locationCoordinates($this->decodeJson($order->current_location ?? null) ?: []);
        $destination = $this->geocodeDeliveryAddress($order->adress ?: ($order->delivery_address ?? null));

        if (! $origin || ! $destination) {
            return null;
        }

        $cacheKey = 'route-estimate:'.md5(implode(',', [
            round($origin['lat'], 4),
            round($origin['lng'], 4),
            round($destination['lat'], 4),
            round($destination['lng'], 4),
        ]));

        return Cache::remember($cacheKey, 120, function () use ($origin, $destination) {
            try {
                $url = sprintf(
                    'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F',
                    $origin['lng'],
                    $origin['lat'],
                    $destination['lng'],
                    $destination['lat']
                );
                $response = Http::timeout(4)
                    ->acceptJson()
                    ->get($url, [
                        'overview' => 'false',
                        'alternatives' => 'false',
                        'steps' => 'false',
                    ]);

                if (! $response->ok()) {
                    return null;
                }

                $route = $response->json('routes.0');
                if (! is_array($route) || ! isset($route['duration'])) {
                    return null;
                }

                return [
                    'durationSeconds' => (float) $route['duration'],
                    'distanceMeters' => (float) ($route['distance'] ?? 0),
                ];
            } catch (\Throwable $exception) {
                Log::warning('Rutt-ETA kunde inte hämtas.', ['message' => $exception->getMessage()]);

                return null;
            }
        });
    }

    private function geocodeDeliveryAddress(?string $address): ?array
    {
        $address = Str::of((string) $address)->squish()->toString();
        if ($address === '') {
            return null;
        }

        $query = str_contains(Str::lower($address), 'stockholm') ? $address : $address.', Stockholm, Sverige';
        $cacheKey = 'geocode:delivery:'.md5(Str::lower($query));

        return Cache::remember($cacheKey, 86400, function () use ($query) {
            try {
                $response = Http::timeout(4)
                    ->acceptJson()
                    ->withHeaders(['User-Agent' => 'StuckbemaLeverans/1.0'])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $query,
                        'format' => 'json',
                        'limit' => 1,
                        'addressdetails' => 0,
                    ]);

                if (! $response->ok()) {
                    return null;
                }

                $match = $response->json('0');
                if (! is_array($match) || ! isset($match['lat'], $match['lon'])) {
                    return null;
                }

                return [
                    'lat' => (float) $match['lat'],
                    'lng' => (float) $match['lon'],
                ];
            } catch (\Throwable $exception) {
                Log::warning('Adress kunde inte geokodas för leverans-ETA.', ['message' => $exception->getMessage()]);

                return null;
            }
        });
    }

    private function locationFromStatusRequest(array $data): ?array
    {
        $lat = $data['lat'] ?? $data['latitude'] ?? null;
        $lng = $data['lng'] ?? $data['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'accuracy' => $data['accuracy'] ?? null,
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    private function locationCoordinates(array $location): ?array
    {
        $lat = $location['lat'] ?? $location['latitude'] ?? null;
        $lng = $location['lng'] ?? $location['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    private function recipientUserForOrder(object $order): ?object
    {
        $email = strtolower(trim((string) (($order->recipient_email ?? null) ?: ($order->customer_email ?? null))));
        if ($email !== '') {
            $user = DB::table('users')->where('email_key', $email)->where('active', true)->first();
            if ($user) {
                return $user;
            }
        }

        $phone = $this->phoneKey(($order->recipient_phone ?? null) ?: ($order->tele ?? null) ?: ($order->customer_phone ?? null));
        if ($phone !== '') {
            $user = DB::table('users')
                ->whereRaw("regexp_replace(coalesce(phone, ''), '[^0-9+]', '', 'g') = ?", [$phone])
                ->where('active', true)
                ->first();
            if ($user) {
                return $user;
            }
        }

        $recipientName = $this->nameKey($order->mottagare ?? $order->recipient_name ?? null);
        if ($recipientName !== '') {
            return DB::table('users')
                ->whereRaw("lower(trim(coalesce(first_name, '') || ' ' || coalesce(last_name, ''))) = ?", [$recipientName])
                ->where('active', true)
                ->first();
        }

        return null;
    }

    private function visibleUsersQuery(?object $viewer)
    {
        return DB::table('users')
            ->when(! $this->canSeeProtectedAdmin($viewer), function ($query) {
                $query->whereRaw('lower(email) <> ?', [self::PROTECTED_ADMIN_EMAIL]);
            });
    }

    private function canSeeProtectedAdmin(?object $viewer): bool
    {
        return $this->isProtectedAdminUser($viewer);
    }

    private function isProtectedAdminUser(?object $user): bool
    {
        return $this->isProtectedAdminEmail($user->email ?? null);
    }

    private function isProtectedAdminEmail(?string $email): bool
    {
        return strtolower(trim((string) $email)) === self::PROTECTED_ADMIN_EMAIL;
    }

    private function phoneKey(?string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', (string) $phone) ?: '';
    }

    private function nameKey(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)), 'UTF-8');
    }

    private function markOrderItemsDelivered(string $orderId, object $user): int
    {
        return DB::table('order_items')
            ->where('order_id', $orderId)
            ->pluck('id')
            ->sum(fn ($itemId) => $this->markOrderItemDelivered((int) $itemId, $user));
    }

    private function reopenDeliveredOrder(object $order, string $targetStatus, object $user): void
    {
        $items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            $delivered = max(0, (int) ($item->delivered_quantity ?? $this->quantityValue($item->delivered_antal ?? 0)));
            $requested = max(1, (int) ($item->requested_quantity ?? $this->quantityValue($item->antal)));
            $productSku = ($item->product_sku ?? null) ?: null;

            if ($delivered > 0 && $productSku && Schema::hasTable('products')) {
                $lockedProduct = DB::table('products')->where('sku', $productSku)->lockForUpdate()->first();
                if ($lockedProduct) {
                    $stockDelivered = max(0, (int) $lockedProduct->stock_delivered - $delivered);
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
                            'order_item_id' => $item->id,
                            'work_order_number' => $item->work_order_number,
                            'quantity_delta' => $delivered,
                            'type' => 'delivery_reverted',
                            'created_by' => $user->id,
                            'payload' => json_encode([
                                'artikel' => $item->artikel,
                                'reverted_delivered' => $delivered,
                                'target_status' => $targetStatus,
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::table('order_items')
                ->where('id', $item->id)
                ->where('order_id', $order->id)
                ->update([
                'delivered_quantity' => 0,
                'remaining_quantity' => $requested,
                'delivered_antal' => null,
                'delivered_at' => null,
                'updated_at' => now(),
                ...$this->existingOrderItemColumns([
                    'levererat_antal' => 0,
                ]),
            ]);

            if (Schema::hasTable('work_order_delivery_events') && Schema::hasColumn('work_order_delivery_events', 'order_item_id')) {
                DB::table('work_order_delivery_events')
                    ->where('order_id', $order->id)
                    ->where('order_item_id', $item->id)
                    ->delete();
            }
        }

        if (Schema::hasTable('work_order_delivery_events')) {
            DB::table('work_order_delivery_events')
                ->where('order_id', $order->id)
                ->when(
                    Schema::hasColumn('work_order_delivery_events', 'order_item_id'),
                    fn ($query) => $query->whereNull('order_item_id')
                )
                ->delete();
        }
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
            $product = $itemProductSku ? $this->productForArticle($itemProductSku) : null;
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
                ...$this->existingOrderItemColumns([
                    'levererat_antal' => $target,
                ]),
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

            if (Schema::hasTable('work_order_delivery_events')) {
                DB::table('work_order_delivery_events')->insert([
                    'id' => 'evt_'.Str::uuid(),
                    'work_order_number' => $item->work_order_number ?: 'Utan arbetsorder',
                    'order_id' => $item->order_id,
                    'item_index' => (int) ($item->sort_order ?? 0),
                    'artikel' => $item->artikel,
                    'delivered_antal' => (string) $delta,
                    'delivered_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                    ...$this->existingWorkOrderDeliveryEventColumns([
                        'order_item_id' => $itemId,
                    ]),
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

    private function existingOrderItemColumns(array $columns): array
    {
        return collect($columns)
            ->filter(fn ($value, string $column) => Schema::hasColumn('order_items', $column))
            ->all();
    }

    private function existingWorkOrderDeliveryEventColumns(array $columns): array
    {
        return collect($columns)
            ->filter(fn ($value, string $column) => Schema::hasColumn('work_order_delivery_events', $column))
            ->all();
    }

    private function pdfQuantity($value): string
    {
        if ($value === null || $value === '') {
            return '?';
        }

        $number = (float) $value;

        return rtrim(rtrim(number_format($number, 3, ',', ''), '0'), ',');
    }

    private function normalizeSwedishText($value): string
    {
        $text = (string) ($value ?? '');
        if ($text === '') {
            return '';
        }

        $text = str_replace("\xEF\xBF\xBD", '�', $text);
        $text = strtr($text, [
            'Ã¥' => 'å',
            'Ã¤' => 'ä',
            'Ã¶' => 'ö',
            'Ã…' => 'Å',
            'Ã„' => 'Ä',
            'Ã–' => 'Ö',
            'Ã©' => 'é',
            'Ã‰' => 'É',
            'Ãè' => 'è',
            'ÃÈ' => 'È',
            'Ã¼' => 'ü',
            'Ãœ' => 'Ü',
            'Ã¡' => 'á',
            'Ã�' => 'Á',
            'Ã³' => 'ó',
            'Ã“' => 'Ó',
            'â€“' => '-',
            'â€”' => '-',
            'â€˜' => "'",
            'â€™' => "'",
            'â€œ' => '"',
            'â€�' => '"',
            'Â ' => ' ',
            'Â' => '',
        ]);

        $text = preg_replace('/\bBilly\s+Wall[Øø⊘�]n\b/u', 'Billy Wallén', $text) ?? $text;
        $text = preg_replace('/\bBilly\s+Wall(?:Ã©|Ã¨|�)n\b/u', 'Billy Wallén', $text) ?? $text;
        $text = str_replace('�', '', $text);

        return trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
    }

    private function pdfCleanText($value): string
    {
        return $this->normalizeSwedishText($value);
    }

    private function deliveryPdfDocumentData(\Illuminate\Support\Collection $orders, object $user, string $view, string $status, string $period, $periodStart, $periodEnd): array
    {
        $sections = $this->deliveryPdfSections($orders);
        $purchasesByWorkOrder = $this->deliveryPdfPurchasesByWorkOrder(
            $sections
                ->pluck('workOrderNumber')
                ->reject(fn ($workOrderNumber) => Str::startsWith((string) $workOrderNumber, 'Utan arbetsorder'))
                ->values()
                ->all(),
            $periodStart,
            $periodEnd
        );
        $sections = $sections->map(function (array $section) use ($purchasesByWorkOrder): array {
            $section['purchases'] = $purchasesByWorkOrder[$section['workOrderNumber']] ?? [];

            return $section;
        });
        $purchaseCount = collect($purchasesByWorkOrder)->flatten(1)->count();

        return [
            'title' => 'STUCKBEMA',
            'documentType' => 'Leveransdokument',
            'createdAt' => now()->format('Y-m-d H:i'),
            'createdBy' => $this->normalizeSwedishText(trim(($user->first_name ?? '').' '.($user->last_name ?? '')).' ('.$this->roleLabel($user->role).')'),
            'selection' => $this->pdfStatusLabel($status),
            'viewLabel' => $this->pdfViewLabel($view),
            'periodLabel' => $this->pdfPeriodLabel($period, $periodStart, $periodEnd),
            'summary' => [
                'workOrders' => $sections->filter(fn ($section) => ! $section['withoutWorkOrder'])->count(),
                'deliveries' => $orders->count(),
                'delivered' => $orders->where('status', 'delivered')->count(),
                'packed' => $orders->where('status', 'packed')->count(),
                'withoutWorkOrder' => $sections->where('withoutWorkOrder', true)->sum(fn ($section) => count($section['items'])),
                'purchases' => $purchaseCount,
            ],
            'sections' => $sections->values()->all(),
            'hasRows' => $orders->isNotEmpty(),
        ];
    }

    private function deliveryPdfPurchasesByWorkOrder(array $workOrderNumbers, $periodStart, $periodEnd): array
    {
        if (! Schema::hasTable('purchases') || ! Schema::hasColumn('purchases', 'work_order_number')) {
            return [];
        }

        $numbers = collect($workOrderNumbers)
            ->map(fn ($workOrderNumber) => trim((string) $workOrderNumber))
            ->filter()
            ->unique()
            ->values();

        if ($numbers->isEmpty()) {
            return [];
        }

        return DB::table('purchases')
            ->whereIn('work_order_number', $numbers->all())
            ->when($periodStart, fn ($query) => $query->where('created_at', '>=', $periodStart))
            ->when($periodEnd, fn ($query) => $query->where('created_at', '<=', $periodEnd))
            ->orderBy('work_order_number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->groupBy('work_order_number')
            ->map(fn ($rows) => $rows->map(fn ($row) => $this->deliveryPdfPurchaseRow($row))->values()->all())
            ->all();
    }

    private function deliveryPdfPurchaseRow(object $purchase): array
    {
        return [
            'item' => $this->normalizeSwedishText($purchase->item_name ?? '-'),
            'quantity' => $this->pdfQuantity($purchase->quantity ?? 1),
            'store' => $this->normalizeSwedishText($purchase->store_name ?? '-'),
            'price' => $this->pdfMoney($purchase->unit_price ?? null, $purchase->currency ?? 'SEK'),
            'gross' => $this->pdfMoney($purchase->total_gross ?? null, $purchase->currency ?? 'SEK'),
            'availability' => $this->normalizeSwedishText($purchase->availability_at_selection ?? '-'),
            'recipient' => $this->normalizeSwedishText($purchase->recipient_name ?? '-'),
            'selectedAt' => $this->pdfDateTime($purchase->selected_at ?? $purchase->created_at ?? null),
        ];
    }

    private function pdfMoney($value, ?string $currency = 'SEK'): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2, ',', ' ').' '.strtoupper($currency ?: 'SEK');
    }

    private function deliveryPdfSections(\Illuminate\Support\Collection $orders): \Illuminate\Support\Collection
    {
        $groups = collect();

        foreach ($orders as $order) {
            foreach (($order['items'] ?? []) as $item) {
                $workOrderNumber = trim((string) ($item['workOrderNumber'] ?? ''));
                $key = $workOrderNumber !== '' ? $workOrderNumber : 'Utan arbetsorder|'.($order['id'] ?? Str::uuid());

                if (! $groups->has($key)) {
                    $groups->put($key, [
                        'workOrderNumber' => $workOrderNumber !== '' ? $workOrderNumber : 'Utan arbetsorder',
                        'withoutWorkOrder' => $workOrderNumber === '',
                        'orders' => [],
                        'items' => [],
                    ]);
                }

                $group = $groups->get($key);
                $group['orders'][$order['id']] = $this->deliveryPdfOrderMeta($order);
                $group['items'][] = $this->deliveryPdfItemRow($order, $item);
                $groups->put($key, $group);
            }
        }

        return $groups
            ->sortBy(fn ($section, $key) => $section['withoutWorkOrder'] ? 'zzzzzz' : $key, SORT_NATURAL)
            ->map(function (array $section): array {
                $firstOrder = reset($section['orders']) ?: [];
                $statuses = collect($section['orders'])->pluck('status')->filter()->unique()->implode(', ');
                $creators = collect($section['orders'])->pluck('createdBy')->filter()->unique()->implode(', ');

                return [
                    'workOrderNumber' => $section['withoutWorkOrder']
                        ? 'Utan arbetsorder - leverans '.($firstOrder['id'] ?? '-')
                        : $section['workOrderNumber'],
                    'withoutWorkOrder' => $section['withoutWorkOrder'],
                    'deliveryCount' => count($section['orders']),
                    'recipient' => $this->normalizeSwedishText($firstOrder['recipient'] ?? '-'),
                    'status' => $this->normalizeSwedishText($statuses ?: '-'),
                    'createdBy' => $this->normalizeSwedishText($creators ?: '-'),
                    'orders' => array_values($section['orders']),
                    'items' => $section['items'],
                ];
            })
            ->values();
    }

    private function deliveryPdfOrderMeta(array $order): array
    {
        return [
            'id' => $this->normalizeSwedishText($order['id'] ?? '-'),
            'recipient' => $this->normalizeSwedishText($order['mottagare'] ?? '-'),
            'phone' => $this->normalizeSwedishText($order['tele'] ?? '-'),
            'address' => $this->normalizeSwedishText($order['adress'] ?? '-'),
            'status' => $this->normalizeSwedishText($this->statusText($order['status'] ?? '')),
            'createdBy' => $this->normalizeSwedishText($order['createdByName'] ?? '-'),
            'createdAt' => $this->pdfDateTime($order['createdAt'] ?? null),
            'desiredAt' => trim(($order['desiredDeliveryDate'] ?: '-').' '.($order['desiredDeliveryTime'] ?: '')),
            'notes' => $this->normalizeSwedishText($order['notes'] ?? ''),
        ];
    }

    private function deliveryPdfItemRow(array $order, array $item): array
    {
        $calculation = $item['workOrderCalculation'] ?? [];
        $workOrderNumber = trim((string) ($item['workOrderNumber'] ?? ''));

        return [
            'article' => $this->normalizeSwedishText($item['artikel'] ?? '-'),
            'unit' => $this->normalizeSwedishText($item['enhet'] ?? 'st'),
            'ordered' => $workOrderNumber !== '' ? $this->pdfQuantity($calculation['bestallt_antal'] ?? null) : '-',
            'previous' => $workOrderNumber !== '' ? $this->pdfQuantity($calculation['levererat_tidigare'] ?? null) : '-',
            'current' => $this->pdfQuantity($calculation['levererat_denna'] ?? ($item['antal'] ?? null)),
            'total' => $this->pdfQuantity($calculation['levererat_totalt'] ?? ($item['deliveredQuantity'] ?? null)),
            'remaining' => $workOrderNumber !== '' ? $this->pdfQuantity($calculation['kvar'] ?? null) : '-',
            'status' => $this->normalizeSwedishText($this->statusText($order['status'] ?? '')),
            'recipient' => $this->normalizeSwedishText($order['mottagare'] ?? '-'),
            'createdBy' => $this->normalizeSwedishText($order['createdByName'] ?? '-'),
            'warning' => $this->normalizeSwedishText($item['workOrderMatchWarning'] ?? ''),
        ];
    }

    private function pdfDateTime($value): string
    {
        if (! $value) {
            return '-';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function renderHtmlPdf(string $html): string
    {
        $tmpDir = storage_path('framework/cache/pdf');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $fontDir = storage_path('framework/cache/pdf/fonts');
        if (! is_dir($fontDir)) {
            mkdir($fontDir, 0775, true);
        }

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);
        $options->set('tempDir', $tmpDir);
        $options->set('chroot', base_path());
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        try {
            $dompdf = new Dompdf($options);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->render();

            $pdf = $dompdf->output();
        } catch (\Throwable $exception) {
            Log::error('HTML-baserad PDF-rendering misslyckades.', [
                'message' => $exception->getMessage(),
                'class' => $exception::class,
            ]);
            abort(500, 'Kunde inte skapa PDF.');
        }

        return $pdf ?: '';
    }

    private function pdfStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Aktiva',
            'packed' => 'Packade',
            'delivered' => 'Levererade',
            default => 'Alla',
        };
    }

    private function pdfViewLabel(string $view): string
    {
        return match ($view) {
            'deliveries' => 'Leveranser',
            default => 'Arbetsorder',
        };
    }

    private function pdfPeriodRange(string $period): array
    {
        $now = now();

        return match ($period) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [null, null],
        };
    }

    private function pdfPeriodLabel(string $period, $start, $end): string
    {
        if (! $start || ! $end) {
            return 'Alla datum';
        }

        $label = match ($period) {
            'day' => 'Idag',
            'week' => 'Denna vecka',
            'month' => 'Denna månad',
            'quarter' => 'Detta kvartal',
            'year' => 'Detta år',
            default => 'Period',
        };

        return $label.' ('.$start->format('Y-m-d').' - '.$end->format('Y-m-d').')';
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

    private function articleRows(): array
    {
        if (Schema::hasTable('products')) {
            return $this->productRows();
        }

        return $this->rememberDashboard('articles', self::DASHBOARD_CACHE_SECONDS, fn () => DB::table('order_items')
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
                ->all());
    }

    private function customerRows(): array
    {
        return $this->rememberDashboard('customers', self::DASHBOARD_CACHE_SECONDS, fn () => DB::table('orders')
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
                ->all());
    }

    private function recipientRows(?string $query = null, ?object $viewer = null): array
    {
        $rows = $this->rememberDashboard('recipients:all', self::DASHBOARD_CACHE_SECONDS, fn () => $this->buildRecipientRows());
        if (! $this->canSeeProtectedAdmin($viewer)) {
            $rows = collect($rows)
                ->reject(fn (array $row) => $this->isProtectedAdminEmail($row['email'] ?? null))
                ->values()
                ->all();
        }

        $queryKey = $this->recipientKey($query);

        if ($queryKey === '') {
            return $rows;
        }

        return collect($rows)
            ->filter(fn (array $row) => str_contains($this->recipientKey($row['name']), $queryKey))
            ->values()
            ->all();
    }

    private function buildRecipientRows(): array
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

        $deduplicated = $rows
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

    private function pushSubscriptionRows(?object $viewer = null): array
    {
        return DB::table('push_subscriptions')
            ->leftJoin('users', 'users.id', '=', 'push_subscriptions.user_id')
            ->when(! $this->canSeeProtectedAdmin($viewer), function ($query) {
                $query->where(function ($nested) {
                    $nested->whereNull('users.email')
                        ->orWhereRaw('lower(users.email) <> ?', [self::PROTECTED_ADMIN_EMAIL]);
                });
            })
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

    private function notifyDeliveryCreated(string $orderId): void
    {
        try {
            $order = DB::table('orders')->where('id', $orderId)->first();
            if (! $order) {
                return;
            }

            $recipient = Str::of((string) ($order->mottagare ?: $order->recipient_name ?: 'Ny leverans'))->squish()->toString();
            $address = Str::of((string) ($order->adress ?: $order->delivery_address ?: ''))->squish()->toString();
            $body = $address !== '' ? "{$recipient} - {$address}" : $recipient;

            app(WebPushNotifier::class)->sendToAllActive([
                'notification' => [
                    'title' => 'Du har en bokad leverans',
                    'body' => $body,
                    'tag' => 'delivery-created-'.$order->id,
                    'requireInteraction' => true,
                ],
                'data' => [
                    'url' => '/',
                    'type' => 'delivery-created',
                    'orderId' => $order->id,
                    'requireInteraction' => true,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Pushnotis för ny leverans kunde inte skickas.', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
            ]);
        }
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
            'items.*.enhet' => ['nullable', 'string', 'max:30'],
            'items.*.workOrderNumber' => ['nullable', 'integer'],
            'items.*.order_item_id' => ['nullable', 'integer'],
            'items.*.workOrderArticleId' => ['nullable', 'integer'],
            'items.*.article_number' => ['nullable', 'string', 'max:200'],
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
        app(DeliveryOrderItemService::class)->replace($orderId, $items, 'web');
    }

    private function orderRows(\Illuminate\Support\Collection $orders): \Illuminate\Support\Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        $itemsByOrder = DB::table('order_items')
            ->whereIn('order_id', $orders->pluck('id')->all())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('order_id');

        return $orders
            ->map(fn ($order) => $this->orderRow($order, $itemsByOrder->get($order->id, collect())))
            ->values();
    }

    private function orderRow(object $row, ?\Illuminate\Support\Collection $preloadedItems = null): array
    {
        $calculator = app(LeveransKalkylService::class);
        $orderItems = $preloadedItems ?? DB::table('order_items')
            ->where('order_id', $row->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $items = $orderItems
            ->map(function ($item) use ($calculator, $row) {
                $isInternalWorkOrder = ! empty($row->internal_work_order_number)
                    && (string) ($item->arbetsorder_nr ?? $item->work_order_number ?? '') === (string) $row->internal_work_order_number;
                $product = ($item->product_sku ?? null) ? $this->productForArticle($item->product_sku) : null;
                $requestedQuantity = (int) ($item->requested_quantity ?? $this->quantityValue($item->antal));
                $deliveredQuantity = (int) ($item->delivered_quantity ?? $this->quantityValue($item->delivered_antal ?? 0));
                $calculation = $calculator->calculationForItem($item, $row->id);

                return [
                    'id' => $item->id,
                    'artikel' => $item->artikel,
                    'antal' => $item->antal,
                    'enhet' => $item->enhet ?? null,
                    'artikelNormalized' => $item->artikel_normalized ?? ArbetsorderParser::normalizeArticle($item->artikel),
                    'workOrderNumber' => $item->arbetsorder_nr ?? $item->work_order_number,
                    'workOrderArticleId' => $item->arbetsorder_rad_id ?? null,
                    'isInternalWorkOrder' => $isInternalWorkOrder,
                    'workOrderMatchStatus' => $item->match_status ?? $calculation['match_status'],
                    'workOrderMatchWarning' => $item->match_warning ?? $calculation['match_warning'],
                    'workOrderCalculation' => $calculation,
                    'deliveredAntal' => $item->delivered_antal ?? null,
                    'deliveredAt' => $item->delivered_at ?? null,
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
            'packed', 'packad' => 'packed',
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
            'internalWorkOrderNumber' => $row->internal_work_order_number ?? null,
            'items' => $items,
            'trackingEnabled' => (bool) $row->tracking_enabled,
            'trackingUrl' => $row->tracking_url,
            'trackingToken' => $row->tracking_token,
            'currentLocation' => $this->decodeJson($row->current_location),
            'packedAt' => $row->packed_at ?? null,
            'deliveredAt' => $row->delivered_at,
            'createdBy' => $row->created_by ?? null,
            'createdByName' => trim((string) ($row->created_by_name ?? '')) ?: ($row->created_by_email ?? $row->created_by ?? null),
            'createdByRole' => $row->created_by_role ?? null,
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

    private function userLocationPayload(?object $user, bool $trackingEnabled = true): array
    {
        if (! $user) {
            return ['trackingEnabled' => false];
        }

        $location = $this->decodeJson($user->current_location ?? null) ?: [];
        $driverName = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->email ?? 'Förare');

        return [
            'type' => 'driver',
            'orderId' => 'driver-'.$user->id,
            'driverId' => $user->id,
            'driverName' => $driverName,
            'recipientName' => $driverName,
            'deliveryAddress' => 'Synlig online',
            'lat' => isset($location['lat']) ? (float) $location['lat'] : (isset($location['latitude']) ? (float) $location['latitude'] : null),
            'lng' => isset($location['lng']) ? (float) $location['lng'] : (isset($location['longitude']) ? (float) $location['longitude'] : null),
            'accuracy' => $location['accuracy'] ?? null,
            'speed' => $location['speed'] ?? null,
            'heading' => $location['heading'] ?? null,
            'trackingEnabled' => $trackingEnabled && ($user->visibility ?? 'offline') === 'online',
            'updatedAt' => $location['updatedAt'] ?? $user->location_updated_at ?? now()->toIso8601String(),
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
            'permissionAllow' => $this->cleanPermissionList($this->jsonArray($row->permission_allow ?? [])),
            'permissionDeny' => $this->cleanPermissionList($this->jsonArray($row->permission_deny ?? [])),
            'effectivePermissions' => $this->permissionsForUser($row),
            'active' => (bool) $row->active,
            'phone' => $row->phone,
            'photoUrl' => $row->photo_url ?? null,
            'imagePath' => $row->image_path ?? null,
            'visibility' => $row->visibility,
            'lastSeenAt' => $row->last_seen_at,
            'isFirstLogin' => (bool) $row->is_first_login,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }

    private function settings(): array
    {
        return $this->rememberDashboard('settings', self::SETTINGS_CACHE_SECONDS, function () {
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
        });
    }

    private function deletePublicProfileImage(?string $photoUrl): void
    {
        $photoUrl = trim((string) $photoUrl);
        if ($photoUrl === '' || ! str_starts_with($photoUrl, '/profile-images/')) {
            return;
        }

        $path = public_path(ltrim($photoUrl, '/'));
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function messageRows(object $user): array
    {
        if (! Schema::hasTable('internal_messages')) {
            return ['inbox' => [], 'outbox' => [], 'deleted' => [], 'unreadCount' => 0];
        }

        $base = DB::table('internal_messages')
            ->leftJoin('users as senders', 'senders.id', '=', 'internal_messages.sender_id')
            ->leftJoin('users as recipients', 'recipients.id', '=', 'internal_messages.recipient_id')
            ->select([
                'internal_messages.*',
                DB::raw("trim(coalesce(senders.first_name, '') || ' ' || coalesce(senders.last_name, '')) as sender_name"),
                'senders.email as sender_email',
                DB::raw("trim(coalesce(recipients.first_name, '') || ' ' || coalesce(recipients.last_name, '')) as recipient_name"),
                'recipients.email as recipient_email',
            ]);

        $map = fn ($rows) => $rows->map(function ($row) use ($user) {
            $canSeeProtectedAdmin = $this->canSeeProtectedAdmin($user);
            $senderName = trim((string) $row->sender_name) ?: ($row->sender_email ?: 'System');
            $recipientName = trim((string) $row->recipient_name) ?: ($row->recipient_email ?: 'Okänd mottagare');

            if (! $canSeeProtectedAdmin && $this->isProtectedAdminEmail($row->sender_email ?? null)) {
                $senderName = 'System';
            }

            if (! $canSeeProtectedAdmin && $this->isProtectedAdminEmail($row->recipient_email ?? null)) {
                $recipientName = 'System';
            }

            return [
                'id' => $row->id,
                'senderId' => $row->sender_id,
                'senderName' => $senderName,
                'recipientId' => $row->recipient_id,
                'recipientName' => $recipientName,
                'orderId' => $row->order_id,
                'subject' => $row->subject,
                'body' => $row->body,
                'readAt' => $row->read_at,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        })->values()->all();

        $inbox = (clone $base)
            ->where('internal_messages.recipient_id', $user->id)
            ->whereNull('internal_messages.recipient_deleted_at')
            ->orderByDesc('internal_messages.created_at')
            ->limit(100)
            ->get();
        $outbox = (clone $base)
            ->where('internal_messages.sender_id', $user->id)
            ->whereNull('internal_messages.sender_deleted_at')
            ->orderByDesc('internal_messages.created_at')
            ->limit(100)
            ->get();
        $deleted = (clone $base)
            ->where(function ($query) use ($user) {
                $query->where(function ($recipientQuery) use ($user) {
                    $recipientQuery->where('internal_messages.recipient_id', $user->id)
                        ->whereNotNull('internal_messages.recipient_deleted_at');
                })->orWhere(function ($senderQuery) use ($user) {
                    $senderQuery->where('internal_messages.sender_id', $user->id)
                        ->whereNotNull('internal_messages.sender_deleted_at');
                });
            })
            ->orderByDesc('internal_messages.updated_at')
            ->limit(100)
            ->get();

        return [
            'inbox' => $map($inbox),
            'outbox' => $map($outbox),
            'deleted' => $map($deleted),
            'unreadCount' => $inbox->filter(fn ($row) => $row->read_at === null)->count(),
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
