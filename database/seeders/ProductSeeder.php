<?php

namespace Database\Seeders;

use App\Models\V2Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $full = config('billing.bundles.full', [
            'FE', 'OTO1', 'OTO2', 'OTO3', 'OTO4', 'OTO5', 'OTO6', 'OTO7', 'OTO8', 'Bundle',
        ]);

        $products = [
            [
                'product_id' => '433885',
                'name' => 'FE Access',
                'entitlements' => ['FE'],
            ],
            [
                'product_id' => '434227',
                'name' => 'Full Access',
                'entitlements' => $full,
            ],
            [
                'product_id' => '434229',
                'name' => 'Full Access',
                'entitlements' => $full,
            ],
            [
                'product_id' => '433887',
                'name' => 'Full Access',
                'entitlements' => $full,
            ],
        ];

        foreach ($products as $product) {
            V2Product::query()->updateOrCreate(
                ['product_id' => $product['product_id']],
                $product
            );
        }
    }
}
