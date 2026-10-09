<?php

namespace Tests\Feature\Billing;

use App\Mail\WelcomeLicenseMail;
use App\Models\User;
use App\Models\V2Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JvzooProductAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-jvzoo-secret';

    private const FULL = ['FE', 'Bundle', 'Reseller', 'AffiliateCampaignVault', 'ProfitMultiplier'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.jvzoo_secret', self::SECRET);
        config()->set('billing.require_entitlement', true);
        Mail::fake();
        $this->withoutVite();
    }

    public function test_seeder_syncs_new_products_and_removes_old_ones(): void
    {
        V2Product::query()->create(['product_id' => '433885', 'name' => 'Old FE', 'entitlements' => ['FE']]);

        $this->seed(ProductSeeder::class);

        $this->assertDatabaseMissing('v2_products', ['product_id' => '433885']);
        $this->assertEqualsCanonicalizing(
            ['455425', '455427', '456171', '456173', '456225', '456227', '456183', '456187', '456221', '456223'],
            V2Product::query()->pluck('product_id')->all()
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function productProvider(): array
    {
        return [
            'FE' => ['455425', ['FE']],
            'Bundle' => ['455427', self::FULL],
            'Fast-pass 456171' => ['456171', self::FULL],
            'Fast-pass 456173' => ['456173', self::FULL],
            'Reseller 456225' => ['456225', ['Reseller']],
            'Reseller 456227' => ['456227', ['Reseller']],
            'Affiliate Campaign Vault 456183' => ['456183', ['AffiliateCampaignVault']],
            'Affiliate Campaign Vault 456187' => ['456187', ['AffiliateCampaignVault']],
            'Profit Multiplier 456221' => ['456221', ['ProfitMultiplier']],
            'Profit Multiplier 456223' => ['456223', ['ProfitMultiplier']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('productProvider')]
    public function test_ipn_sale_grants_product_entitlements(string $productId, array $expected): void
    {
        $this->seed(ProductSeeder::class);

        $this->post('/webhooks/jvzoo', $this->ipn($productId, 'buyer@example.com', 'TX-'.$productId))
            ->assertOk();

        $user = User::query()->where('email', 'buyer@example.com')->firstOrFail();
        $this->assertEqualsCanonicalizing($expected, $user->entitlements);
        Mail::assertSent(WelcomeLicenseMail::class, fn ($mail) => $mail->hasTo('buyer@example.com'));
    }

    public function test_admin_can_assign_new_entitlements(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'entitlements' => self::FULL]);
        $user = User::factory()->create(['entitlements' => ['FE']]);

        $this->actingAs($admin)->getJson("/admin/users/{$user->id}/permissions")
            ->assertOk()
            ->assertJsonPath('options', self::FULL);

        $this->actingAs($admin)->put('/admin/users/entitlements', [
            'user_id' => $user->id,
            'entitlements' => ['FE', 'Reseller', 'AffiliateCampaignVault', 'ProfitMultiplier', 'OTO5'],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['FE', 'Reseller', 'AffiliateCampaignVault', 'ProfitMultiplier'],
            $user->fresh()->entitlements
        );
    }

    public function test_addon_purchases_stack_on_existing_fe_access(): void
    {
        $this->seed(ProductSeeder::class);

        $this->post('/webhooks/jvzoo', $this->ipn('455425', 'stack@example.com', 'TX-1'))->assertOk();
        $this->post('/webhooks/jvzoo', $this->ipn('456225', 'stack@example.com', 'TX-2'))->assertOk();
        $this->post('/webhooks/jvzoo', $this->ipn('456183', 'stack@example.com', 'TX-3'))->assertOk();

        $user = User::query()->where('email', 'stack@example.com')->firstOrFail();
        $this->assertEqualsCanonicalizing(['FE', 'Reseller', 'AffiliateCampaignVault'], $user->entitlements);

        $this->actingAs($user)->get('/reseller/users')->assertOk();
        $this->actingAs($user)->get('/affiliate-campaign-vault')->assertOk();
        $this->actingAs($user)->get('/profit-multiplier')->assertForbidden();
        $this->actingAs($user)->get('/bonus/dfy-campaign')->assertForbidden();
    }

    public function test_old_product_ids_are_rejected(): void
    {
        $this->seed(ProductSeeder::class);

        $this->post('/webhooks/jvzoo', $this->ipn('433885', 'old@example.com', 'TX-OLD'))
            ->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'old@example.com']);
    }

    public function test_fe_only_user_cannot_open_addon_or_bonus_pages(): void
    {
        $user = User::factory()->create(['entitlements' => ['FE']]);

        $this->actingAs($user)->get('/reseller/users')->assertForbidden();
        $this->actingAs($user)->get('/affiliate-campaign-vault')->assertForbidden();
        $this->actingAs($user)->get('/profit-multiplier')->assertForbidden();
        $this->actingAs($user)->get('/bonus/upsell-unlimited')->assertForbidden();
    }

    public function test_bundle_user_can_open_every_addon_and_bonus_page(): void
    {
        $user = User::factory()->create(['entitlements' => self::FULL]);

        foreach ([
            '/reseller/users',
            '/affiliate-campaign-vault',
            '/profit-multiplier',
            '/bonus/upsell-unlimited',
            '/bonus/market-agency-setup',
            '/bonus/dfy-campaign',
            '/bonus/coach-program',
            '/bonus/unlimited-traffic',
        ] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_legacy_oto_entitlements_are_migrated(): void
    {
        $legacyFull = User::factory()->create(['entitlements' => ['FE', 'OTO1', 'OTO2', 'OTO3', 'OTO4', 'OTO5', 'OTO6', 'OTO7', 'OTO8', 'Bundle']]);
        $legacyReseller = User::factory()->create(['entitlements' => ['FE', 'OTO5']]);
        $legacyOtos = User::factory()->create(['entitlements' => ['FE', 'OTO2', 'OTO7']]);
        $feOnly = User::factory()->create(['entitlements' => ['FE']]);

        $migration = require database_path('migrations/2026_10_09_080000_migrate_legacy_oto_entitlements.php');
        $migration->up();

        $this->assertEqualsCanonicalizing(self::FULL, $legacyFull->fresh()->entitlements);
        $this->assertEqualsCanonicalizing(['FE', 'Reseller'], $legacyReseller->fresh()->entitlements);
        $this->assertEqualsCanonicalizing(['FE'], $legacyOtos->fresh()->entitlements);
        $this->assertEqualsCanonicalizing(['FE'], $feOnly->fresh()->entitlements);
    }

    /**
     * @return array<string, string>
     */
    private function ipn(string $productId, string $email, string $transactionId): array
    {
        $fields = [
            'ccustemail' => $email,
            'ccustname' => 'Buyer',
            'cproditem' => $productId,
            'ctransaction' => 'SALE',
            'ctransreceipt' => $transactionId,
        ];

        $keys = array_keys($fields);
        sort($keys);
        $pop = '';
        foreach ($keys as $key) {
            $pop .= $fields[$key].'|';
        }
        $pop .= self::SECRET;

        $fields['cverify'] = strtoupper(substr(sha1($pop), 0, 8));

        return $fields;
    }
}
