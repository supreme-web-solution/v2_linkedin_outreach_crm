<?php

namespace App\Jobs\V2;

use App\Models\V2ContentPost;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishV2ContentPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $postId)
    {
        $this->onQueue('default');
    }

    public function handle(UnipileProvider $unipile): void
    {
        $post = V2ContentPost::find($this->postId);
        if (!$post || $post->status === 'published') {
            return;
        }

        // Find the active LinkedIn integration account for this org/user
        $account = V2IntegrationAccount::query()
            ->where('user_id', $post->user_id)
            ->where('provider', 'linkedin')
            ->where('status', 'active')
            ->first();

        if (!$account) {
            Log::warning('[PublishPost] No active LinkedIn account', ['post_id' => $this->postId, 'user_id' => $post->user_id]);
            $post->update(['status' => 'failed', 'meta' => array_merge((array) $post->meta, [
                'publish_error' => 'No active LinkedIn account connected.',
            ])]);
            return;
        }

        $unipileAccountId = $account->meta['unipile_account_id'] ?? $account->provider_account_id;

        try {
            $result = $unipile->createPost($unipileAccountId, $post->content);
            $linkedinPostId = $result['id'] ?? $result['post_id'] ?? null;

            $post->update([
                'status'       => 'published',
                'published_at' => now(),
                'meta'         => array_merge((array) $post->meta, [
                    'linkedin_post_id' => $linkedinPostId,
                    'published_via'    => 'unipile',
                    'published_at'     => now()->toIso8601String(),
                ]),
            ]);

            Log::info('[PublishPost] Published successfully', [
                'post_id'          => $this->postId,
                'linkedin_post_id' => $linkedinPostId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[PublishPost] Failed to publish', [
                'post_id' => $this->postId,
                'error'   => $e->getMessage(),
            ]);

            $post->update([
                'status' => 'failed',
                'meta'   => array_merge((array) $post->meta, [
                    'publish_error' => $e->getMessage(),
                ]),
            ]);

            throw $e; // allow retry
        }
    }
}
