<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InternalWorkOrderService
{
    public function syncForOrder(string $orderId, array $items): array
    {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        if (! $order) {
            return ['work_order' => null, 'rows' => []];
        }

        $existingWorkOrder = $this->workOrderForOrder($order);
        $manualItems = collect(array_values($items))
            ->map(fn (array $item, int $index) => ['index' => $index, 'item' => $item])
            ->filter(fn (array $row) => $this->isManualItem($row['item'], $order, $existingWorkOrder))
            ->values();

        if ($manualItems->isEmpty()) {
            $this->releaseForOrder($orderId);

            return ['work_order' => null, 'rows' => []];
        }

        $workOrder = $existingWorkOrder;
        if (! $workOrder) {
            $number = $this->reservedInternalWorkOrderNumber($order) ?? $this->nextInternalWorkOrderNumber();
            $now = now();

            $workOrderId = DB::table('arbetsordrar')->insertGetId([
                'arbetsorder_nr' => $number,
                'utskriftsdatum' => $now->toDateString(),
                'handlaggare' => 'Stuckbema',
                'fardig_datum' => null,
                'ordernr' => null,
                'projekt' => null,
                'startdatum' => $order->desired_delivery_date ?? $now->toDateString(),
                'startdatum_text' => $this->deliveryTimeText($order),
                'kontaktperson' => $order->mottagare ?? $order->recipient_name ?? null,
                'telefon' => $order->tele ?? $order->recipient_phone ?? null,
                'arbetsplats' => $order->adress ?? $order->delivery_address ?? null,
                'postadress' => $order->adress ?? $order->delivery_address ?? null,
                'arbetsbeskrivning_raw' => 'Intern arbetsorder skapad från manuell leverans.',
                'handskrivet_raw' => null,
                'is_kopia' => false,
                'raw_text' => 'Intern arbetsorder skapad automatiskt från leverans '.$orderId.'.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $workOrder = DB::table('arbetsordrar')->where('id', $workOrderId)->first();
        } else {
            DB::table('arbetsordrar')->where('id', $workOrder->id)->update([
                'startdatum' => $order->desired_delivery_date ?? $workOrder->startdatum,
                'startdatum_text' => $this->deliveryTimeText($order) ?: $workOrder->startdatum_text,
                'kontaktperson' => $order->mottagare ?? $order->recipient_name ?? $workOrder->kontaktperson,
                'telefon' => $order->tele ?? $order->recipient_phone ?? $workOrder->telefon,
                'arbetsplats' => $order->adress ?? $order->delivery_address ?? $workOrder->arbetsplats,
                'postadress' => $order->adress ?? $order->delivery_address ?? $workOrder->postadress,
                'updated_at' => now(),
            ]);

            $workOrder = DB::table('arbetsordrar')->where('id', $workOrder->id)->first();
        }

        $this->storeOrderReference($orderId, $workOrder);
        $rows = $this->replaceRows($workOrder, $manualItems);

        return ['work_order' => $workOrder, 'rows' => $rows];
    }

    public function releaseForOrder(string $orderId): void
    {
        if (! Schema::hasColumn('orders', 'internal_work_order_id')) {
            return;
        }

        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        if (! $order || empty($order->internal_work_order_id)) {
            return;
        }

        $workOrder = DB::table('arbetsordrar')->where('id', $order->internal_work_order_id)->first();
        if ($workOrder && str_contains((string) $workOrder->raw_text, 'skapad automatiskt från leverans '.$orderId)) {
            DB::table('arbetsorder_rader')->where('arbetsorder_id', $workOrder->id)->delete();
            DB::table('arbetsordrar')->where('id', $workOrder->id)->delete();
        }

        DB::table('orders')->where('id', $orderId)->update([
            'internal_work_order_id' => null,
            'internal_work_order_number' => null,
            'updated_at' => now(),
        ]);
    }

    private function isManualItem(array $item, object $order, ?object $workOrder): bool
    {
        $workOrderNumber = trim((string) ($item['workOrderNumber'] ?? $item['work_order_number'] ?? ''));
        $article = trim((string) ($item['artikel'] ?? $item['article_number'] ?? $item['article'] ?? $item['model'] ?? ''));
        $quantity = trim((string) ($item['antal'] ?? $item['quantity'] ?? ''));
        $hasContent = $article !== '' || $quantity !== '';

        if (! $hasContent) {
            return false;
        }

        if (! empty($item['__internal_manual_work_order']) || ! empty($item['isInternalWorkOrder']) || ! empty($item['internalWorkOrder'])) {
            return true;
        }

        if ($workOrderNumber !== '') {
            return $this->isOrderInternalWorkOrderNumber($workOrderNumber, $order, $workOrder);
        }

        return true;
    }

    private function workOrderForOrder(object $order): ?object
    {
        if (Schema::hasColumn('orders', 'internal_work_order_id') && ! empty($order->internal_work_order_id)) {
            $workOrder = DB::table('arbetsordrar')->where('id', $order->internal_work_order_id)->first();
            if ($workOrder) {
                return $workOrder;
            }
        }

        if (Schema::hasColumn('orders', 'internal_work_order_number') && ! empty($order->internal_work_order_number)) {
            return DB::table('arbetsordrar')->where('arbetsorder_nr', $order->internal_work_order_number)->first();
        }

        return null;
    }

    private function nextInternalWorkOrderNumber(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::selectOne("select pg_advisory_xact_lock(hashtext('stuckbema_internal_work_order_number'))");
            $this->ensurePostgresSequence();

            for ($attempt = 0; $attempt < 100; $attempt++) {
                $number = (int) DB::selectOne("select nextval(?::regclass) as number", ['internal_work_order_number_seq'])->number;
                if (! DB::table('arbetsordrar')->where('arbetsorder_nr', $number)->exists()) {
                    return $number;
                }
            }
        }

        return $this->maxInternalWorkOrderNumber() + 1;
    }

    private function ensurePostgresSequence(): void
    {
        $sequence = DB::selectOne("select to_regclass('internal_work_order_number_seq') as name");
        if ($sequence?->name) {
            return;
        }

        DB::statement('create sequence if not exists internal_work_order_number_seq');
        $nextNumber = $this->maxInternalWorkOrderNumber() + 1;
        $nextNumber = max(1, $nextNumber);

        DB::select('select setval(?::regclass, ?::bigint, false)', [
            'internal_work_order_number_seq',
            $nextNumber,
        ]);
    }

    private function maxInternalWorkOrderNumber(): int
    {
        $orderReferenceMax = 0;
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'internal_work_order_number')) {
            $orderReferenceMax = (int) DB::table('orders')
                ->whereNotNull('internal_work_order_number')
                ->max('internal_work_order_number');
        }

        $rawTextMax = 0;
        if (Schema::hasTable('arbetsordrar') && Schema::hasColumn('arbetsordrar', 'raw_text')) {
            $rawTextMax = (int) DB::table('arbetsordrar')
                ->where('raw_text', 'like', 'Intern arbetsorder skapad automatiskt från leverans %')
                ->max('arbetsorder_nr');
        }

        return max($orderReferenceMax, $rawTextMax, 0);
    }

    private function reservedInternalWorkOrderNumber(object $order): ?int
    {
        if (! Schema::hasColumn('orders', 'internal_work_order_number') || empty($order->internal_work_order_number)) {
            return null;
        }

        $number = (int) $order->internal_work_order_number;
        if ($number <= 0) {
            return null;
        }

        $exists = DB::table('arbetsordrar')->where('arbetsorder_nr', $number)->exists();

        return $exists ? null : $number;
    }

    private function isOrderInternalWorkOrderNumber(string $workOrderNumber, object $order, ?object $workOrder): bool
    {
        if (! ctype_digit($workOrderNumber)) {
            return false;
        }

        $number = (int) $workOrderNumber;
        if ($workOrder && (int) $workOrder->arbetsorder_nr === $number) {
            return true;
        }

        return Schema::hasColumn('orders', 'internal_work_order_number')
            && ! empty($order->internal_work_order_number)
            && (int) $order->internal_work_order_number === $number;
    }

    private function storeOrderReference(string $orderId, object $workOrder): void
    {
        if (! Schema::hasColumn('orders', 'internal_work_order_id')) {
            return;
        }

        DB::table('orders')->where('id', $orderId)->update([
            'internal_work_order_id' => $workOrder->id,
            'internal_work_order_number' => $workOrder->arbetsorder_nr,
            'updated_at' => now(),
        ]);
    }

    private function replaceRows(object $workOrder, Collection $manualItems): array
    {
        DB::table('arbetsorder_rader')->where('arbetsorder_id', $workOrder->id)->delete();

        $rows = [];
        foreach ($manualItems as $position => $row) {
            $item = $row['item'];
            $article = trim((string) ($item['artikel'] ?? $item['article_number'] ?? $item['article'] ?? $item['model'] ?? ''));
            $quantity = trim((string) ($item['antal'] ?? $item['quantity'] ?? ''));
            $unit = trim((string) ($item['enhet'] ?? $item['unit'] ?? '')) ?: app(LeveransKalkylService::class)->unitFromQuantity($quantity) ?: 'st';
            $quantityValue = app(DeliveryOrderItemService::class)->quantityValue($quantity);
            $now = now();

            $rowId = DB::table('arbetsorder_rader')->insertGetId([
                'arbetsorder_id' => $workOrder->id,
                'arbetsorder_nr' => $workOrder->arbetsorder_nr,
                'rad_nr' => $position + 1,
                'artikel' => $article,
                'artikel_normalized' => ArbetsorderParser::normalizeArticle($article),
                'antal' => $quantityValue,
                'enhet' => $unit,
                'raw_line' => trim($article.' '.$quantity),
                'kommentar' => 'Skapad från manuell leveransrad.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rows[$row['index']] = DB::table('arbetsorder_rader')->where('id', $rowId)->first();
        }

        return $rows;
    }

    private function deliveryTimeText(object $order): ?string
    {
        $date = $order->desired_delivery_date ?? null;
        $time = $order->desired_delivery_time ?? null;

        return trim(($date ?: '').' '.($time ?: '')) ?: null;
    }
}
