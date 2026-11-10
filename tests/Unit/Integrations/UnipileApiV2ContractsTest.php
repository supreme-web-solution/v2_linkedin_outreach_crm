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
            'unipile.test/v2/acc_li/linkedin/search/people' => Http::response(['items' => []], 200),
        ]);

        app(UnipileProvider::class)->searchPeople(
            ['keywords' => 'founder', 'limit' => 5],
            ['account_id' => 'acc_li'],
        );

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://unipile.test/v2/acc_li/linkedin/search/people'
                && ($payload['keywords'] ?? null) === 'founder'
                && array_key_exists('offset', $payload)
                && ! array_key_exists('api', $payload)
                && ! array_key_exists('category', $payload)
                && ! array_key_exists('cursor', $payload);
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
            'unipile.test/v2/acc_li/chats/send' => Http::response(['id' => 'chat_1'], 201),
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

            return ($payload['users_ids'] ?? null) === 'ACoAAA'
                && ($payload['specifics']['linkedin']['classic']['inmail'] ?? null) === true
                && ! array_key_exists('linkedin', $payload);
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
