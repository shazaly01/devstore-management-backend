<?php

namespace App\Services\Pricing;

use App\Models\ItemPriceHistory;
use App\Models\ItemUnit;
use App\Models\ItemUnitPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class PriceRollbackService
{
    /**
     * تنفيذ التراجع عن دفعة تسعير كاملة واسترجاع الأسعار والتكاليف السابقة بأمان
     *
     * @param string $batchId
     * @param int $userId
     * @param string|null $notes
     * @return array
     * @throws Exception
     */
    public function rollback(string $batchId, int $userId, ?string $notes = null): array
    {
        return DB::transaction(function () use ($batchId, $userId, $notes) {
            $historyRecords = ItemPriceHistory::where('batch_id', $batchId)->get();

            if ($historyRecords->isEmpty()) {
                throw new Exception("لا توجد سجلات مرتبطة برقم دفعة التسعير المحددة ({$batchId}).");
            }

            $rollbackBatchId = (string) Str::uuid();
            $restoredCount = 0;
            $rollbackHistories = [];
            $now = now();

            foreach ($historyRecords as $history) {
                // استرجاع أسعار فئات الأسعار المخصصة
                if ($history->price_list_id !== null) {
                    $unitPrice = ItemUnitPrice::where('item_unit_id', $history->item_unit_id)
                        ->where('price_list_id', $history->price_list_id)
                        ->first();

                    if ($unitPrice) {
                        $currentPrice = (float) $unitPrice->price;
                        $targetOldPrice = (float) $history->old_price;

                        $rollbackHistories[] = [
                            'batch_id'          => $rollbackBatchId,
                            'item_id'           => $history->item_id,
                            'item_unit_id'      => $history->item_unit_id,
                            'price_list_id'     => $history->price_list_id,
                            'old_price'         => $currentPrice,
                            'new_price'         => $targetOldPrice,
                            'old_cost'          => (float) $history->new_cost,
                            'new_cost'          => (float) $history->old_cost,
                            'currency_id'       => $history->currency_id,
                            'exchange_rate'     => $history->exchange_rate,
                            'change_percentage' => $currentPrice > 0
                                ? round((($targetOldPrice - $currentPrice) / $currentPrice) * 100, 2)
                                : 0.0,
                            'change_type'       => 'rollback',
                            'user_id'           => $userId,
                            'notes'             => $notes ?: "تراجع عن الدفعة رقم: {$batchId}",
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ];

                        $unitPrice->update([
                            'price' => $targetOldPrice,
                        ]);
                    }
                } else {
                    // استرجاع السعر والتكلفة الأساسية للوحدة
                    $itemUnit = ItemUnit::find($history->item_unit_id);

                    if ($itemUnit) {
                        $currentPrice = (float) $itemUnit->price;
                        $targetOldPrice = (float) $history->old_price;
                        $targetOldCost = $history->old_cost !== null ? (float) $history->old_cost : (float) $itemUnit->cost;

                        $rollbackHistories[] = [
                            'batch_id'          => $rollbackBatchId,
                            'item_id'           => $history->item_id,
                            'item_unit_id'      => $history->item_unit_id,
                            'price_list_id'     => null,
                            'old_price'         => $currentPrice,
                            'new_price'         => $targetOldPrice,
                            'old_cost'          => (float) $itemUnit->cost,
                            'new_cost'          => $targetOldCost,
                            'currency_id'       => $history->currency_id,
                            'exchange_rate'     => $history->exchange_rate,
                            'change_percentage' => $currentPrice > 0
                                ? round((($targetOldPrice - $currentPrice) / $currentPrice) * 100, 2)
                                : 0.0,
                            'change_type'       => 'rollback',
                            'user_id'           => $userId,
                            'notes'             => $notes ?: "تراجع عن الدفعة رقم: {$batchId}",
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ];

                        $itemUnit->update([
                            'price' => $targetOldPrice,
                            'cost'  => $targetOldCost,
                        ]);

                        $restoredCount++;
                    }
                }
            }

            if (!empty($rollbackHistories)) {
                foreach (array_chunk($rollbackHistories, 250) as $chunk) {
                    ItemPriceHistory::insert($chunk);
                }
            }

            return [
                'rollback_batch_id' => $rollbackBatchId,
                'original_batch_id' => $batchId,
                'restored_count'    => $restoredCount,
            ];
        });
    }
}