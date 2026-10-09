<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FULL = ['FE', 'Bundle', 'Reseller', 'AffiliateCampaignVault', 'ProfitMultiplier'];

    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('entitlements')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    $old = json_decode((string) $user->entitlements, true);
                    if (! is_array($old)) {
                        continue;
                    }

                    $new = $this->map($old);

                    if ($new !== array_values($old)) {
                        DB::table('users')->where('id', $user->id)->update([
                            'entitlements' => json_encode($new),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Legacy OTO entitlements are retired; no safe rollback.
    }

    /**
     * @param array<int, mixed> $old
     * @return list<string>
     */
    private function map(array $old): array
    {
        if (in_array('Bundle', $old, true)) {
            return self::FULL;
        }

        $new = array_values(array_intersect($old, self::FULL));

        if (in_array('OTO5', $old, true)) {
            $new[] = 'Reseller';
        }

        return array_values(array_unique($new));
    }
};
