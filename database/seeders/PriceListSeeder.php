<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PriceListSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('price_lists')->updateOrInsert(
            ['id' => 1],
            [
                'name' => 'قائمة عملاء A',
                'is_default' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ]
        );

        DB::table('price_lists')->updateOrInsert(
            ['id' => 2],
            [
                'name' => 'قائمة عملاء B',
                'is_default' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ]
        );
    }
}
