<?php

namespace App\V2\Ai\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Transcription;

class WhatsAppVoiceTranscriptionService
{
    /**
     * Download a WhatsApp voice note and transcribe it for Alex.
     *
     * @param  array{type?:string,url?:string,mime?:string}  $media
     */
    public function transcribe(array $media): ?string
    {
        $url = trim((string) ($media['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        $mime = $this->normalizeMime(isset($media['mime']) ? (string) $media['mime'] : null);
        $path = null;

        try {
            $binary = $this->downloadMedia($url);
            $path = $this->storeTempAudio($binary, $mime);

            $transcript = trim((string) Transcription::fromPath($path, $mime)->generate());
            if ($transcript === '') {
                Log::warning('[WhatsAppVoice] empty transcription', ['url' => Str::limit($url, 120)]);

                return null;
            }

            return $transcript;
        } catch (\Throwable $e) {
            report($e);
            Log::warning('[WhatsAppVoice] transcription failed', [
                'error' => $e->getMessage(),
                'url' => Str::limit($url, 120),
            ]);

            return null;
        } finally {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function downloadMedia(string $url): string
    {
        $response = Http::timeout(90)->get($url);
        if (! $response->successful()) {
            $apiKey = (string) config('socifusion_ai.zernio.api_key', '');
            if ($apiKey !== '') {
                $response = Http::withToken($apiKey)->timeout(90)->get($url);
            }
        }

        if (! $response->successful()) {
            throw new \RuntimeException('Could not download WhatsApp voice note.');
        }

        $body = $response->body();
        if ($body === '') {
            throw new \RuntimeException('WhatsApp voice note download was empty.');
        }

        return $body;
    }

    private function storeTempAudio(string $binary, ?string $mime): string
    {
        $dir = storage_path('app/tmp/whatsapp-voice');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ext = match (true) {
            $mime === 'audio/ogg', $mime === 'audio/opus' => 'ogg',
            $mime === 'audio/mpeg', $mime === 'audio/mp3' => 'mp3',
            $mime === 'audio/mp4', $mime === 'audio/m4a', $mime === 'audio/x-m4a' => 'm4a',
            $mime === 'audio/wav', $mime === 'audio/x-wav', $mime === 'audio/wave' => 'wav',
            $mime === 'audio/amr', $mime === 'audio/3gpp' => 'amr',
            default => 'ogg',
        };

        $path = $dir.DIRECTORY_SEPARATOR.Str::uuid()->toString().'.'.$ext;
        if (file_put_contents($path, $binary) === false) {
            throw new \RuntimeException('Could not store WhatsApp voice note temporarily.');
        }

        return $path;
    }

    private function normalizeMime(?string $mime): ?string
    {
        $mime = strtolower(trim((string) $mime));
        if ($mime === '') {
            return 'audio/ogg';
        }

        // Strip codecs like "audio/ogg; codecs=opus"
        if (str_contains($mime, ';')) {
            $mime = trim(explode(';', $mime, 2)[0]);
        }

        return $mime;
    }
}
