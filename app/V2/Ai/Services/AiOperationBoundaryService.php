<?php

namespace App\V2\Ai\Services;

class AiOperationBoundaryService
{
    public function assertCopyOnlyContext(string $context): void
    {
        $text = strtolower(trim($context));
        if ($text === '') {
            return;
        }

        $patterns = [
            '/\b(launch|activate|send now|run now|execute now)\b/',
            '/\b(contact\s+\d+|message\s+\d+|reach out to)\b/',
            '/\b(approve|auto-approve|skip approval)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                throw new \InvalidArgumentException(
                    'This endpoint is copy-only. Action decisions or execution requests must go through AI Employee Command Center.'
                );
            }
        }
    }
}
