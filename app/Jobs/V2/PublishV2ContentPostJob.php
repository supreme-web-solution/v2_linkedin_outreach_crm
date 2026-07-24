<?php

namespace App\Jobs\V2;

use App\Models\V2ContentPost;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Services\CloudinaryMediaService;
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

    public function handle(UnipileProvider $unipile, CloudinaryMediaService $cloudinary): void
    {
        $post = V2ContentPost::find($this->postId);
        if (! $post || $post->status === 'published') {
            return;
        }

        $account = V2IntegrationAccount::query()
            ->where('user_id', $post->user_id)
            ->where('provider', 'linkedin')
            ->where('status', 'active')
            ->first();

        if (! $account) {
            Log::warning('[PublishPost] No active LinkedIn account', ['post_id' => $this->postId, 'user_id' => $post->user_id]);
            $post->update(['status' => 'failed', 'meta' => array_merge((array) $post->meta, [
                'publish_error' => 'No active LinkedIn account connected.',
            ])]);

            return;
        }

        $unipileAccountId = $account->meta['unipile_account_id'] ?? $account->provider_account_id;
        $attachments = $this->resolveAttachments($post, $cloudinary);

        try {
            $result = $unipile->createPost($unipileAccountId, $post->content, [
                'attachments' => $attachments,
            ]);
            $linkedinPostId = $result['id'] ?? $result['post_id'] ?? null;

            $post->update([
                'status' => 'published',
                'published_at' => now(),
                'meta' => array_merge((array) $post->meta, [
                    'linkedin_post_id' => $linkedinPostId,
                    'published_via' => 'unipile',
                    'published_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::info('[PublishPost] Published successfully', [
                'post_id' => $this->postId,
                'linkedin_post_id' => $linkedinPostId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[PublishPost] Failed to publish', [
                'post_id' => $this->postId,
                'error' => $e->getMessage(),
            ]);

            $post->update([
                'status' => 'failed',
                'meta' => array_merge((array) $post->meta, [
                    'publish_error' => $e->getMessage(),
                ]),
            ]);

            throw $e;
        } finally {
            foreach ($attachments as $attachment) {
                if (! empty($attachment['_temp']) && is_file($attachment['path'])) {
                    @unlink($attachment['path']);
                }
            }
        }
    }

    /**
     * @return list<array{path: string, filename: string, mime: string, _temp?: bool}>
     */
    private function resolveAttachments(V2ContentPost $post, CloudinaryMediaService $cloudinary): array
    {
        $meta = (array) $post->meta;
        $attachments = [];

        foreach ((array) ($meta['image_urls'] ?? []) as $url) {
            $file = $this->resolveCloudinaryUrl((string) $url, $cloudinary, 'image.jpg');
            if ($file) {
                $attachments[] = $file;
            }
        }

        if ($attachments === [] && ! empty($meta['ai_image_url'])) {
            $file = $this->resolveCloudinaryUrl((string) $meta['ai_image_url'], $cloudinary, 'ai-image.png');
            if ($file) {
                $attachments[] = $file;
            }
        }

        $videoUrl = (string) ($meta['video_url'] ?? '');
        if ($videoUrl !== '') {
            $file = $this->resolveCloudinaryUrl($videoUrl, $cloudinary, 'video.mp4');
            if ($file) {
                return [$file];
            }
        }

        return $attachments;
    }

    /**
     * @return array{path: string, filename: string, mime: string, _temp: bool}|null
     */
    private function resolveCloudinaryUrl(string $url, CloudinaryMediaService $cloudinary, string $fallbackName): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        try {
            $binary = $cloudinary->download($url);
        } catch (\Throwable) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pub_');
        if ($tmp === false) {
            return null;
        }

        file_put_contents($tmp, $binary);

        return [
            'path' => $tmp,
            'filename' => $fallbackName,
            'mime' => mime_content_type($tmp) ?: 'application/octet-stream',
            '_temp' => true,
        ];
    }
}
