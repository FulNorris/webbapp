<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Services\Purchases\PurchaseCrawlerHealthService;
use App\Services\Purchases\PurchaseProductSearchService;
use App\Services\WebPushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    private const APPROVED_SUPPLIERS = [
        'Bauhaus' => ['Bromma', 'Stockholm'],
        'Bygma' => ['Bromma', 'Stockholm'],
        'Jula' => ['Kungens Kurva', 'Stockholm'],
        'Biltema' => ['Bredden'],
        'Beijer' => ['Bromma', 'Stockholm'],
        'Swedol' => ['Stockholm'],
        'XL-Bygg' => ['Stockholm'],
        'Hornbach' => ['Botkyrka', 'Stockholm'],
    ];

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

    public function index(): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('internal.dashboard');
    }

    public function search(Request $request, PurchaseProductSearchService $search): JsonResponse
    {
        $this->requirePermission($request, 'purchases.view');

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/'],
            'article_number' => ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        [$latitude, $longitude] = $this->requestLocation($request, $data);

        return response()->json($search->search(
            (string) ($data['q'] ?? ''),
            (string) ($data['article_number'] ?? ''),
            $latitude,
            $longitude
        ));
    }

    public function crawlerHealth(Request $request, PurchaseCrawlerHealthService $health): JsonResponse
    {
        $this->requirePermission($request, 'purchases.view');

        $report = $health->report();

        return response()->json($report, $report['ok'] ? 200 : 503);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->requirePermission($request, 'purchases.create');

        $purchase = Purchase::create($this->validatePurchase($request));
        $this->syncPurchaseThumbnail($purchase, $purchase->image_url);
        $this->forgetPurchaseCache();
        $this->notifyPurchaseCreated($purchase);

        return back()->with('success', 'Inköpet skapades.');
    }

    public function update(Request $request, Purchase $purchase): \Illuminate\Http\RedirectResponse
    {
        $this->requirePermission($request, 'purchases.update');

        $data = $this->validatePurchase($request);
        $purchase->update($data);
        $this->syncPurchaseThumbnail($purchase->refresh(), $data['image_url'] ?? null);
        $this->forgetPurchaseCache();

        return back()->with('success', 'Inköpet sparades.');
    }

    public function destroy(Request $request, Purchase $purchase): \Illuminate\Http\RedirectResponse
    {
        $this->requirePermission($request, 'purchases.delete');

        $purchase->delete();
        $this->forgetPurchaseCache();

        return back()->with('success', 'Inköpet raderades.');
    }

    public function markAsOrdered(Request $request, Purchase $purchase): \Illuminate\Http\RedirectResponse
    {
        $this->requirePermission($request, 'purchases.update_status');

        $purchase->update(['status' => 'ordered']);
        $this->forgetPurchaseCache();

        return back()->with('success', 'Inköpet markerades som beställt.');
    }

    public function markAsReceived(Request $request, Purchase $purchase): \Illuminate\Http\RedirectResponse
    {
        $this->requirePermission($request, 'purchases.update_status');

        $purchase->update(['status' => 'received']);
        $this->forgetPurchaseCache();

        return back()->with('success', 'Inköpet markerades som mottaget.');
    }

    public function updateStatus(Request $request, Purchase $purchase): \Illuminate\Http\RedirectResponse
    {
        $this->requirePermission($request, 'purchases.update_status');

        $data = $request->validate([
            'status' => ['required', Rule::in(['planned', 'ordered', 'received', 'cancelled'])],
        ]);

        $purchase->update(['status' => $data['status']]);
        $this->forgetPurchaseCache();

        return back()->with('success', 'Inköpsstatus uppdaterades.');
    }

    private function validatePurchase(Request $request): array
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'item_name' => ['required', 'string', 'max:255'],
            'store_name' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', Rule::in(array_keys(self::APPROVED_SUPPLIERS))],
            'store' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', Rule::in(['Stockholm'])],
            'sku' => ['nullable', 'string', 'max:120'],
            'product_url' => ['nullable', 'url', 'max:3000'],
            'image_url' => ['nullable', 'url', 'max:3000'],
            'maps_label' => ['nullable', 'string', 'max:255'],
            'maps_url' => ['nullable', 'url', 'max:3000'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'availability_at_selection' => ['nullable', 'string', 'max:255'],
            'selected_at' => ['nullable', 'date'],
            'fetched_at' => ['nullable', 'date'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:80'],
            'work_order_number' => ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'status' => ['nullable', Rule::in(['planned', 'ordered', 'received', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $data['work_order_number'] = $this->normalizeNullableText($data['work_order_number'] ?? null);
        $data['status'] = $data['status'] ?? 'planned';
        $data['currency'] = strtoupper($data['currency'] ?? 'SEK');
        $data['vat_rate'] = round((float) ($data['vat_rate'] ?? 25), 2);
        $data['city'] = $data['city'] ?: ($data['supplier'] ? 'Stockholm' : null);

        if (($data['supplier'] ?? null) || ($data['store'] ?? null)) {
            $this->validateApprovedSupplierStore((string) ($data['supplier'] ?? ''), (string) ($data['store'] ?? ''));
            $data['store_name'] = trim($data['supplier'].' '.$data['store']);
            $data['maps_label'] = $data['maps_label'] ?: "Kör till {$data['store_name']}";
            $data['maps_url'] = $data['maps_url'] ?: 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($data['store_name']);
            $data['selected_at'] = $data['selected_at'] ?? now();
        }

        if (($data['maps_url'] ?? null) && ! str_starts_with((string) $data['maps_url'], 'https://www.google.com/maps/search/')) {
            abort(422, 'Ogiltig Google Maps-länk.');
        }

        $quantity = max(1, (int) $data['quantity']);
        $unitPrice = isset($data['unit_price']) && $data['unit_price'] !== null ? round((float) $data['unit_price'], 2) : null;
        $net = $unitPrice !== null ? round($quantity * $unitPrice, 2) : 0.0;
        $vat = round($net * ($data['vat_rate'] / 100), 2);

        $data['unit_price'] = $unitPrice;
        $data['total_net'] = $net;
        $data['total_vat'] = $vat;
        $data['total_gross'] = round($net + $vat, 2);

        return $data;
    }

    private function requestLocation(Request $request, array $data): array
    {
        $latitude = $data['lat'] ?? $request->headers->get('CF-IPLatitude');
        $longitude = $data['lng'] ?? $request->headers->get('CF-IPLongitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [null, null];
        }

        return [(float) $latitude, (float) $longitude];
    }

    private function syncPurchaseThumbnail(Purchase $purchase, ?string $imageUrl): void
    {
        $imageUrl = $this->normalizeNullableText($imageUrl);

        if ($imageUrl === null) {
            $this->deletePurchaseThumbnail($purchase->thumbnail_path);
            $purchase->forceFill([
                'thumbnail_path' => null,
                'thumbnail_hash' => null,
            ])->saveQuietly();

            return;
        }

        try {
            $response = Http::timeout(5)
                ->connectTimeout(2)
                ->withHeaders([
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
                ])
                ->get($imageUrl);

            if (! $response->successful()) {
                return;
            }

            $body = (string) $response->body();
            if ($body === '' || strlen($body) > 4 * 1024 * 1024) {
                return;
            }

            $contentType = strtolower((string) $response->header('Content-Type', ''));
            $extension = $this->imageExtension($contentType, $imageUrl);
            if ($extension === null) {
                return;
            }

            $hash = hash('sha256', $body);
            if ($purchase->thumbnail_hash === $hash && $purchase->thumbnail_path) {
                return;
            }

            $directory = public_path('purchase-thumbnails');
            if (! File::isDirectory($directory)) {
                File::makeDirectory($directory, 0775, true);
            }

            $filename = 'purchase-'.$purchase->id.'-'.substr($hash, 0, 16).'.'.$extension;
            $relativePath = 'purchase-thumbnails/'.$filename;
            File::put(public_path($relativePath), $body);
            $this->deletePurchaseThumbnail($purchase->thumbnail_path, $relativePath);

            $purchase->forceFill([
                'thumbnail_path' => $relativePath,
                'thumbnail_hash' => $hash,
            ])->saveQuietly();
        } catch (\Throwable $exception) {
            Log::warning('Kunde inte spara inköpsthumbnail.', [
                'purchase_id' => $purchase->id,
                'image_host' => parse_url($imageUrl, PHP_URL_HOST),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function imageExtension(string $contentType, string $imageUrl): ?string
    {
        return match (true) {
            str_contains($contentType, 'image/jpeg') || preg_match('/\.jpe?g(?:\?|$)/i', $imageUrl) => 'jpg',
            str_contains($contentType, 'image/png') || preg_match('/\.png(?:\?|$)/i', $imageUrl) => 'png',
            str_contains($contentType, 'image/webp') || preg_match('/\.webp(?:\?|$)/i', $imageUrl) => 'webp',
            str_contains($contentType, 'image/avif') || preg_match('/\.avif(?:\?|$)/i', $imageUrl) => 'avif',
            default => null,
        };
    }

    private function deletePurchaseThumbnail(?string $path, ?string $except = null): void
    {
        if (! $path || $path === $except || ! str_starts_with($path, 'purchase-thumbnails/')) {
            return;
        }

        $absolutePath = public_path($path);
        if (File::isFile($absolutePath)) {
            File::delete($absolutePath);
        }
    }

    private function normalizeNullableText(?string $value): ?string
    {
        $text = Str::of((string) $value)->squish()->toString();

        return $text === '' ? null : $text;
    }

    private function validateApprovedSupplierStore(string $supplier, string $store): void
    {
        if (! isset(self::APPROVED_SUPPLIERS[$supplier]) || ! in_array($store, self::APPROVED_SUPPLIERS[$supplier], true)) {
            abort(422, 'Endast godkända Stockholmsbutiker får användas.');
        }
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

    private function requirePermission(Request $request, string $permission): object
    {
        $user = $this->activeUser($request);
        abort_if(! $user || ! $this->hasPermission($user, $permission), 403);

        return $user;
    }

    private function hasPermission(object $user, string $permission): bool
    {
        $role = $this->normalizedRole($user->role);
        if ($role === 'firmatecknare') {
            return true;
        }

        $matrix = [
            'admin' => ['purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete', 'purchases.update_status'],
            'arbetsledare' => ['purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete', 'purchases.update_status'],
            'personal' => ['purchases.view', 'purchases.create', 'purchases.update', 'purchases.update_status'],
            'viewer' => ['purchases.view'],
        ];

        return in_array($permission, $matrix[$role] ?? [], true);
    }

    private function normalizedRole(?string $role): string
    {
        $key = Str::of((string) $role)->lower()->ascii()->replace(' ', '_')->toString();

        return $this->roleAliases[$key] ?? (in_array($key, $this->roles, true) ? $key : 'viewer');
    }

    private function forgetPurchaseCache(): void
    {
        Cache::forget('dashboard:purchases');
    }

    private function notifyPurchaseCreated(Purchase $purchase): void
    {
        try {
            $quantity = max(1, (int) $purchase->quantity);
            $itemName = Str::of((string) $purchase->item_name)->squish()->toString();
            $storeName = Str::of((string) ($purchase->store_name ?? ''))->squish()->toString();
            $body = $storeName !== ''
                ? "{$quantity} st {$itemName} - {$storeName}"
                : "{$quantity} st {$itemName}";

            app(WebPushNotifier::class)->sendToAllActive([
                'notification' => [
                    'title' => 'Nytt inköp',
                    'body' => $body,
                    'tag' => 'purchase-created-'.$purchase->id,
                ],
                'data' => [
                    'url' => '/?tab=purchases',
                    'type' => 'purchase-created',
                    'purchaseId' => $purchase->id,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Pushnotis för nytt inköp kunde inte skickas.', [
                'purchase_id' => $purchase->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
