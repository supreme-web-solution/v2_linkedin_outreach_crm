<?php

namespace App\V2\Services;

use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIContentService
{
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * @return array{content:string,hashtags:string,image?:array{url:string,path:string},image_error?:string}
     */
    public function generateLinkedInPost(
        string $topic,
        string $style = 'professional',
        string $length = 'medium',
        bool $withImage = false,
        ?int $userId = null,
    ): array {
        $wordGuide = match ($length) {
            'short' => '80-130 words',
            'long' => '230-320 words',
            default => '140-220 words',
        };

        $prompt = <<<PROMPT
Write a high-quality LinkedIn post.
Topic: {$topic}
Style: {$style}
Length: {$wordGuide}

Rules:
- Start with a strong hook in the first line.
- Give practical value, examples, or clear insights (not placeholders).
- Keep it natural and human, not robotic.
- End with a CTA question.
- Add 3-6 relevant hashtags at the end.
- Plain text only: no markdown, no ** bold **, no bullet markdown syntax.
- Return only the final post text.
PROMPT;

        $text = $this->sanitizeLinkedInText($this->chatCompletion($prompt, 900, 0.8));
        [$content, $hashtags] = $this->splitContentAndHashtags($text);

        $result = [
            'content' => $content,
            'hashtags' => $hashtags,
        ];

        if ($withImage && $userId !== null) {
            try {
                $result['image'] = $this->generateImage(
                    $this->buildPostImagePrompt($topic, $content),
                    $userId,
                );
            } catch (\Throwable $e) {
                Log::warning('[OpenAIContentService] Post image generation failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                $result['image_error'] = 'Text was generated, but the image could not be created.';
            }
        }

        return $result;
    }

    /**
     * Improve existing post with a specific action.
     */
    public function improvePost(string $content, string $action): string
    {
        $actionPrompt = match ($action) {
            'add_hook' => 'Add a stronger opening hook to the beginning.',
            'add_cta' => 'Add a strong call to action at the end.',
            'expand' => 'Expand with more depth, examples, and detail.',
            'make_viral' => 'Rewrite to maximize engagement and shareability.',
            'add_data' => 'Add relevant metrics/statistics naturally.',
            'bullet_points' => 'Restructure key points into readable bullets.',
            'add_story' => 'Add a short relatable story or anecdote.',
            'controversial' => 'Add a thought-provoking, debate-worthy angle.',
            'add_emoji' => 'Add tasteful emojis for readability.',
            'make_concise' => 'Make it more concise and punchy.',
            'repurpose' => 'Repurpose into a fresh alternative version.',
            default => 'Improve clarity, impact, and readability.',
        };

        $prompt = <<<PROMPT
You are a LinkedIn content strategist.
Task: {$actionPrompt}

Original post:
{$content}

Return only the improved final post text.
Use plain text only — no markdown formatting.
PROMPT;

        return $this->sanitizeLinkedInText($this->chatCompletion($prompt, 900, 0.7));
    }

    /**
     * Rewrite in requested tone and optional mode.
     */
    public function rewritePost(string $content, string $tone = 'professional', ?string $mode = null): string
    {
        $modeText = match ($mode) {
            'shorten' => 'Make it shorter and more direct.',
            'expand' => 'Expand with richer detail and depth.',
            default => 'Keep similar length.',
        };

        $prompt = <<<PROMPT
Rewrite this LinkedIn post.
Tone: {$tone}
{$modeText}

Original post:
{$content}

Return only the rewritten post.
Use plain text only — no markdown formatting.
PROMPT;

        return $this->sanitizeLinkedInText($this->chatCompletion($prompt, 900, 0.75));
    }

    /**
     * Generate an AI image for a post and store locally.
     * @return array{url:string,path:string}
     */
    public function generateImage(string $prompt, int $userId): array
    {
        $response = Http::withToken($this->apiKey())
            ->timeout(120)
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => (string) config('services.openai.image_model', 'gpt-image-2'),
                'prompt' => $prompt,
                'size' => '1024x1024',
                'quality' => 'medium',
            ]);

        if (!$response->ok()) {
            Log::warning('[OpenAIContentService] Image generation failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 400),
            ]);
            throw new \RuntimeException(OpenAiUserError::fromHttp($response->status(), $response->body()));
        }

        $b64 = Arr::get($response->json(), 'data.0.b64_json');
        if (!$b64) {
            throw new \RuntimeException('AI image generation returned empty image data.');
        }

        $binary = base64_decode($b64, true);
        if ($binary === false) {
            throw new \RuntimeException('Failed to decode generated image.');
        }

        $relativePath = 'content-ai-images/u'.$userId.'_'.time().'_'.bin2hex(random_bytes(4)).'.png';
        $cloudinary = app(CloudinaryMediaService::class);
        if (! $cloudinary->isConfigured()) {
            throw new \RuntimeException('Cloudinary is not configured. Set CLOUDINARY_URL in .env.');
        }

        $upload = $cloudinary->uploadBinary($binary, 'content-ai-images/u'.$userId, 'ai-post.png');

        return [
            'url' => $upload['secure_url'] ?: $upload['url'],
            'path' => $upload['public_id'],
            'cloudinary_public_id' => $upload['public_id'],
            'resource_type' => $upload['resource_type'],
        ];
    }

    private function apiKey(): string
    {
        return (string) config('services.openai.key', '');
    }

    private function chatCompletion(string $prompt, int $maxTokens = 700, float $temperature = 0.8): string
    {
        $response = Http::withToken($this->apiKey())
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an expert LinkedIn copywriter.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
            ]);

        if (!$response->ok()) {
            Log::warning('[OpenAIContentService] Chat completion failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 400),
            ]);
            throw new \RuntimeException(OpenAiUserError::fromHttp($response->status(), $response->body()));
        }

        return trim((string) Arr::get($response->json(), 'choices.0.message.content', ''));
    }

    /**
     * Generate a LinkedIn comment reply for a feed post.
     */
    public function generateLinkedInComment(string $postContent, string $tone = 'professional'): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(OpenAiUserError::NOT_CONFIGURED);
        }

        $prompt = <<<PROMPT
Generate a thoughtful, engaging LinkedIn comment for the following post.
Tone: {$tone}
Rules:
- Add value to the conversation; be specific to the post content.
- 2-3 sentences maximum.
- No hashtags unless they fit naturally.
- Do not wrap in quotes.
- Return only the comment text.

Post:
{$postContent}
PROMPT;

        return $this->chatCompletion($prompt, 220, 0.75);
    }

    /**
     * Generate or paraphrase outreach sequence copy (email, LinkedIn, WhatsApp, etc.).
     */
    public function generateOutreachContent(
        string $mode,
        string $channel,
        string $action,
        string $field,
        string $context = '',
        string $currentText = '',
        string $emailSubject = '',
    ): string {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(OpenAiUserError::NOT_CONFIGURED);
        }

        $channelLabel = ucfirst(str_replace('_', ' ', $channel));
        $placeholders = 'You may use {{firstName}}, {{lastName}}, {{company}}, {{position}} as merge tags where natural.';

        if ($mode === 'paraphrase') {
            $prompt = <<<PROMPT
Paraphrase this {$channelLabel} outreach {$field} to be clearer, warmer, and more professional. Keep similar intent and length. {$placeholders}
Do not wrap in quotes. Return only the rewritten text.

Current text:
{$currentText}
PROMPT;

            return $this->chatCompletion($prompt, 400, 0.65);
        }

        $fieldGuide = match ($field) {
            'subject' => 'Write a short email subject line (max 60 chars).',
            'body' => 'Write the email body.',
            default => 'Write the message text.',
        };

        $actionGuide = match ($action) {
            'send_invite' => 'LinkedIn connection invite note (under 300 characters).',
            'send_email' => 'Cold or follow-up email.',
            default => 'Direct message.',
        };

        $prompt = <<<PROMPT
Write outreach copy for a {$channelLabel} campaign step.
Step type: {$actionGuide}
Output: {$fieldGuide}
{$placeholders}

User context / goal:
{$context}

PROMPT;

        if ($field === 'body' && $emailSubject !== '') {
            $prompt .= "\nEmail subject for context: {$emailSubject}\n";
        }

        if ($currentText !== '') {
            $prompt .= "\nOptional draft to improve:\n{$currentText}\n";
        }

        $prompt .= "\nReturn only the final {$field} text, no labels or quotes.";

        $maxTokens = $field === 'subject' ? 80 : 350;

        return $this->chatCompletion($prompt, $maxTokens, 0.72);
    }

    /**
     * @param  array<int, array{role: string, body: string, source?: string|null}>  $thread
     */
    public function summarizeInboxThread(
        string $channel,
        array $thread,
        string $leadName,
        ?string $existingSummary = null,
    ): string {
        if ($thread === [] || ! $this->isConfigured()) {
            return $existingSummary ?? '';
        }

        $channelLabel = OutreachChannelRegistry::channelLabel($channel);
        $lines = [];
        foreach ($thread as $index => $message) {
            $speaker = ($message['role'] ?? '') === 'assistant' ? 'You' : $leadName;
            $body = mb_substr(trim((string) ($message['body'] ?? '')), 0, 400);
            if ($body === '') {
                continue;
            }
            $lines[] = ($index + 1).". {$speaker}: {$body}";
        }

        if ($lines === []) {
            return $existingSummary ?? '';
        }

        $transcript = implode("\n", $lines);
        $prior = trim((string) ($existingSummary ?? ''));

        $prompt = $prior !== ''
            ? "Update this conversation summary with the older messages below.\n\nCurrent summary:\n{$prior}\n\nOlder messages to fold in:\n{$transcript}"
            : "Summarize this {$channelLabel} sales conversation for an AI that will reply next.\n\nCapture: who said what matters, lead intent/objections, offers made, and open questions.\nKeep it under 120 words, plain text, no bullet labels.\n\nMessages:\n{$transcript}";

        return $this->chatCompletion($prompt, 220, 0.3);
    }

    /**
     * @param  array<int, array{role: string, body: string, source?: string|null}>  $thread
     * @param  array{campaign_name?: string, lead_headline?: string|null, thread_summary?: string}  $options
     */
    public function generateInboxReply(
        string $channel,
        string $aiContext,
        array $thread,
        string $inboundBody,
        string $leadName,
        array $options = [],
    ): string {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(OpenAiUserError::NOT_CONFIGURED);
        }

        $channelLabel = OutreachChannelRegistry::channelLabel($channel);
        $campaignName = trim((string) ($options['campaign_name'] ?? ''));
        $leadHeadline = trim((string) ($options['lead_headline'] ?? ''));
        $threadSummary = trim((string) ($options['thread_summary'] ?? ''));

        $systemLines = [
            "You are replying on behalf of a sales team in an active {$channelLabel} conversation.",
            '',
            'Business / campaign brief (use as background, not a script to repeat verbatim):',
            $aiContext !== '' ? $aiContext : 'General professional outreach.',
            '',
            "Lead name: {$leadName}",
        ];

        if ($leadHeadline !== '') {
            $systemLines[] = "Lead headline / role: {$leadHeadline}";
        }

        if ($campaignName !== '') {
            $systemLines[] = "Outreach campaign: {$campaignName}";
        }

        if ($threadSummary !== '') {
            $systemLines[] = '';
            $systemLines[] = 'Earlier conversation summary (before the last few messages):';
            $systemLines[] = $threadSummary;
        }

        $systemLines[] = '';
        $systemLines[] = 'How to reply:';
        $systemLines[] = '- Use the summary for background, then focus on the last few messages below.';
        $systemLines[] = '- Respond to what the lead actually said and what was already discussed.';
        $systemLines[] = '- Do not repeat the same pitch or question if it was already sent unless the lead asks again.';
        $systemLines[] = '- Match the channel tone (WhatsApp, Instagram, Telegram, X = casual; LinkedIn and email = slightly formal).';
        $systemLines[] = '- Write ONE natural reply (1-4 sentences) with a sensible next step when appropriate.';
        $systemLines[] = '- Never mention that you are AI. Return only the reply text.';

        $chatMessages = [
            ['role' => 'system', 'content' => implode("\n", $systemLines)],
        ];

        foreach ($thread as $message) {
            $body = trim((string) ($message['body'] ?? ''));
            if ($body === '') {
                continue;
            }

            $role = ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $source = (string) ($message['source'] ?? '');

            if ($role === 'assistant' && $source === 'outreach_campaign') {
                $body = "[Automated campaign step]\n{$body}";
            }

            $chatMessages[] = ['role' => $role, 'content' => $body];
        }

        $inboundBody = trim($inboundBody);
        $last = $thread !== [] ? $thread[array_key_last($thread)] : null;
        $lastBody = trim((string) ($last['body'] ?? ''));
        $lastIsSameInbound = $last !== null
            && ($last['role'] ?? '') !== 'assistant'
            && $lastBody === $inboundBody;

        if ($inboundBody !== '' && ! $lastIsSameInbound) {
            $chatMessages[] = ['role' => 'user', 'content' => $inboundBody];
        }

        $response = Http::withToken($this->apiKey())
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => $chatMessages,
                'max_tokens' => 320,
                'temperature' => 0.55,
            ]);

        if (! $response->ok()) {
            Log::warning('[OpenAIContentService] Inbox reply failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 400),
            ]);
            throw new \RuntimeException(OpenAiUserError::fromHttp($response->status(), $response->body()));
        }

        return trim((string) Arr::get($response->json(), 'choices.0.message.content', ''));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitContentAndHashtags(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['', ''];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $hashLines = [];
        $bodyLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && preg_match('/^#\w+/u', $trimmed) && ! preg_match('/[^\s#]/u', preg_replace('/#\w+/u', '', $trimmed))) {
                $hashLines[] = $trimmed;
            } else {
                $bodyLines[] = $line;
            }
        }

        $body = trim(implode("\n", $bodyLines));
        $hashtags = trim(implode(' ', $hashLines));

        if ($hashtags === '' && preg_match('/(?:\s|^)((?:#\w+\s*){2,})\s*$/u', $body, $matches)) {
            $hashtags = trim($matches[1]);
            $body = trim(substr($body, 0, -strlen($matches[0])));
        }

        return [$body, $hashtags];
    }

    private function buildPostImagePrompt(string $topic, string $content): string
    {
        $snippet = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?: $topic), 0, 280);

        return "Professional LinkedIn post illustration. Topic: {$topic}. Visual concept inspired by: {$snippet}. Clean, modern, photorealistic or polished editorial style, no text overlay, suitable for social media feed.";
    }

    private function sanitizeLinkedInText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/s', '$1', $text) ?? $text;
        $text = str_replace('**', '', $text);
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;

        // Unpack jammed paragraphs when the model returns a single block
        if (substr_count($text, "\n") < 2 && mb_strlen($text) > 160) {
            $text = preg_replace('/([.!?])\s+(?=[A-Z“"\'🚀💡✅🔥])/u', "$1\n\n", $text) ?? $text;
        }

        // Break before emoji-led lines when jammed
        $text = preg_replace('/([^\n])\s*([\x{1F300}-\x{1FAFF}])/u', "$1\n\n$2", $text) ?? $text;

        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Build an image prompt from post body (AI-generated or user-pasted).
     */
    public function imagePromptFromPostContent(string $content, ?string $topic = null): string
    {
        $topic = trim((string) $topic);
        if ($topic === '') {
            $topic = 'LinkedIn post';
        }

        return $this->buildPostImagePrompt($topic, $content);
    }
}

