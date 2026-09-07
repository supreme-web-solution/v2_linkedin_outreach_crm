<?php

namespace App\V2\Ai\Support;

final class PlanLeadList
{
    /**
     * Attach a lead list reference to a Command Center plan payload.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function merge(array $plan, ?string $listHash, ?string $listSrc, ?string $listName = null): array
    {
        $hash = trim((string) $listHash);
        $src = trim((string) $listSrc);

        if ($hash === '' || ! in_array($src, ['aud', 'sn', 'csv'], true)) {
            return $plan;
        }

        $plan['list_hash'] = $hash;
        $plan['list_src'] = $src;
        if ($listName !== null && trim($listName) !== '') {
            $plan['list_name'] = trim($listName);
        }

        return $plan;
    }
}
