<?php

namespace App\V2\Services;

class EmailAddressQuality
{
    /** @var list<string> */
    private const PLACEHOLDER_DOMAINS = [
        'example.com',
        'example.org',
        'example.net',
        'test.com',
        'email.com',
        'company.com',
        'domain.com',
        'sample.com',
        'placeholder.com',
        'mail.com',
        'yopmail.com',
        'localhost',
    ];

    /** @var list<string> */
    private const PLACEHOLDER_LOCAL_PARTS = [
        'john',
        'jane',
        'test',
        'demo',
        'sample',
        'prospect',
        'email',
        'user',
        'fake',
        'noreply',
    ];

    /**
     * @return array{level: string, label: string, hint: string|null}
     */
    public function assess(?string $email): array
    {
        $normalized = strtolower(trim((string) $email));

        if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return [
                'level' => 'invalid',
                'label' => 'Invalid email',
                'hint' => 'This contact has no valid email address on file.',
            ];
        }

        [$local, $domain] = array_pad(explode('@', $normalized, 2), 2, '');

        if (in_array($domain, self::PLACEHOLDER_DOMAINS, true)) {
            return [
                'level' => 'placeholder',
                'label' => 'Placeholder email',
                'hint' => 'Looks like sample data (e.g. @example.com). Enrich the lead or update the address before sending.',
            ];
        }

        if (in_array($local, self::PLACEHOLDER_LOCAL_PARTS, true) && in_array($domain, ['company.com', 'email.com', 'test.com'], true)) {
            return [
                'level' => 'placeholder',
                'label' => 'Placeholder email',
                'hint' => 'This address looks like demo/import data, not a real prospect inbox.',
            ];
        }

        if (str_contains($local, 'example') || str_contains($domain, 'example.')) {
            return [
                'level' => 'placeholder',
                'label' => 'Placeholder email',
                'hint' => 'Replace example/test addresses with a real work or personal email.',
            ];
        }

        return [
            'level' => 'ok',
            'label' => 'Email on file',
            'hint' => null,
        ];
    }
}
