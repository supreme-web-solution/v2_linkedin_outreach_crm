<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Support\ApprovalActionLabels;
use PHPUnit\Framework\TestCase;

class BulkDeleteLabelsTest extends TestCase
{
    public function test_bulk_delete_uses_confirm_delete_label(): void
    {
        $labels = ApprovalActionLabels::for('delete_campaign', [
            'type' => 'bulk_delete',
            'kind' => 'bulk',
            'item_count' => 7,
        ]);

        $this->assertSame('Confirm Delete', $labels['approve']);
        $this->assertTrue($labels['show_preview']);
    }

    public function test_resource_bulk_items_label(): void
    {
        $labels = ApprovalActionLabels::for('delete_resource', [
            'type' => 'bulk_delete',
            'items' => [
                ['kind' => 'outreach', 'resource_id' => '18'],
                ['kind' => 'lead_list', 'resource_id' => 'abc', 'list_src' => 'csv'],
            ],
        ]);

        $this->assertSame('Confirm Delete', $labels['approve']);
    }
}
