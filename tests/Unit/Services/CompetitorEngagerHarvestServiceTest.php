<?php

namespace Tests\Unit\Services;

use App\Models\Audience;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Services\CompetitorEngagerHarvestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompetitorEngagerHarvestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.unipile_pacing.harvest_page_delay_min_ms' => 0,
            'services.unipile_pacing.harvest_page_delay_max_ms' => 0,
        ]);
    }

    public function test_harvest_stores_reactors_and_commenters_from_company_posts(): void
    {
        config([
            'services.unipile.base_url' => 'https://unipile.test/api/v1',
            'services.unipile.api_key' => 'test-key',
            'services.unipile.mock' => false,
            'services.competitor_followers.company_posts_limit' => 1,
            'services.competitor_followers.page_size' => 100,
            'services.competitor_followers.max_engagers_per_post' => 100,
            'services.unipile_pacing.harvest_page_delay_min_ms' => 0,
            'services.unipile_pacing.harvest_page_delay_max_ms' => 0,
            'services.unipile_pacing.account_lock_seconds' => 1,
            'services.unipile_pacing.account_lock_wait_seconds' => 1,
        ]);

        Http::fake([
            'unipile.test/api/v1/linkedin/search*' => function ($request) {
                $payload = $request->data();
                if (($payload['category'] ?? '') === 'companies') {
                    return Http::response([
                        'items' => [[
                            'type' => 'COMPANY',
                            'id' => '1035',
                            'name' => 'Microsoft',
                            'profile_url' => 'https://www.linkedin.com/company/microsoft/',
                        ]],
                    ]);
                }

                return Http::response([
                    'items' => [[
                        'type' => 'POST',
                        'social_id' => 'urn:li:activity:111',
                        'id' => '111',
                    ]],
                ]);
            },
            'unipile.test/api/v1/posts/urn%3Ali%3Aactivity%3A111/reactions*' => Http::response([
                'items' => [[
                    'author' => [
                        'id' => 'ACoAAA111',
                        'type' => 'INDIVIDUAL',
                        'name' => 'Jane Doe',
                        'headline' => 'VP Sales',
                        'public_identifier' => 'jane-doe',
                        'profile_url' => 'https://www.linkedin.com/in/jane-doe',
                    ],
                ], [
                    // Some Unipile payloads omit `name` and only send first/last.
                    'author' => [
                        'id' => 'ACoAAA333',
                        'type' => 'INDIVIDUAL',
                        'first_name' => 'Alex',
                        'last_name' => 'Rivera',
                        'headline' => 'Operator',
                        'public_identifier' => 'alex-rivera',
                        'profile_url' => 'https://www.linkedin.com/in/alex-rivera',
                        'network_distance' => 'THIRD_DEGREE',
                    ],
                ]],
            ]),
            'unipile.test/api/v1/posts/urn%3Ali%3Aactivity%3A111/comments*' => Http::response([
                'items' => [[
                    // Name lives on string `author`; profile fields on author_details.
                    'author_details' => [
                        'id' => 'ACoAAA222',
                        'is_company' => false,
                        'headline' => 'Founder',
                        'profile_url' => 'https://www.linkedin.com/in/john-smith',
                    ],
                    'author' => 'John Smith',
                ], [
                    // No name fields — fall back to public identifier slug.
                    'author_details' => [
                        'id' => 'ACoAAA444',
                        'headline' => 'Bookkeeper',
                        'public_identifier' => 'sam-taylor-a1b2c3d4',
                        'profile_url' => 'https://www.linkedin.com/in/sam-taylor-a1b2c3d4',
                        'network_distance' => 'SECOND_DEGREE',
                    ],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'acc_test_123',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'acc_test_123'],
        ]);

        $audience = Audience::query()->create([
            'audience_name' => 'Microsoft - Active Engagers',
            'audience_id' => now()->timestamp.$user->id,
            'audience_type' => 'LI',
            'user_id' => $user->id,
            'tag' => 'competitor_active_followers',
            'source' => 'linkedin_company_followers',
            'source_meta' => json_encode([
                'company_url' => 'https://www.linkedin.com/company/microsoft/',
            ]),
        ]);

        $result = app(CompetitorEngagerHarvestService::class)->harvest(
            $audience,
            $user->id,
            'https://www.linkedin.com/company/microsoft/'
        );

        $this->assertSame(4, $result['stored_count']);
        $this->assertSame(1, $result['posts_scanned']);
        $this->assertDatabaseHas('audience_lists', [
            'audience_id' => $audience->audience_id,
            'con_public_identifier' => 'jane-doe',
            'con_first_name' => 'Jane',
            'con_last_name' => 'Doe',
        ]);
        $this->assertDatabaseHas('audience_lists', [
            'audience_id' => $audience->audience_id,
            'con_public_identifier' => 'alex-rivera',
            'con_first_name' => 'Alex',
            'con_last_name' => 'Rivera',
        ]);
        $this->assertDatabaseHas('audience_lists', [
            'audience_id' => $audience->audience_id,
            'con_public_identifier' => 'john-smith',
            'con_first_name' => 'John',
            'con_last_name' => 'Smith',
        ]);
        $this->assertDatabaseHas('audience_lists', [
            'audience_id' => $audience->audience_id,
            'con_public_identifier' => 'sam-taylor-a1b2c3d4',
            'con_first_name' => 'Sam',
            'con_last_name' => 'Taylor',
        ]);
    }

    public function test_resolve_company_identifier_parses_linkedin_company_url(): void
    {
        $service = app(CompetitorEngagerHarvestService::class);

        $this->assertSame(
            'microsoft',
            $service->resolveCompanyIdentifier('https://www.linkedin.com/company/microsoft/')
        );
    }

    public function test_resolve_company_falls_back_to_direct_profile_when_search_is_empty(): void
    {
        config([
            'services.unipile.base_url' => 'https://unipile.test/api/v1',
            'services.unipile.api_key' => 'test-key',
            'services.unipile.mock' => false,
        ]);

        Http::fake([
            'unipile.test/api/v1/linkedin/search*' => Http::response([
                'items' => [],
                'paging' => ['total_count' => 0],
            ]),
            'unipile.test/api/v1/linkedin/company/microsoft*' => Http::response([
                'object' => 'CompanyProfile',
                'id' => '1035',
                'name' => 'Microsoft',
                'public_identifier' => 'microsoft',
                'profile_url' => 'https://www.linkedin.com/company/microsoft/',
            ]),
        ]);

        $provider = app(\App\V2\Integrations\Unipile\UnipileProvider::class);
        $service = app(CompetitorEngagerHarvestService::class);

        $company = $service->resolveCompany(
            $provider,
            'https://www.linkedin.com/company/microsoft/',
            ['account_id' => 'acc-test']
        );

        $this->assertSame('1035', $company['id']);
        $this->assertSame('Microsoft', $company['name']);
    }

    public function test_resolve_person_falls_back_to_people_search_when_profile_lookup_404s(): void
    {
        config([
            'services.unipile.base_url' => 'https://unipile.test/api/v1',
            'services.unipile.api_key' => 'test-key',
            'services.unipile.mock' => false,
        ]);

        Http::fake([
            'unipile.test/api/v1/users/*' => Http::response([
                'status' => 404,
                'type' => 'errors/resource_not_found',
                'title' => 'Resource not found.',
                'detail' => 'The requested resource were not found.Account not found.',
            ], 404),
            'unipile.test/api/v1/linkedin/search/parameters*' => Http::response([
                'items' => [[
                    'id' => 'ACoAAA888',
                    'title' => 'Eleazar Nzerem',
                ]],
            ]),
            'unipile.test/api/v1/linkedin/search*' => Http::response([
                'items' => [],
            ]),
        ]);

        $provider = app(\App\V2\Integrations\Unipile\UnipileProvider::class);
        $service = app(CompetitorEngagerHarvestService::class);

        $person = $service->resolvePerson(
            $provider,
            'https://www.linkedin.com/in/eleazarnzerem',
            ['account_id' => 'acc-test']
        );

        $this->assertSame('ACoAAA888', $person['id']);
        $this->assertSame('Eleazar Nzerem', $person['name']);
        $this->assertSame('eleazarnzerem', $person['public_identifier']);
        $this->assertSame('ACoAAA888', $person['search_parameter_id']);
    }

    public function test_detect_linkedin_source_parses_company_and_profile_urls(): void
    {
        $service = app(CompetitorEngagerHarvestService::class);

        $this->assertSame(
            ['type' => 'company', 'identifier' => 'microsoft'],
            $service->detectLinkedInSource('https://www.linkedin.com/company/microsoft/')
        );
        $this->assertSame(
            'satya-nadella',
            $service->resolveProfileIdentifier('https://www.linkedin.com/in/satya-nadella/')
        );
        $this->assertSame('person', $service->detectLinkedInSource('https://www.linkedin.com/in/satya-nadella')['type']);
    }

    public function test_harvest_stores_reactors_from_person_posts(): void
    {
        config([
            'services.unipile.base_url' => 'https://unipile.test/api/v1',
            'services.unipile.api_key' => 'test-key',
            'services.unipile.mock' => false,
            'services.competitor_followers.company_posts_limit' => 1,
            'services.competitor_followers.page_size' => 100,
            'services.competitor_followers.max_engagers_per_post' => 100,
            'services.unipile_pacing.harvest_page_delay_min_ms' => 0,
            'services.unipile_pacing.harvest_page_delay_max_ms' => 0,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/reactions')) {
                return Http::response([
                    'items' => [[
                        'author' => [
                            'id' => 'ACoAAA111',
                            'type' => 'INDIVIDUAL',
                            'name' => 'Jane Doe',
                            'headline' => 'VP Sales',
                            'public_identifier' => 'jane-doe',
                            'profile_url' => 'https://www.linkedin.com/in/jane-doe',
                        ],
                    ]],
                ]);
            }

            if (str_contains($url, '/comments')) {
                return Http::response(['items' => []]);
            }

            if (str_contains($url, '/posts')) {
                return Http::response([
                    'items' => [[
                        'type' => 'POST',
                        'social_id' => 'urn:li:activity:222',
                        'id' => '222',
                        'reaction_counter' => 4,
                    ]],
                ]);
            }

            if (str_contains($url, '/users/')) {
                return Http::response([
                    'id' => 'ACoAAA999',
                    'provider_id' => 'ACoAAA999',
                    'first_name' => 'Satya',
                    'last_name' => 'Nadella',
                    'public_identifier' => 'satya-nadella',
                    'profile_url' => 'https://www.linkedin.com/in/satya-nadella',
                ]);
            }

            return Http::response(['items' => []], 404);
        });

        $user = User::factory()->create();
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'acc_test_123',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'acc_test_123'],
        ]);

        $audience = Audience::query()->create([
            'audience_name' => 'Satya Nadella - Active Engagers',
            'audience_id' => now()->timestamp.$user->id,
            'audience_type' => 'LI',
            'user_id' => $user->id,
            'tag' => 'competitor_active_followers',
            'source' => 'linkedin_company_followers',
            'source_meta' => json_encode([
                'company_url' => 'https://www.linkedin.com/in/satya-nadella',
                'source_type' => 'person',
            ]),
        ]);

        $result = app(CompetitorEngagerHarvestService::class)->harvest(
            $audience,
            $user->id,
            'https://www.linkedin.com/in/satya-nadella'
        );

        $this->assertSame(1, $result['stored_count']);
        $this->assertSame(1, $result['posts_scanned']);
        $this->assertDatabaseHas('audience_lists', [
            'audience_id' => $audience->audience_id,
            'con_public_identifier' => 'jane-doe',
            'con_first_name' => 'Jane',
            'con_last_name' => 'Doe',
        ]);

        $audience->refresh();
        $meta = json_decode((string) $audience->source_meta, true);
        $this->assertSame('person', $meta['source_type'] ?? null);
        $this->assertSame('Satya Nadella', $meta['person_name'] ?? null);
    }
}
