<?php

namespace App\V2\Services;

use App\V2\Integrations\Unipile\UnipileException;
use Throwable;

class OutreachUserErrorMapper
{
    public const USER_MESSAGE = 'Messaging is unavailable right now. Your message was saved — please contact your admin.';

    /**
     * @return array{user_message: string, admin_detail: string, retryable: bool}
     */
    public static function map(Throwable $exception): array
    {
        $adminDetail = $exception->getMessage();
        $retryable = true;

        if ($exception instanceof UnipileException) {
            $type = (string) ($exception->context['error_code'] ?? '');
            if ($type === '' && is_array($exception->context['response'] ?? null)) {
                $type = (string) ($exception->context['response']['type'] ?? '');
            }
            $status = $exception->statusCode;

            if ($type === 'errors/subscription_required' || $status === 402 || $status === 403) {
                return [
                    'user_message' => self::USER_MESSAGE,
                    'admin_detail' => $adminDetail,
                    'retryable' => false,
                ];
            }
        }

        if (str_contains(strtolower($adminDetail), 'subscription_required')
            || str_contains(strtolower($adminDetail), 'subscription required')) {
            return [
                'user_message' => self::USER_MESSAGE,
                'admin_detail' => $adminDetail,
                'retryable' => false,
            ];
        }

        return [
            'user_message' => 'Could not send your message right now. Your draft was kept — please try again later or contact your admin.',
            'admin_detail' => $adminDetail,
            'retryable' => $retryable,
        ];
    }

    public static function isNonRetryable(Throwable $exception): bool
    {
        return !self::map($exception)['retryable'];
    }

    public static function userMessageForCall(?array $meta): ?string
    {
        if (!is_array($meta)) {
            return null;
        }

        $user = trim((string) ($meta['launch_error_user'] ?? ''));
        if ($user !== '') {
            return $user;
        }

        if (!empty($meta['launch_error'])) {
            return self::USER_MESSAGE;
        }

        return null;
    }
}
