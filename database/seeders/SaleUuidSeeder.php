<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sale;
use Illuminate\Support\Str;

class SaleUuidSeeder extends Seeder
{
    /**
     * توليد UUID لكافة الفواتير السابقة التي لا تملك معرف وصول آمن.
     */
    public function run(): void
    {
        Sale::withTrashed()
            ->whereNull('uuid')
            ->chunkById(100, function ($sales) {
                foreach ($sales as $sale) {
                    $sale->uuid = (string) Str::uuid();
                    $sale->saveQuietly();
                }
            });
    }
}