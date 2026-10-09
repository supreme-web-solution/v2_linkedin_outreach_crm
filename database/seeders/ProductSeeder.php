<?php

namespace Database\Seeders;

use App\Models\V2Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $bundles = config('billing.bundles', []);
        $products = config('billing.jvzoo_products', []);

        $productIds = [];

        foreach ($products as $product) {
            $entitlements = isset($product['bundle'])
                ? ($bundles[$product['bundle']] ?? [])
                : ($product['entitlements'] ?? []);

            V2Product::query()->updateOrCreate(
                ['product_id' => (string) $product['product_id']],
                [
                    'name' => $product['name'],
                    'entitlements' => array_values($entitlements),
                ]
            );

            $productIds[] = (string) $product['product_id'];
        }

        V2Product::query()->whereNotIn('product_id', $productIds)->delete();
    }
}
