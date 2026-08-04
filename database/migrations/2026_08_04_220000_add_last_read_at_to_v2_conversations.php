<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_conversations', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable()->after('last_message_at');
        });

        DB::table('v2_conversations')
            ->whereNotNull('meta')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $meta = is_string($row->meta) ? json_decode($row->meta, true) : null;
                    if (! is_array($meta)) {
                        continue;
                    }

                    $raw = $meta['last_read_at'] ?? null;
                    if (! is_string($raw) || trim($raw) === '') {
                        continue;
                    }

                    try {
                        $parsed = Carbon::parse($raw);
                    } catch (\Throwable) {
                        continue;
                    }

                    DB::table('v2_conversations')
                        ->where('id', $row->id)
                        ->update(['last_read_at' => $parsed]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('v2_conversations', function (Blueprint $table) {
            $table->dropColumn('last_read_at');
        });
    }
};
