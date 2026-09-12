<?php

namespace App\V2\Ai\Support;

use App\V2\Services\WebScrapeChainService;
use Illuminate\Support\Str;

/**
 * Build agent-safe previews of inbound text — never truncate URLs mid-string.
 */
final class InboundMessagePresenter
{
    /**
     * @return list<string>
     */
    public static function extractUrls(string $body): array
    {
        return app(WebScrapeChainService::class)->extractUrls(trim($body));
    }

    public static function previewWithUrls(string $body, int $proseLimit = 120): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $urls = self::extractUrls($body);
        $prose = $body;
        foreach ($urls as $url) {
            $prose = str_replace($url, ' ', $prose);
        }
        $prose = trim(preg_replace('/\s+/', ' ', $prose) ?? '');

        $parts = [];
        if ($prose !== '') {
            $parts[] = Str::limit($prose, $proseLimit, '…');
        }
        foreach (array_slice($urls, 0, 5) as $url) {
            $parts[] = 'URL: '.$url;
        }

        return implode("\n", $parts);
    }
}
