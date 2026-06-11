<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeveransKalkylService
{
    public function matchArbetsorderRad(?int $arbetsorderNr, ?string $artikel): ?object
    {
        if (! $arbetsorderNr || trim((string) $artikel) === '') {
            return null;
        }

        if (! $this->arbetsorderForNumber($arbetsorderNr)) {
            return null;
        }

        $candidates = ArbetsorderParser::articleCandidates($artikel);

        return DB::table('arbetsorder_rader')
            ->where('arbetsorder_nr', $arbetsorderNr)
            ->whereIn('artikel_normalized', $candidates)
            ->orderBy('rad_nr')
            ->first();
    }

    public function arbetsorderForNumber(?int $arbetsorderNr): ?object
    {
        if (! $arbetsorderNr) {
            return null;
        }

        $query = DB::table('arbetsordrar')->where('arbetsorder_nr', $arbetsorderNr);
        if (Schema::hasColumn('arbetsordrar', 'hidden_at')) {
            $query->whereNull('hidden_at');
        }

        return $query->first();
    }

    public function calculationForItem(object $item, ?string $currentOrderId = null): array
    {
        $arbetsorderNr = $this->itemArbetsorderNr($item);
        $articleNormalized = ($item->artikel_normalized ?? null)
            ?: ArbetsorderParser::normalizeArticle($item->artikel ?? $item->article ?? '');
        $arbetsorderRadId = $item->arbetsorder_rad_id ?? null;
        $arbetsorderRad = $arbetsorderRadId
            ? DB::table('arbetsorder_rader')->where('id', $arbetsorderRadId)->first()
            : $this->matchArbetsorderRad($arbetsorderNr, $item->artikel ?? $item->article ?? null);
        $articleNormalized = $arbetsorderRad->artikel_normalized ?? $articleNormalized;
        $bestallt = $arbetsorderRad?->antal !== null ? (float) $arbetsorderRad->antal : null;
        $levereratDenna = $this->currentDeliveredQuantity($item);
        $levereratTidigare = $this->previousDeliveredQuantity($arbetsorderNr, $articleNormalized, (int) ($item->id ?? 0), $currentOrderId);
        $levereratTotalt = $levereratTidigare + $levereratDenna;

        return [
            'arbetsorder_nr' => $arbetsorderNr,
            'arbetsorder_rad_id' => $arbetsorderRad?->id,
            'artikel_normalized' => $articleNormalized,
            'bestallt_antal' => $bestallt,
            'levererat_tidigare' => $levereratTidigare,
            'levererat_denna' => $levereratDenna,
            'levererat_totalt' => $levereratTotalt,
            'kvar' => $bestallt === null ? null : max(0, $bestallt - $levereratTotalt),
            'match_status' => $arbetsorderNr ? ($arbetsorderRad ? 'matched' : 'unmatched') : null,
            'match_warning' => $arbetsorderNr && ! $arbetsorderRad ? 'Artikeln hittades inte på arbetsordern' : null,
        ];
    }

    public function calculationsForOrder(string $orderId): array
    {
        return DB::table('order_items')
            ->where('order_id', $orderId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (object $item) => [$item->id => $this->calculationForItem($item, $orderId)])
            ->all();
    }

    public function summaryForOrder(string $orderId): array
    {
        return collect($this->calculationsForOrder($orderId))
            ->filter(fn (array $row) => $row['arbetsorder_nr'] && $row['artikel_normalized'])
            ->groupBy(fn (array $row) => $row['arbetsorder_nr'].'|'.$row['artikel_normalized'])
            ->map(function ($rows) {
                $first = $rows->first();

                return [
                    'arbetsorder_nr' => $first['arbetsorder_nr'],
                    'artikel_normalized' => $first['artikel_normalized'],
                    'bestallt_antal' => $first['bestallt_antal'],
                    'levererat_tidigare' => $rows->sum('levererat_tidigare'),
                    'levererat_denna' => $rows->sum('levererat_denna'),
                    'levererat_totalt' => $rows->sum('levererat_totalt'),
                    'kvar' => $first['bestallt_antal'] === null ? null : max(0, $first['bestallt_antal'] - $rows->sum('levererat_totalt')),
                ];
            })
            ->values()
            ->all();
    }

    public function statusForArbetsorderRad(object $arbetsorderRad): array
    {
        $bestallt = $arbetsorderRad->antal !== null ? (float) $arbetsorderRad->antal : null;
        $delivered = $this->previousDeliveredQuantity(
            (int) $arbetsorderRad->arbetsorder_nr,
            $arbetsorderRad->artikel_normalized,
            0,
            null,
        );

        return [
            'arbetsorder_nr' => (int) $arbetsorderRad->arbetsorder_nr,
            'arbetsorder_rad_id' => $arbetsorderRad->id,
            'artikel_normalized' => $arbetsorderRad->artikel_normalized,
            'bestallt_antal' => $bestallt,
            'levererat_totalt' => $delivered,
            'kvar' => $bestallt === null ? null : max(0, $bestallt - $delivered),
        ];
    }

    public function quantityDecimal($value): float
    {
        if (preg_match('/\d+(?:[,.]\d+)?/', (string) $value, $matches)) {
            return max(0, (float) str_replace(',', '.', $matches[0]));
        }

        return 0.0;
    }

    public function unitFromQuantity(?string $quantity): ?string
    {
        if (preg_match('/\b(lpm|mm|kg|m|st|x)\b/iu', (string) $quantity, $matches)) {
            return mb_strtolower($matches[1], 'UTF-8') === 'x' ? 'st' : mb_strtolower($matches[1], 'UTF-8');
        }

        return null;
    }

    private function previousDeliveredQuantity(?int $arbetsorderNr, ?string $articleNormalized, int $currentItemId, ?string $currentOrderId): float
    {
        if (! $arbetsorderNr || ! $articleNormalized) {
            return 0.0;
        }

        $rows = DB::table('order_items')
            ->leftJoin('orders', 'orders.id', '=', 'order_items.order_id')
            ->where(function ($query) use ($arbetsorderNr) {
                $query->where('order_items.arbetsorder_nr', $arbetsorderNr)
                    ->orWhere('order_items.work_order_number', (string) $arbetsorderNr);
            })
            ->where('order_items.artikel_normalized', $articleNormalized)
            ->when($currentItemId > 0, fn ($query) => $query->where('order_items.id', '<>', $currentItemId))
            ->when($currentOrderId, fn ($query) => $query->where('order_items.order_id', '<>', $currentOrderId))
            ->select('order_items.*', 'orders.status as order_status', 'orders.delivered_at as order_delivered_at')
            ->get();

        return $rows->sum(fn (object $row) => $this->historicalDeliveredQuantity($row));
    }

    private function currentDeliveredQuantity(object $item): float
    {
        if (($item->levererat_antal ?? null) !== null) {
            return (float) $item->levererat_antal;
        }

        if (($item->delivered_quantity ?? null) !== null && (float) $item->delivered_quantity > 0) {
            return (float) $item->delivered_quantity;
        }

        return $this->quantityDecimal($item->antal ?? $item->quantity ?? 0);
    }

    private function historicalDeliveredQuantity(object $item): float
    {
        if (($item->levererat_antal ?? null) !== null) {
            return (float) $item->levererat_antal;
        }

        if (($item->delivered_quantity ?? null) !== null && (float) $item->delivered_quantity > 0) {
            return (float) $item->delivered_quantity;
        }

        if (($item->delivered_at ?? null) || ($item->order_delivered_at ?? null) || in_array($item->order_status ?? null, ['delivered', 'levererad'], true)) {
            return $this->quantityDecimal($item->antal ?? $item->quantity ?? 0);
        }

        return 0.0;
    }

    private function itemArbetsorderNr(object $item): ?int
    {
        $value = $item->arbetsorder_nr ?? $item->work_order_number ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
