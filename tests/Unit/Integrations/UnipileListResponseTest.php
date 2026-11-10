<?php

namespace Tests\Unit\Integrations;

use App\V2\Integrations\Unipile\UnipileProvider;
use Tests\TestCase;

class UnipileListResponseTest extends TestCase
{
    public function test_extracts_v2_data_array_envelope(): void
    {
        $items = UnipileProvider::extractListItems([
            'object' => 'UserRelationsList',
            'data' => [
                ['user' => ['id' => 'ACoOne', 'public_identifier' => 'one']],
                ['user' => ['id' => 'ACoTwo', 'public_identifier' => 'two']],
            ],
            'next_cursor' => 'cursor_abc',
        ]);

        $this->assertCount(2, $items);
        $this->assertSame('ACoOne', $items[0]['user']['id']);
        $this->assertSame('cursor_abc', UnipileProvider::extractNextCursor([
            'data' => $items,
            'next_cursor' => 'cursor_abc',
        ]));
    }

    public function test_extracts_items_and_nested_data_items(): void
    {
        $this->assertSame(
            [['id' => 'a']],
            UnipileProvider::extractListItems(['items' => [['id' => 'a']]])
        );
        $this->assertSame(
            [['id' => 'b']],
            UnipileProvider::extractListItems(['data' => ['items' => [['id' => 'b']]]])
        );
    }

    public function test_ignores_associative_data_objects(): void
    {
        $this->assertSame([], UnipileProvider::extractListItems([
            'data' => ['id' => 'not_a_list', 'name' => 'x'],
        ]));
    }
}
