<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeliveryOrderItemService
{
    public function replace(string $orderId, array $items, string $source = 'web'): void
    {
        $internalWorkOrder = app(InternalWorkOrderService::class)->syncForOrder($orderId, $items);
        DB::table('order_items')->where('order_id', $orderId)->delete();

        foreach (array_values($items) as $index => $item) {
            $item = $this->withInternalWorkOrder($item, $index, $internalWorkOrder);
            $payload = $this->payload($orderId, $item, $index, $source);
            if ($payload === []) {
                continue;
            }

            DB::table('order_items')->insert($this->existingColumns($payload));
        }
    }

    public function payload(string $orderId, array $item, int $index, string $source = 'web'): array
    {
        $article = trim((string) ($item['artikel'] ?? $item['article_number'] ?? $item['article'] ?? $item['model'] ?? ''));
        $quantity = trim((string) ($item['antal'] ?? $item['quantity'] ?? ''));

        if ($source === 'web' && $article === '' && $quantity === '') {
            return [];
        }

        $calculator = app(LeveransKalkylService::class);
        $product = null;
        $workOrderNumber = trim((string) ($item['workOrderNumber'] ?? $item['work_order_number'] ?? ''));
        $arbetsorderNr = $workOrderNumber !== '' && ctype_digit($workOrderNumber) ? (int) $workOrderNumber : null;
        $arbetsorderRadId = $this->workOrderRowId($item);
        $arbetsorder = $calculator->arbetsorderForNumber($arbetsorderNr);
        $arbetsorderRad = $arbetsorderRadId
            ? DB::table('arbetsorder_rader')->where('id', $arbetsorderRadId)->first()
            : $calculator->matchArbetsorderRad($arbetsorderNr, $article);
        $this->validateWorkOrderMatch($index, $arbetsorderNr, $article, $arbetsorder, $arbetsorderRad, $arbetsorderRadId);
        if ($arbetsorderRad) {
            $article = $article !== '' ? $article : (string) $arbetsorderRad->artikel;
            if (empty($item['__internal_manual_work_order'])) {
                $product = app(ProductResolver::class)->forArticle($article);
            }
        } elseif (! empty($item['product_sku'])) {
            $product = app(ProductResolver::class)->forArticle((string) $item['product_sku']);
        }
        $articleNormalized = $arbetsorderRad?->artikel_normalized ?: ArbetsorderParser::normalizeArticle($article);
        $unit = trim((string) ($item['enhet'] ?? $item['unit'] ?? '')) ?: $calculator->unitFromQuantity($quantity) ?: 'st';
        $requestedQuantity = $this->quantityValue($quantity);

        return [
            'order_id' => $orderId,
            'artikel' => $article,
            'article' => $item['article'] ?? $article,
            'benamning' => $item['benamning'] ?? $item['description'] ?? null,
            'description' => $item['description'] ?? $item['benamning'] ?? $article,
            'antal' => $quantity,
            'quantity' => $item['quantity'] ?? $quantity,
            'sort_order' => $index,
            'work_order_number' => $arbetsorderNr ? (string) $arbetsorderNr : null,
            'product_sku' => $product?->sku,
            'product_title' => $product?->title,
            'product_image_path' => $product?->primary_image_path,
            'product_image_url' => $product?->primary_image_url,
            'requested_quantity' => $requestedQuantity,
            'delivered_quantity' => 0,
            'remaining_quantity' => $requestedQuantity,
            'payload' => json_encode($item),
            'created_at' => now(),
            'updated_at' => now(),
            'arbetsorder_nr' => $arbetsorderNr,
            'arbetsorder_id' => $arbetsorder?->id,
            'arbetsorder_rad_id' => $arbetsorderRad?->id,
            'artikel_normalized' => $articleNormalized,
            'enhet' => $unit,
            'levererat_antal' => 0,
            'match_status' => $arbetsorderNr ? ($arbetsorderRad ? 'matched' : 'unmatched') : null,
            'match_warning' => $arbetsorderNr && ! $arbetsorderRad ? 'Artikeln hittades inte på arbetsordern' : null,
        ];
    }

    private function withInternalWorkOrder(array $item, int $index, array $internalWorkOrder): array
    {
        $workOrder = $internalWorkOrder['work_order'] ?? null;
        $rows = $internalWorkOrder['rows'] ?? [];
        $row = $rows[$index] ?? null;

        if (! $workOrder || ! $row) {
            return $item;
        }

        return [
            ...$item,
            'workOrderNumber' => (int) $workOrder->arbetsorder_nr,
            'workOrderArticleId' => (int) $row->id,
            'order_item_id' => (int) $row->id,
            '__internal_manual_work_order' => true,
        ];
    }

    public function quantityValue($value): int
    {
        if (preg_match('/\d+(?:[,.]\d+)?/', (string) $value, $matches)) {
            return max(0, (int) ceil((float) str_replace(',', '.', $matches[0])));
        }

        return 0;
    }

    private function existingColumns(array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, string $column) => Schema::hasColumn('order_items', $column))
            ->all();
    }

    private function validateWorkOrderMatch(int $index, ?int $arbetsorderNr, string $article, ?object $arbetsorder, ?object $arbetsorderRad, ?int $arbetsorderRadId): void
    {
        if (! $arbetsorderNr) {
            return;
        }

        if (! $arbetsorder) {
            throw ValidationException::withMessages([
                "items.{$index}.workOrderNumber" => "Arbetsorder {$arbetsorderNr} hittades inte.",
            ]);
        }

        if ($arbetsorderRadId && (! $arbetsorderRad || (int) $arbetsorderRad->arbetsorder_nr !== $arbetsorderNr)) {
            throw ValidationException::withMessages([
                "items.{$index}.order_item_id" => "Vald artikel tillhör inte arbetsorder {$arbetsorderNr}.",
            ]);
        }

        if ($article !== '' && ! $arbetsorderRad) {
            throw ValidationException::withMessages([
                "items.{$index}.artikel" => "Artikeln {$article} finns inte på arbetsorder {$arbetsorderNr}.",
            ]);
        }
    }

    private function workOrderRowId(array $item): ?int
    {
        $value = $item['order_item_id']
            ?? $item['orderItemId']
            ?? $item['workOrderArticleId']
            ?? $item['arbetsorderRadId']
            ?? $item['arbetsorder_rad_id']
            ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
