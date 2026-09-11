<?php

namespace App\V2\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fetch readable page content via JINA Reader (https://jina.ai/reader).
 */
class JinaReaderService
{
    public function isConfigured(): bool
    {
        return trim((string) config('ai.providers.jina.key')) !== '';
    }

    /**
     * @return array{ok:bool, url:string, title:?string, content:string, error:?string}
     */
    public function scrape(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return $this->failure($url, 'URL is empty.');
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'https://'.ltrim($url, '/');
            }
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                return $this->failure($url, 'Invalid URL.');
            }
        }

        $readerUrl = 'https://r.jina.ai/'.Str::of($url)->ltrim('/');

        try {
            $pending = Http::timeout(45)
                ->withHeaders([
                    'Accept' => 'text/plain',
                    'X-Return-Format' => 'markdown',
                ]);

            $apiKey = trim((string) config('ai.providers.jina.key'));
            if ($apiKey !== '') {
                $pending = $pending->withToken($apiKey);
            }

            $response = $pending->get($readerUrl);
        } catch (\Throwable $e) {
            report($e);

            return $this->failure($url, 'Could not reach JINA Reader.');
        }

        if (! $response->ok()) {
            Log::warning('[JinaReader] scrape failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
            ]);

            return $this->failure($url, 'JINA could not read that page (HTTP '.$response->status().').');
        }

        $body = trim($response->body());
        if ($body === '') {
            return $this->failure($url, 'JINA returned empty content.');
        }

        $title = null;
        if (preg_match('/^Title:\s*(.+)$/m', $body, $matches)) {
            $title = trim($matches[1]);
        }

        return [
            'ok' => true,
            'url' => $url,
            'title' => $title,
            'content' => Str::limit($body, 12000),
            'error' => null,
        ];
    }

    /**
     * @return array{ok:bool, url:string, title:?string, content:string, error:?string}
     */
    private function failure(string $url, string $error): array
    {
        return [
            'ok' => false,
            'url' => $url,
            'title' => null,
            'content' => '',
            'error' => $error,
        ];
    }
}
