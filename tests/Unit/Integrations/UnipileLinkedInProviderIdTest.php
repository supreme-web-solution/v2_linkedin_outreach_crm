<?php

namespace Tests\Unit\Integrations;

use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnipileLinkedInProviderIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.unipile.base_url', 'https://unipile.test/v2');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);
    }

    public function test_send_invitation_resolves_linkedin_vanity_slug_before_posting(): void
    {
        Http::fake([
            'unipile.test/v2/acc_linkedin/users/eleazarnzerem*' => Http::response([
                'provider_id' => 'eleazarnzerem',
                'public_identifier' => 'eleazarnzerem',
                'member_urn' => 'urn:li:member:ACoAAResolved123',
            ], 200),
            'unipile.test/v2/acc_linkedin/users/me/relation-requests' => Http::response(['id' => 'inv_1'], 201),
        ]);

        $provider = app(UnipileProvider::class);
        $result = $provider->sendInvitation([
            'account_id' => 'acc_linkedin',
            'recipient_id' => 'eleazarnzerem',
            'message' => 'Hi there',
        ]);

        $this->assertSame('inv_1', $result['id']);

        Http::assertSentCount(2);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://unipile.test/v2/acc_linkedin/users/me/relation-requests') {
                return false;
            }

            $payload = $request->data();

            return ($payload['user_id'] ?? '') === 'ACoAAResolved123'
                && ! array_key_exists('account_id', $payload)
                && ! array_key_exists('provider_id', $payload);
        });
    }

    public function test_resolve_provider_id_extracts_member_id_from_member_urn(): void
    {
        Http::fake([
            'unipile.test/v2/acc_linkedin/users/jane-doe*' => Http::response([
                'provider_id' => 'jane-doe',
                'public_identifier' => 'jane-doe',
                'member_urn' => 'urn:li:fsd_profile:ACoAAJane456',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $resolved = $provider->resolveProviderId('jane-doe', ['account_id' => 'acc_linkedin']);

        $this->assertSame('ACoAAJane456', $resolved['provider_id']);
    }

    public function test_start_chat_uses_users_ids_on_chats_send(): void
    {
        Http::fake([
            'unipile.test/v2/acc_whatsapp/chats/send' => Http::response(['id' => 'chat_1'], 201),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->startChat([
            'account_id' => 'acc_whatsapp',
            'attendee_ids' => ['+447700900123'],
            'text' => 'Hello',
        ]);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://unipile.test/v2/acc_whatsapp/chats/send') {
                return false;
            }

            $payload = $request->data();

            return ($payload['users_ids'] ?? '') === '+447700900123'
                && ! array_key_exists('attendees_ids', $payload)
                && ! array_key_exists('account_id', $payload);
        });
    }

    public function test_start_group_chat_keeps_users_ids_as_array(): void
    {
        Http::fake([
            'unipile.test/v2/acc_whatsapp/chats/send' => Http::response(['id' => 'chat_group'], 201),
        ]);

        app(UnipileProvider::class)->startChat([
            'account_id' => 'acc_whatsapp',
            'users_ids' => ['+447700900123', '+447700900999'],
            'text' => 'Hello group',
            'name' => 'Team',
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return ($payload['users_ids'] ?? null) === ['+447700900123', '+447700900999']
                && ($payload['name'] ?? null) === 'Team';
        });
    }
}
