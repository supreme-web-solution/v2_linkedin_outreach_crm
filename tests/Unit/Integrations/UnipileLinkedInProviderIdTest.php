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

        Config::set('services.unipile.base_url', 'https://unipile.test/api/v1');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);
    }

    public function test_send_invitation_resolves_linkedin_vanity_slug_before_posting(): void
    {
        Http::fake([
            'unipile.test/api/v1/users/eleazarnzerem*' => Http::response([
                'provider_id' => 'eleazarnzerem',
                'public_identifier' => 'eleazarnzerem',
                'member_urn' => 'urn:li:member:ACoAAResolved123',
            ], 200),
            'unipile.test/api/v1/users/invite' => Http::response(['id' => 'inv_1'], 201),
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
            if ($request->url() !== 'https://unipile.test/api/v1/users/invite') {
                return false;
            }

            $payload = $request->data();

            return ($payload['provider_id'] ?? '') === 'ACoAAResolved123'
                && ($payload['account_id'] ?? '') === 'acc_linkedin';
        });
    }

    public function test_resolve_provider_id_extracts_member_id_from_member_urn(): void
    {
        Http::fake([
            'unipile.test/api/v1/users/jane-doe*' => Http::response([
                'provider_id' => 'jane-doe',
                'public_identifier' => 'jane-doe',
                'member_urn' => 'urn:li:fsd_profile:ACoAAJane456',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $resolved = $provider->resolveProviderId('jane-doe', ['account_id' => 'acc_linkedin']);

        $this->assertSame('ACoAAJane456', $resolved['provider_id']);
    }

    public function test_start_chat_leaves_whatsapp_phone_numbers_untouched(): void
    {
        Http::fake([
            'unipile.test/api/v1/chats*' => Http::response(['id' => 'chat_1'], 201),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->startChat([
            'account_id' => 'acc_whatsapp',
            'attendee_ids' => ['+447700900123'],
            'text' => 'Hello',
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/chats')) {
                return false;
            }

            $payload = $request->data();

            return ($payload['attendees_ids'][0] ?? '') === '+447700900123';
        });
    }
}
