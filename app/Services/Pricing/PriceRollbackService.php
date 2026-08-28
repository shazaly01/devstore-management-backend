<?php

namespace App\Services\Pricing;

use App\Models\ItemPriceHistory;
use App\Models\ItemPriceHistoryMain;
use App\Models\ItemUnit;
use App\Models\ItemUnitPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class PriceRollbackService
{
    /**
     * تنفيذ التراجع عن مجموعة تسعير كاملة واسترجاع الأسعار والتكاليف السابقة بأمان
     *
     * @param array $criteria
     * @param int $userId
     * @param string|null $notes
     * @return array
     * @throws Exception
     */
    public function rollback(array $criteria, int $userId, ?string $notes = null): array
    {
        return DB::transaction(function () use ($criteria, $userId, $notes) {
            // 1. البحث عن دفعة التسعير الرئيسية
            $query = ItemPriceHistoryMain::query();

            if (!empty($criteria['main_id'])) {
                $query->where('id', $criteria['main_id']);
            } elseif (!empty($criteria['batch_code'])) {
                $query->where('batch_code', $criteria['batch_code']);
            } elseif (!empty($criteria['batch_id'])) {
                $batchMain = ItemPriceHistoryMain::whereHas('details', function ($q) use ($criteria) {
                    $q->where('batch_id', $criteria['batch_id']);
                })->first();

                if ($batchMain) {
                    $query->where('id', $batchMain->id);
                }
            }

            $mainBatch = $query->first();

            if (!$mainBatch) {
                throw new Exception("لم يتم العثور على دفعة التسعير المحددة.");
            }

            // 2. التحقق من عدم التراجع المسبق عن هذه الدفعة
            if ($mainBatch->is_rolled_back) {
                $rolledBackDate = $mainBatch->rolled_back_at ? $mainBatch->rolled_back_at->format('Y-m-d H:i') : 'سابقاً';
                throw new Exception("تم التراجع عن هذه الدفعة مسبقاً بتاريخ ({$rolledBackDate}). لا يمكن تكرار العملية.");
            }

            $historyRecords = ItemPriceHistory::where('item_price_history_main_id', $mainBatch->id)->get();

            // في حال كانت السجلات قديمة قبل الهيكلة الجديدة
            if ($historyRecords->isEmpty() && !empty($criteria['batch_id'])) {
                $historyRecords = ItemPriceHistory::where('batch_id', $criteria['batch_id'])->get();
            }

            if ($historyRecords->isEmpty()) {
                throw new Exception("لا توجد سجلات تفصيلية مرتبطة بدفعة التسعير ({$mainBatch->batch_code}).");
            }

            $rollbackUuid = (string) Str::uuid();
            $rollbackBatchCode = 'RBK-' . date('Ymd') . '-' . strtoupper(Str::random(5));
            $now = now();

            // 3. إنشاء رأس دفعة التراجع الجديدة
            $rollbackMain = ItemPriceHistoryMain::create([
                'batch_code'    => $rollbackBatchCode,
                'category_id'   => $mainBatch->category_id,
                'currency_id'   => $mainBatch->currency_id,
                'exchange_rate' => $mainBatch->exchange_rate,
                'change_type'   => 'rollback',
                'items_count'   => 0,
                'notes'         => $notes ?: "تراجع عن الدفعة: {$mainBatch->batch_code}",
                'user_id'       => $userId,
            ]);

            $restoredCount = 0;
            $rollbackHistories = [];

            // 4. استرجاع وتطبيق الأسعار والتكاليف السابقة
            foreach ($historyRecords as $history) {
                if ($history->price_list_id !== null) {
                    $unitPrice = ItemUnitPrice::where('item_unit_id', $history->item_unit_id)
                        ->where('price_list_id', $history->price_list_id)
                        ->first();

                    if ($unitPrice) {
                        $currentPrice = (float) $unitPrice->price;
                        $targetOldPrice = (float) $history->old_price;

                        $rollbackHistories[] = [
                            'item_price_history_main_id' => $rollbackMain->id,
                            'batch_id'                   => $rollbackUuid,
                            'item_id'                    => $history->item_id,
                            'item_unit_id'               => $history->item_unit_id,
                            'price_list_id'              => $history->price_list_id,
                            'old_price'                  => $currentPrice,
                            'new_price'                  => $targetOldPrice,
                            'old_cost'                   => (float) $history->new_cost,
                            'new_cost'                   => (float) $history->old_cost,
                            'currency_id'                => $history->currency_id,
                            'exchange_rate'              => $history->exchange_rate,
                            'change_percentage'          => $currentPrice > 0
                                ? round((($targetOldPrice - $currentPrice) / $currentPrice) * 100, 2)
                                : 0.0,
                            'change_type'                => 'rollback',
                            'user_id'                    => $userId,
                            'notes'                      => $notes ?: "تراجع عن الدفعة: {$mainBatch->batch_code}",
                            'created_at'                 => $now,
                            'updated_at'                 => $now,
                        ];

                        $unitPrice->update([
                            'price' => $targetOldPrice,
                        ]);
                    }
                } else {
                    $itemUnit = ItemUnit::find($history->item_unit_id);

                    if ($itemUnit) {
                        $currentPrice = (float) $itemUnit->price;
                        $targetOldPrice = (float) $history->old_price;
                        $targetOldCost = $history->old_cost !== null ? (float) $history->old_cost : (float) $itemUnit->cost;

                        $rollbackHistories[] = [
                            'item_price_history_main_id' => $rollbackMain->id,
                            'batch_id'                   => $rollbackUuid,
                            'item_id'                    => $history->item_id,
                            'item_unit_id'               => $history->item_unit_id,
                            'price_list_id'              => null,
                            'old_price'                  => $currentPrice,
                            'new_price'                  => $targetOldPrice,
                            'old_cost'                   => (float) $itemUnit->cost,
                            'new_cost'                   => $targetOldCost,
                            'currency_id'                => $history->currency_id,
                            'exchange_rate'              => $history->exchange_rate,
                            'change_percentage'          => $currentPrice > 0
                                ? round((($targetOldPrice - $currentPrice) / $currentPrice) * 100, 2)
                                : 0.0,
                            'change_type'                => 'rollback',
                            'user_id'                    => $userId,
                            'notes'                      => $notes ?: "تراجع عن الدفعة: {$mainBatch->batch_code}",
                            'created_at'                 => $now,
                            'updated_at'                 => $now,
                        ];

                        $itemUnit->update([
                            'price' => $targetOldPrice,
                            'cost'  => $targetOldCost,
                        ]);

                        $restoredCount++;
                    }
                }
            }

            // 5. حفظ سجلات التراجع التفصيلية مجمعة
            if (!empty($rollbackHistories)) {
                foreach (array_chunk($rollbackHistories, 250) as $chunk) {
                    ItemPriceHistory::insert($chunk);
                }
            }

            // 6. تحديث رأس دفعة التراجع بعدد الوحدات
            $rollbackMain->update(['items_count' => $restoredCount]);

            // 7. تحديث الدفعة الأصلية وإغلاقها كدفعة تم التراجع عنها
            $mainBatch->update([
                'is_rolled_back'   => true,
                'rolled_back_at'   => $now,
                'rolled_back_by'   => $userId,
                'rollback_main_id' => $rollbackMain->id,
            ]);

            return [
                'main_id'             => $mainBatch->id,
                'batch_code'          => $mainBatch->batch_code,
                'rollback_main_id'    => $rollbackMain->id,
                'rollback_batch_code' => $rollbackMain->batch_code,
                'restored_count'      => $restoredCount,
            ];
        });
    }
}