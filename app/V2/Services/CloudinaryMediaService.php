<?php

namespace App\V2\Services;

use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class CloudinaryMediaService
{
    public function isConfigured(): bool
    {
        return $this->cloudName() !== '' && $this->apiKey() !== '' && $this->apiSecret() !== '';
    }

    /**
     * @return array{url: string, public_id: string, resource_type: string, secure_url: string}
     */
    public function upload(UploadedFile|string $file, string $folder, ?string $publicId = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cloudinary is not configured. Set CLOUDINARY_URL in .env.');
        }

        $options = [
            'folder' => trim($folder, '/'),
            'resource_type' => 'auto',
            'use_filename' => true,
            'unique_filename' => true,
        ];

        if ($publicId !== null && $publicId !== '') {
            $options['public_id'] = $publicId;
            unset($options['unique_filename']);
        }

        $source = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! is_string($source) || $source === '' || ! is_readable($source)) {
            throw new RuntimeException('Unable to read upload source for Cloudinary.');
        }

        $result = $this->client()->uploadApi()->upload($source, $options);

        return [
            'url' => (string) ($result['url'] ?? ''),
            'secure_url' => (string) ($result['secure_url'] ?? $result['url'] ?? ''),
            'public_id' => (string) ($result['public_id'] ?? ''),
            'resource_type' => (string) ($result['resource_type'] ?? 'image'),
        ];
    }

    /**
     * @return array{url: string, public_id: string, resource_type: string, secure_url: string}
     */
    public function uploadBinary(string $binary, string $folder, string $filename = 'file.bin'): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cloudinary is not configured. Set CLOUDINARY_URL in .env.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cld_');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create temp file for Cloudinary upload.');
        }

        file_put_contents($tmp, $binary);

        try {
            return $this->upload($tmp, $folder);
        } finally {
            @unlink($tmp);
        }
    }

    public function url(string $publicId, string $resourceType = 'image'): string
    {
        if (! $this->isConfigured()) {
            return '';
        }

        return (string) $this->client()->image($publicId, [
            'resource_type' => $resourceType,
            'secure' => true,
        ]);
    }

    /**
     * Download file bytes from Cloudinary (for forwarding to Unipile etc.).
     */
    public function download(string $secureUrl): string
    {
        $content = @file_get_contents($secureUrl);
        if ($content === false) {
            throw new RuntimeException('Failed to download file from Cloudinary.');
        }

        return $content;
    }

    private function client(): Cloudinary
    {
        $url = (string) config('services.cloudinary.url', '');
        if ($url !== '') {
            return new Cloudinary($url);
        }

        return new Cloudinary([
            'cloud' => [
                'cloud_name' => $this->cloudName(),
                'api_key' => $this->apiKey(),
                'api_secret' => $this->apiSecret(),
            ],
            'url' => [
                'secure' => true,
            ],
        ]);
    }

    private function cloudName(): string
    {
        return (string) config('services.cloudinary.cloud_name', '');
    }

    private function apiKey(): string
    {
        return (string) config('services.cloudinary.api_key', '');
    }

    private function apiSecret(): string
    {
        return (string) config('services.cloudinary.api_secret', '');
    }
}
