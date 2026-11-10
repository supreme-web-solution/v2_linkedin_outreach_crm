<?php

namespace Tests\Unit\Services;

use App\V2\Services\ConversationSyncService;
use ReflectionMethod;
use Tests\TestCase;

class ConversationSyncV2ChatShapeTest extends TestCase
{
    public function test_extracts_v2_chat_user_id_and_display_name(): void
    {
        $service = app(ConversationSyncService::class);

        $ids = $this->invokePrivate($service, 'extractAttendeeIds', [[
            'id' => 'chat_1',
            'user_id' => 'ACoAAProspect123',
            'user' => [
                'id' => 'ACoAAProspect123',
                'display_name' => 'Jane Prospect',
                'public_identifier' => 'jane-prospect',
            ],
            'is_1to1' => true,
        ]]);

        $this->assertContains('ACoAAProspect123', $ids);
        $this->assertContains('jane-prospect', $ids);

        $name = $this->invokePrivate($service, 'extractProspectName', [[
            'user' => ['display_name' => 'Jane Prospect'],
        ]]);

        $this->assertSame('Jane Prospect', $name);
    }

    public function test_extracts_v2_group_participant_user_ids(): void
    {
        $service = app(ConversationSyncService::class);

        $ids = $this->invokePrivate($service, 'extractAttendeeIds', [[
            'id' => 'group_1',
            'is_group' => true,
            'participants' => [
                ['user' => ['id' => 'u1', 'display_name' => 'One']],
                ['user' => ['id' => 'u2', 'display_name' => 'Two']],
            ],
        ]]);

        $this->assertSame(['u1', 'u2'], $ids);
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function invokePrivate(object $service, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($service, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($service, $args);
    }
}
