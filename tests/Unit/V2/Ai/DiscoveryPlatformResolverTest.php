<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\DiscoveryPlatformResolver;
use Tests\TestCase;

class DiscoveryPlatformResolverTest extends TestCase
{
    public function test_find_only_instagram_request_uses_instagram_not_linkedin(): void
    {
        $resolver = app(DiscoveryPlatformResolver::class);

        $plan = [
            'required_outcome' => 'find_only',
            'constraints' => ['preferred_channel' => 'instagram'],
            'objective' => [
                'criteria' => 'help me get 50 leads about my business on instgram',
                'segment' => 'my business',
            ],
        ];

        $this->assertSame('instagram', $resolver->resolve($plan));
    }

    public function test_find_only_vague_request_uses_auto_not_a_default_channel(): void
    {
        $resolver = app(DiscoveryPlatformResolver::class);

        $plan = [
            'required_outcome' => 'find_only',
            'constraints' => [],
            'objective' => [
                'criteria' => 'help me get 50 leads about my business',
                'segment' => 'my business',
            ],
        ];

        $this->assertSame('auto', $resolver->resolve($plan));
    }

    public function test_explicit_linkedin_in_message_uses_linkedin(): void
    {
        $resolver = app(DiscoveryPlatformResolver::class);

        $plan = [
            'required_outcome' => 'find_only',
            'constraints' => [],
            'objective' => ['criteria' => 'find 20 SaaS founders on LinkedIn'],
        ];

        $this->assertSame('linkedin', $resolver->resolve($plan));
    }

    public function test_multichannel_phrase_uses_auto(): void
    {
        $resolver = app(DiscoveryPlatformResolver::class);

        $plan = [
            'required_outcome' => 'find_only',
            'constraints' => [],
            'objective' => ['criteria' => 'search all channels for coffee brands'],
        ];

        $this->assertSame('auto', $resolver->resolve($plan));
    }

    public function test_explicit_argument_overrides_plan(): void
    {
        $resolver = app(DiscoveryPlatformResolver::class);

        $plan = [
            'required_outcome' => 'find_only',
            'constraints' => ['preferred_channel' => 'linkedin'],
            'objective' => ['criteria' => 'find instagram leads'],
        ];

        $this->assertSame('instagram', $resolver->resolve($plan, ['platform' => 'instagram']));
    }
}
