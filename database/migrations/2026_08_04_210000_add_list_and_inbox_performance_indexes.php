<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audience_lists', function (Blueprint $table) {
            $table->index(['audience_id', 'email_fetch_status'], 'audience_lists_audience_email_status_idx');
            $table->index(['audience_id', 'email_fetch_attempted_at'], 'audience_lists_audience_email_attempted_idx');
        });

        Schema::table('sn_leads', function (Blueprint $table) {
            $table->index(['sn_list_id', 'email_fetch_status'], 'sn_leads_list_email_status_idx');
            $table->index(['sn_list_id', 'email_fetch_attempted_at'], 'sn_leads_list_email_attempted_idx');
        });

        Schema::table('v2_messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'direction', 'received_at'], 'v2_messages_conversation_direction_received_idx');
        });

        Schema::table('v2_conversations', function (Blueprint $table) {
            $table->index(['user_id', 'provider', 'last_message_at'], 'v2_conversations_user_provider_last_msg_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audience_lists', function (Blueprint $table) {
            $table->dropIndex('audience_lists_audience_email_status_idx');
            $table->dropIndex('audience_lists_audience_email_attempted_idx');
        });

        Schema::table('sn_leads', function (Blueprint $table) {
            $table->dropIndex('sn_leads_list_email_status_idx');
            $table->dropIndex('sn_leads_list_email_attempted_idx');
        });

        Schema::table('v2_messages', function (Blueprint $table) {
            $table->dropIndex('v2_messages_conversation_direction_received_idx');
        });

        Schema::table('v2_conversations', function (Blueprint $table) {
            $table->dropIndex('v2_conversations_user_provider_last_msg_idx');
        });
    }
};
