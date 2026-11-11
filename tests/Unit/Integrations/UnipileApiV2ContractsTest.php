<?php

namespace Tests\Unit\Integrations;

use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnipileApiV2ContractsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.unipile.base_url', 'https://unipile.test/v2');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);
        Config::set('services.unipile.endpoints.search', '/linkedin/search');
        Config::set('services.unipile.endpoints.search_people', '/linkedin/search/people');
        Config::set('services.unipile.endpoints.search_companies', '/linkedin/search/companies');
        Config::set('services.unipile.endpoints.search_posts', '/linkedin/search/posts');
        Config::set('services.unipile.endpoints.list_invitations', '/users/me/relation-requests');
        Config::set('services.unipile.endpoints.withdraw_invitation', '/users/me/relation-requests/%s/cancel');
        Config::set('services.unipile.endpoints.create_post', '/posts');
    }

    public function test_people_search_uses_v2_path_without_api_category(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/linkedin/search/people*' => Http::response(['data' => []], 200),
        ]);

        app(UnipileProvider::class)->searchPeople(
            ['keywords' => 'founder', 'title' => 'Founder', 'limit' => 5],
            ['account_id' => 'acc_li'],
        );

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return str_starts_with($request->url(), 'https://unipile.test/v2/acc_li/linkedin/search/people')
                && ($payload['keywords'] ?? null) === 'founder'
                && ($payload['advanced_keywords']['title'] ?? null) === 'Founder'
                && ! array_key_exists('title', $payload)
                && ! array_key_exists('count', $payload)
                && ! array_key_exists('offset', $payload)
                && ! array_key_exists('limit', $payload)
                && ! array_key_exists('api', $payload)
                && ! array_key_exists('category', $payload)
                && ! array_key_exists('cursor', $payload)
                && ! str_contains($request->url(), 'cursor=');
        });
    }

    public function test_people_search_paginates_with_cursor_query(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/linkedin/search/people' => Http::sequence()
                ->push([
                    'data' => [
                        ['id' => 'p1', 'display_name' => 'One'],
                        ['id' => 'p2', 'display_name' => 'Two'],
                    ],
                    'next_cursor' => 'cur_2',
                ], 200)
                ->push([
                    'data' => [
                        ['id' => 'p3', 'display_name' => 'Three'],
                    ],
                ], 200),
            'unipile.test/v2/acc_li/linkedin/search/people?*' => Http::response([
                'data' => [
                    ['id' => 'p3', 'display_name' => 'Three'],
                ],
            ], 200),
        ]);

        $result = app(UnipileProvider::class)->searchPeople(
            ['keywords' => 'laravel developer', 'limit' => 3],
            ['account_id' => 'acc_li'],
        );

        $items = UnipileProvider::extractListItems($result);
        $this->assertCount(3, $items);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://unipile.test/v2/acc_li/linkedin/search/people'
                && ($request->data()['keywords'] ?? null) === 'laravel developer';
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'cursor=cur_2')
                && ($request->data()['keywords'] ?? null) === 'laravel developer'
                && ! array_key_exists('offset', $request->data())
                && ! array_key_exists('count', $request->data());
        });
    }

    public function test_people_search_maps_legacy_network_depths_to_v2_integers(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/linkedin/search/people*' => Http::response(['data' => []], 200),
        ]);

        app(UnipileProvider::class)->searchPeople(
            [
                'keywords' => 'founder',
                'network_depths' => ['F', 'S', 'O'],
                'limit' => 5,
            ],
            ['account_id' => 'acc_li'],
        );

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return ($payload['keywords'] ?? null) === 'founder'
                && ($payload['network_distance'] ?? null) === [1, 2, 3]
                && ! array_key_exists('network_depths', $payload);
        });
    }

    public function test_posts_search_maps_from_company_and_uses_cursor_query(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/linkedin/search/posts*' => Http::response([
                'data' => [['id' => 'post_1', 'social_id' => 'urn:li:activity:1']],
                'next_cursor' => 'posts_cur_2',
            ], 200),
        ]);

        app(UnipileProvider::class)->searchPosts(
            [
                'from_company' => ['12345'],
                'count' => 10,
                'cursor' => 'posts_cur_1',
            ],
            ['account_id' => 'acc_li'],
        );

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return str_contains($request->url(), '/linkedin/search/posts')
                && str_contains($request->url(), 'cursor=posts_cur_1')
                && str_contains($request->url(), 'limit=10')
                && ($payload['posted_by']['company'] ?? null) === ['12345']
                && ! array_key_exists('from_company', $payload)
                && ! array_key_exists('count', $payload)
                && ! array_key_exists('cursor', $payload)
                && ! array_key_exists('limit', $payload);
        });
    }

    public function test_relation_list_uses_type_query_and_cancel_is_post(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/users/me/relation-requests*' => Http::response(['data' => []], 200),
            'unipile.test/v2/acc_li/users/me/relation-requests/req_1/cancel' => Http::response([
                'object' => 'RelationRequestCanceled',
            ], 200),
            'unipile.test/v2/acc_li/users/me/relation-requests/req_2/accept' => Http::response([
                'object' => 'RelationRequestAccepted',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->listSentInvitations(['account_id' => 'acc_li']);
        $provider->cancelInvitation('req_1', ['account_id' => 'acc_li']);
        $provider->handleReceivedInvitation('req_2', 'accept', ['account_id' => 'acc_li']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/users/me/relation-requests')
                && str_contains($request->url(), 'type=sent');
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://unipile.test/v2/acc_li/users/me/relation-requests/req_1/cancel';
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://unipile.test/v2/acc_li/users/me/relation-requests/req_2/accept';
        });
    }

    public function test_profile_and_relations_use_v2_shapes(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/users/jane*' => Http::response(['id' => 'ACoJane'], 200),
            'unipile.test/v2/acc_li/users/me/relations' => Http::response(['data' => []], 200),
            'unipile.test/v2/acc_li/linkedin/member/ACoJane/endorse-skill' => Http::response([
                'object' => 'SkillEndorsed',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->getProfileByIdentifier('jane', [
            'account_id' => 'acc_li',
            'linkedin_sections' => ['skills'],
        ]);
        $provider->listRelations(['account_id' => 'acc_li']);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://unipile.test/v2/acc_li/users/jane')
                && str_contains($request->url(), 'with_sections')
                && ! str_contains($request->url(), 'linkedin_sections');
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://unipile.test/v2/acc_li/users/me/relations';
        });
    }

    public function test_create_post_maps_v1_aliases_to_v2_fields(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/posts' => Http::response(['id' => 'post_1'], 201),
        ]);

        app(UnipileProvider::class)->createPost('acc_li', 'Hello', [
            'repost' => 'quoted_abc',
            'as_organization' => 'org_1',
            'external_link' => 'https://example.com',
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return ($payload['quoted_post_id'] ?? null) === 'quoted_abc'
                && ($payload['post_as'] ?? null) === 'org_1'
                && ! array_key_exists('repost', $payload)
                && ! array_key_exists('as_organization', $payload)
                && ! array_key_exists('external_link', $payload);
        });
    }

    public function test_bare_list_invitations_defaults_required_type(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/users/me/relation-requests*' => Http::response(['data' => []], 200),
        ]);

        app(UnipileProvider::class)->listInvitations(['account_id' => 'acc_li']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/users/me/relation-requests')
                && str_contains($request->url(), 'type=received');
        });
    }

    public function test_start_chat_maps_legacy_linkedin_into_specifics(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/inboxes/CLASSIC_PRIMARY/chat' => Http::response(['id' => 'chat_1'], 201),
        ]);
        Config::set('services.unipile.endpoints.start_chat', '/chats/send');

        app(UnipileProvider::class)->startChat([
            'account_id' => 'acc_li',
            'users_ids' => ['ACoAAA'],
            'text' => 'Hello',
            'linkedin' => ['inmail' => true],
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://unipile.test/v2/acc_li/inboxes/CLASSIC_PRIMARY/chat'
                && ($payload['users_ids'] ?? null) === 'ACoAAA'
                && ($payload['specifics']['linkedin']['classic']['inmail'] ?? null) === true
                && ! array_key_exists('linkedin', $payload);
        });
    }

    public function test_start_chat_maps_options_linkedin_and_invitation_id(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/inboxes/CLASSIC_PRIMARY/chat' => Http::response(['id' => 'chat_2'], 201),
        ]);
        Config::set('services.unipile.endpoints.start_chat', '/chats/send');

        app(UnipileProvider::class)->startChat([
            'account_id' => 'acc_li',
            'users_ids' => ['ACoBBB'],
            'text' => 'Hi',
            'options' => [
                'linkedin' => [
                    'invitation_id' => 'req_legacy_1',
                    'inmail' => true,
                ],
            ],
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $classic = $payload['specifics']['linkedin']['classic'] ?? [];

            return $request->url() === 'https://unipile.test/v2/acc_li/inboxes/CLASSIC_PRIMARY/chat'
                && ($payload['users_ids'] ?? null) === 'ACoBBB'
                && ($classic['relation_request_id'] ?? null) === 'req_legacy_1'
                && ($classic['inmail'] ?? null) === true
                && ! array_key_exists('invitation_id', $classic)
                && ! array_key_exists('options', $payload)
                && ! array_key_exists('linkedin', $payload);
        });
    }

    public function test_start_chat_keeps_generic_path_for_whatsapp(): void
    {
        Http::fake([
            'unipile.test/v2/acc_wa/chats/send' => Http::response(['id' => 'chat_wa'], 201),
        ]);
        Config::set('services.unipile.endpoints.start_chat', '/chats/send');

        app(UnipileProvider::class)->startChat([
            'account_id' => 'acc_wa',
            'users_ids' => ['15551234567@s.whatsapp.net'],
            'text' => 'Hello',
            'provider' => 'WHATSAPP',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://unipile.test/v2/acc_wa/chats/send'
                && ($request->data()['users_ids'] ?? null) === '15551234567@s.whatsapp.net';
        });
    }

    public function test_sales_nav_search_from_url_puts_limit_offset_in_query(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/linkedin/sales-navigator/search*' => Http::response([
                'data' => [
                    ['id' => 'sn1', 'display_name' => 'SN One'],
                ],
            ], 200),
        ]);

        app(UnipileProvider::class)->searchFromUrl(
            'https://www.linkedin.com/sales/search/people?query=(recentSearchParam:(id:1))',
            'acc_li',
            5,
        );

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $url = $request->url();

            return str_contains($url, '/linkedin/sales-navigator/search')
                && ! str_contains($url, '/linkedin/search?')
                && ! str_ends_with(parse_url($url, PHP_URL_PATH) ?? '', '/linkedin/search')
                && str_contains($url, 'limit=')
                && str_contains($url, 'offset=0')
                && ($payload['url'] ?? null) !== null
                && ! array_key_exists('limit', $payload)
                && ! array_key_exists('offset', $payload)
                && ! array_key_exists('count', $payload);
        });
    }

    public function test_recruiter_search_from_url_uses_recruiter_path(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/linkedin/recruiter/search*' => Http::response([
                'data' => [
                    ['id' => 'r1', 'display_name' => 'Rec One'],
                ],
            ], 200),
        ]);

        app(UnipileProvider::class)->searchFromUrl(
            'https://www.linkedin.com/talent/search?searchHistoryId=1',
            'acc_li',
            5,
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/linkedin/recruiter/search')
                && str_contains($request->url(), 'offset=0')
                && ($request->data()['url'] ?? null) !== null;
        });
    }

    public function test_list_chats_strips_owner_and_legacy_unread(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/chats*' => Http::response(['items' => []], 200),
        ]);
        Config::set('services.unipile.endpoints.list_chats', '/chats');

        app(UnipileProvider::class)->listChats(
            ['unread' => true, 'limit' => 10, 'owner_id' => 99, 'count' => 50],
            ['account_id' => 'acc_li', 'organization_id' => 7],
        );

        Http::assertSent(function ($request) {
            $query = $request->data();

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://unipile.test/v2/acc_li/chats')
                && ($query['is_unread'] ?? null) === true
                && ($query['limit'] ?? null) == 10
                && ! array_key_exists('unread', $query)
                && ! array_key_exists('owner_id', $query)
                && ! array_key_exists('organization_id', $query)
                && ! array_key_exists('count', $query)
                && ! array_key_exists('account_id', $query);
        });
    }

    public function test_list_posts_uses_users_me_posts_path(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/users/me/posts*' => Http::response(['items' => []], 200),
        ]);

        app(UnipileProvider::class)->listPosts(['account_id' => 'acc_li', 'limit' => 10]);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://unipile.test/v2/acc_li/users/me/posts');
        });
    }

    public function test_classic_search_parameters_reads_v2_data_envelope(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/linkedin/search/parameters*' => Http::response([
                'data' => [
                    ['object' => 'SearchParameter', 'id' => '102571732', 'name' => 'San Francisco Bay Area'],
                ],
            ], 200),
        ]);

        $rows = app(UnipileProvider::class)->listClassicSearchParameters(
            'acc_li',
            'CONNECTIONS',
            'San Francisco',
            5,
        );

        $this->assertSame([
            ['id' => '102571732', 'label' => 'San Francisco Bay Area'],
        ], $rows);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/linkedin/search/parameters')
                && str_contains($request->url(), 'type=RELATION')
                && str_contains($request->url(), 'keywords=San%20Francisco');
        });
    }

    public function test_follow_and_unfollow_use_v2_user_routes(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/users/me/follow/ACoFollow' => Http::response([
                'object' => 'UserFollowed',
            ], 200),
            'unipile.test/v2/acc_li/users/me/unfollow/ACoFollow' => Http::response([
                'object' => 'UserUnfollowed',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->performLinkedinProfileAction('follow', [
            'account_id' => 'acc_li',
            'provider_id' => 'ACoFollow',
        ]);
        $provider->performLinkedinProfileAction('unfollow', [
            'account_id' => 'acc_li',
            'provider_id' => 'ACoFollow',
        ]);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://unipile.test/v2/acc_li/users/me/follow/ACoFollow';
        });
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://unipile.test/v2/acc_li/users/me/unfollow/ACoFollow';
        });
    }

    public function test_profile_contact_extractors_read_v2_root_fields(): void
    {
        $provider = app(UnipileProvider::class);

        $this->assertSame('lead@example.com', $provider->extractProfileEmail([
            'emails' => [['email' => 'lead@example.com']],
        ]));
        $this->assertSame('15551234567', $provider->extractProfilePhone([
            'phone_numbers' => [['number' => '+1 (555) 123-4567']],
        ]));
    }

    public function test_calendar_event_body_maps_v1_aliases(): void
    {
        Http::fake([
            'unipile.test/v2/acc_cal/calendars/cal_1/events' => Http::response(['id' => 'evt_1'], 201),
        ]);
        Config::set('services.unipile.endpoints.calendar_events', '/calendars/%s/events');

        app(UnipileProvider::class)->createCalendarEvent('acc_cal', 'cal_1', [
            'name' => 'Demo',
            'notify' => true,
            'start' => [
                'date_time' => '2026-05-28T10:00:00',
                'time_zone' => 'Europe/Paris',
            ],
            'end' => [
                'date_time' => '2026-05-28T11:00:00',
                'time_zone' => 'Europe/Paris',
            ],
            'attendees' => [['identifier' => 'lead@example.com', 'name' => 'Lead']],
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return ($payload['title'] ?? null) === 'Demo'
                && ! array_key_exists('name', $payload)
                && ($payload['notify'] ?? null) === 'all'
                && ($payload['start']['timezone'] ?? null) === 'Europe/Paris'
                && ! isset($payload['start']['time_zone'])
                && ($payload['attendees'][0]['email'] ?? null) === 'lead@example.com'
                && ($payload['attendees'][0]['display_name'] ?? null) === 'Lead';
        });
    }
}
