<?php

namespace Tests\Unit\V2\Ai\Support;

use App\V2\Ai\Support\InboundMessagePresenter;
use Tests\TestCase;

class InboundMessagePresenterTest extends TestCase
{
    public function test_preview_preserves_full_url_on_separate_line(): void
    {
        $body = 'did you got my last message, i sadi check my site our and get me tailor stuff https://engr.phanrise.com/';

        $preview = InboundMessagePresenter::previewWithUrls($body, 80);

        $this->assertStringContainsString('URL: https://engr.phanrise.com/', $preview);
        $this->assertStringNotContainsString('phanris...', $preview);
        $this->assertSame(['https://engr.phanrise.com/'], InboundMessagePresenter::extractUrls($body));
    }
}
