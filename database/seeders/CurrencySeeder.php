<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Currency;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            [
                'name'       => 'الجنيه السوداني',
                'code'       => 'SDG',
                'symbol'     => 'ج.س',
                'is_default' => true,
                'is_active'  => true,
            ],
            [
                'name'       => 'الدولار الأمريكي',
                'code'       => 'USD',
                'symbol'     => '$',
                'is_default' => false,
                'is_active'  => true,
            ],
            [
                'name'       => 'الريال السعودي',
                'code'       => 'SAR',
                'symbol'     => 'ر.س',
                'is_default' => false,
                'is_active'  => true,
            ],
            [
                'name'       => 'الدرهم الإماراتي',
                'code'       => 'AED',
                'symbol'     => 'د.إ',
                'is_default' => false,
                'is_active'  => true,
            ],
            [
                'name'       => 'الجنيه المصري',
                'code'       => 'EGP',
                'symbol'     => 'ج.م',
                'is_default' => false,
                'is_active'  => true,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::firstOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}