<?php

return [

    'require_entitlement' => env('BILLING_REQUIRE_ENTITLEMENT', true),

    'jvzoo_secret' => env('JVZOO_SECRET'),

    'platform_admin_emails' => array_filter(array_map(
        'trim',
        explode(',', env('PLATFORM_ADMIN_EMAILS', ''))
    )),

    'entitlements' => [
        'FE',
        'Bundle',
        'Reseller',
        'AffiliateCampaignVault',
        'ProfitMultiplier',
    ],

    'bundles' => [
        'fe' => ['FE'],
        'reseller' => ['FE', 'Reseller'],
        'full' => ['FE', 'Bundle', 'Reseller', 'AffiliateCampaignVault', 'ProfitMultiplier'],
    ],

    /*
     * JVZoo product IDs (cproditem) and the entitlements each purchase grants.
     * Synced into v2_products by ProductSeeder; IDs not listed here are removed.
     */
    'jvzoo_products' => [
        ['product_id' => '455425', 'name' => 'FE', 'bundle' => 'fe'],
        ['product_id' => '455427', 'name' => 'Bundle', 'bundle' => 'full'],
        ['product_id' => '456171', 'name' => 'Fast-pass Bundle', 'bundle' => 'full'],
        ['product_id' => '456173', 'name' => 'Fast-pass Bundle', 'bundle' => 'full'],
        ['product_id' => '456225', 'name' => 'Reseller', 'entitlements' => ['Reseller']],
        ['product_id' => '456227', 'name' => 'Reseller', 'entitlements' => ['Reseller']],
        ['product_id' => '456183', 'name' => 'Affiliate Campaign Vault', 'entitlements' => ['AffiliateCampaignVault']],
        ['product_id' => '456187', 'name' => 'Affiliate Campaign Vault', 'entitlements' => ['AffiliateCampaignVault']],
        ['product_id' => '456221', 'name' => 'Profit Multiplier', 'entitlements' => ['ProfitMultiplier']],
        ['product_id' => '456223', 'name' => 'Profit Multiplier', 'entitlements' => ['ProfitMultiplier']],
    ],

];
