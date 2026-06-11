<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkOrderArticleService
{
    public function articlesFor(string|int $workOrderNumber): Collection
    {
        $workOrderNumber = (int) $workOrderNumber;
        if (! $workOrderNumber || ! Schema::hasTable('arbetsordrar') || ! Schema::hasTable('arbetsorder_rader')) {
            return collect();
        }

        $workOrder = DB::table('arbetsordrar')->where('arbetsorder_nr', $workOrderNumber)->first();
        if (! $workOrder) {
            return collect();
        }

        $rows = DB::table('arbetsorder_rader')
            ->where('arbetsorder_id', $workOrder->id)
            ->orderBy('rad_nr')
            ->orderBy('id')
            ->get();

        $products = $this->productsForRows($rows);
        $deliveredByArticle = $this->deliveredQuantitiesForWorkOrder($workOrderNumber);

        return $rows->map(fn (object $row) => $this->articleRow($row, $products, $deliveredByArticle));
    }

    public function responseFor(string|int $workOrderNumber): array
    {
        $workOrderNumber = (int) $workOrderNumber;
        $workOrder = $this->workOrderFor($workOrderNumber);
        $items = $this->articlesFor($workOrderNumber);

        return [
            'work_order_number' => (string) $workOrderNumber,
            'work_order' => $workOrder ? [
                'id' => (int) $workOrder->id,
                'work_order_number' => (string) $workOrder->arbetsorder_nr,
                'recipient_name' => $workOrder->kontaktperson ?? null,
                'recipient_phone' => $workOrder->telefon ?? null,
                'workplace' => $workOrder->arbetsplats ?? null,
                'postal_address' => $workOrder->postadress ?? null,
                'address' => trim(implode(', ', array_filter([$workOrder->arbetsplats ?? null, $workOrder->postadress ?? null]))),
            ] : null,
            'count' => $items->count(),
            'items' => $items->values(),
        ];
    }

    private function workOrderFor(int $workOrderNumber): ?object
    {
        if (! $workOrderNumber || ! Schema::hasTable('arbetsordrar')) {
            return null;
        }

        $query = DB::table('arbetsordrar')->where('arbetsorder_nr', $workOrderNumber);
        if (Schema::hasColumn('arbetsordrar', 'hidden_at')) {
            $query->whereNull('hidden_at');
        }

        return $query->first();
    }

    private function articleRow(object $row, Collection $products, Collection $deliveredByArticle): array
    {
        $articleNumber = $row->artikel_normalized ?: ArbetsorderParser::normalizeArticle($row->artikel);
        $product = $products->get(mb_strtolower($articleNumber, 'UTF-8'));
        $ordered = $row->antal === null ? null : (float) $row->antal;
        $delivered = (float) ($deliveredByArticle->get($articleNumber) ?? 0);
        $remaining = $ordered === null ? null : max(0, $ordered - $delivered);
        $unit = $row->enhet ?: 'st';
        $name = $product?->title ?: $row->artikel;
        $disabled = $remaining !== null && $remaining <= 0;
        $warning = $product ? null : 'Saknas i produktregistret';

        return [
            'id' => (int) $row->id,
            'order_item_id' => (int) $row->id,
            'work_order_number' => (string) $row->arbetsorder_nr,
            'article_number' => $articleNumber,
            'artikel' => $row->artikel,
            'artikel_normalized' => $articleNumber,
            'name' => $name,
            'product_id' => null,
            'product_sku' => $product?->sku,
            'ordered_quantity' => $ordered,
            'delivered_quantity' => $delivered,
            'remaining_quantity' => $remaining,
            'unit' => $unit,
            'disabled' => $disabled,
            'warning' => $warning,
            'reason_if_disabled' => $disabled ? 'Inget återstående antal' : null,
            'label' => $this->label($articleNumber, $name, $remaining, $unit, $warning),
        ];
    }

    private function productsForRows(Collection $rows): Collection
    {
        if ($rows->isEmpty() || ! Schema::hasTable('products')) {
            return collect();
        }

        $candidates = $rows
            ->flatMap(function (object $row) {
                $article = $row->artikel_normalized ?: $row->artikel;

                return app(ProductResolver::class)->skuCandidates((string) $article);
            })
            ->map(fn (string $candidate) => mb_strtolower($candidate, 'UTF-8'))
            ->filter()
            ->unique()
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        return DB::table('products')
            ->whereIn(DB::raw('lower(sku)'), $candidates->all())
            ->get()
            ->keyBy(fn (object $product) => mb_strtolower($product->sku, 'UTF-8'));
    }

    private function deliveredQuantitiesForWorkOrder(int $workOrderNumber): Collection
    {
        if (! Schema::hasTable('order_items')) {
            return collect();
        }

        $deliveredExpressions = [];
        if (Schema::hasColumn('order_items', 'levererat_antal')) {
            $deliveredExpressions[] = 'when order_items.levererat_antal is not null then order_items.levererat_antal';
        }
        if (Schema::hasColumn('order_items', 'delivered_quantity')) {
            $deliveredExpressions[] = 'when order_items.delivered_quantity is not null and order_items.delivered_quantity > 0 then order_items.delivered_quantity';
        }

        $completionConditions = [];
        if (Schema::hasColumn('order_items', 'delivered_at')) {
            $completionConditions[] = 'order_items.delivered_at is not null';
        }
        if (Schema::hasColumn('orders', 'delivered_at')) {
            $completionConditions[] = 'orders.delivered_at is not null';
        }
        if (Schema::hasColumn('orders', 'status')) {
            $completionConditions[] = "orders.status in ('delivered', 'levererad')";
        }

        $completionExpression = $completionConditions === []
            ? ''
            : 'when '.implode(' or ', $completionConditions).' then coalesce(order_items.requested_quantity, 0)';

        $caseSql = implode("\n", $deliveredExpressions);
        $caseSql = trim($caseSql."\n".$completionExpression);
        if ($caseSql === '') {
            return collect();
        }

        return DB::table('order_items')
            ->leftJoin('orders', 'orders.id', '=', 'order_items.order_id')
            ->where(function ($query) use ($workOrderNumber) {
                $query->where('order_items.arbetsorder_nr', $workOrderNumber)
                    ->orWhere('order_items.work_order_number', (string) $workOrderNumber);
            })
            ->whereNotNull('order_items.artikel_normalized')
            ->selectRaw(<<<SQL
                order_items.artikel_normalized,
                sum(
                    case
                        {$caseSql}
                        else 0
                    end
                ) as delivered_quantity
            SQL)
            ->groupBy('order_items.artikel_normalized')
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->artikel_normalized => (float) $row->delivered_quantity]);
    }

    private function label(string $articleNumber, string $name, ?float $remaining, string $unit, ?string $warning): string
    {
        $quantity = $remaining === null ? '?' : rtrim(rtrim(number_format($remaining, 3, ',', ''), '0'), ',');
        $label = "{$articleNumber} | {$name} | {$quantity} {$unit} kvar";

        return $warning ? "{$label} | {$warning}" : $label;
    }
}
