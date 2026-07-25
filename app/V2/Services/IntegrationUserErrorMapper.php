<?php

namespace App\V2\Services;

use App\V2\Integrations\Unipile\UnipileException;
use Illuminate\Support\Facades\Log;
use Throwable;

class IntegrationUserErrorMapper
{
    public static function forConnection(Throwable $exception, ?string $channelLabel = null): string
    {
        $channel = $channelLabel !== null && $channelLabel !== ''
            ? $channelLabel
            : 'This channel';

        if ($exception instanceof UnipileException) {
            $type = (string) ($exception->context['error_code'] ?? '');
            if ($type === '' && is_array($exception->context['response'] ?? null)) {
                $type = (string) ($exception->context['response']['type'] ?? '');
            }

            $status = $exception->statusCode;

            if ($type === 'errors/subscription_required' || $status === 402) {
                return 'This integration requires an active subscription. Please contact your administrator.';
            }

            if ($status === 403) {
                return 'You do not have permission to connect this integration. Please contact your administrator.';
            }

            if ($type === 'errors/invalid_parameters' || $status === 400) {
                return "{$channel} could not be connected due to a configuration issue. Please contact your administrator.";
            }

            if ($status === 401) {
                return 'The integration service rejected our request. Please contact your administrator.';
            }

            if ($status >= 500) {
                return 'The integration service is temporarily unavailable. Please try again in a few minutes.';
            }
        }

        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'subscription_required') || str_contains($message, 'subscription required')) {
            return 'This integration requires an active subscription. Please contact your administrator.';
        }

        if (str_contains($message, 'api key is missing')) {
            return 'Integrations are not configured on this server. Please contact your administrator.';
        }

        return "{$channel} could not be connected. Please try again or contact your administrator if the problem continues.";
    }

    public static function log(Throwable $exception, string $action, array $context = []): void
    {
        $payload = [
            'action' => $action,
            'error' => $exception->getMessage(),
        ];

        if ($exception instanceof UnipileException) {
            $payload['status'] = $exception->statusCode;
            $payload['error_code'] = $exception->context['error_code'] ?? null;
            if (is_array($exception->context['response'] ?? null)) {
                $response = $exception->context['response'];
                $payload['title'] = $response['title'] ?? null;
                $detail = (string) ($response['detail'] ?? '');
                $payload['detail'] = strlen($detail) > 500 ? substr($detail, 0, 500).'…' : $detail;
            }
        }

        Log::error('[Integrations] '.$action, array_merge($payload, $context));
    }
}
