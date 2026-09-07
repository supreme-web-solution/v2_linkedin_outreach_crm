<?php

namespace App\Console\Commands;

use App\V2\Ai\Integrations\ZernioClient;
use App\V2\Services\OpenAIContentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DiagnoseAiCommand extends Command
{
    protected $signature = 'ai:diagnose';

    protected $description = 'Check SociFusion AI Employee config: OpenAI, Zernio, migrations, autonomy';

    public function handle(ZernioClient $zernio, OpenAIContentService $openai): int
    {
        $this->info('SociFusion AI Employee diagnostics');
        $this->newLine();

        $enabled = (bool) config('socifusion_ai.enabled', true);
        $killSwitch = (bool) config('socifusion_ai.kill_switch', false);
        $autonomy = (int) config('socifusion_ai.default_autonomy', 2);
        $employee = (string) config('socifusion_ai.employee_name', 'Alex');

        $this->line('Employee: '.$employee);
        $this->line('Enabled: '.($enabled ? 'yes' : 'no'));
        $this->line('Kill switch: '.($killSwitch ? 'ON (tools blocked)' : 'off'));
        $this->line('Default autonomy: '.$autonomy);

        $this->newLine();
        $this->info('OpenAI');

        if ($openai->isConfigured()) {
            $this->line('  ✓ API key configured');
        } else {
            $this->warn('  ✗ OPENAI_API_KEY / services.chatgpt.key missing — agent chat and drafts will fail');
        }

        $this->newLine();
        $this->info('Zernio (WhatsApp Command Center)');

        $checks = [
            'ZERNIO_BASE_URL' => $zernio->baseUrl(),
            'ZERNIO_API_KEY' => $zernio->apiKey() !== '' ? '(set)' : '',
            'ZERNIO_WEBHOOK_SECRET' => $zernio->webhookSecret() !== '' ? '(set)' : '',
            'ZERNIO_FROM_NUMBER' => (string) config('socifusion_ai.zernio.from_number', ''),
        ];

        foreach ($checks as $label => $value) {
            if ($value === '') {
                $this->warn("  ✗ {$label} not set");
            } else {
                $this->line("  ✓ {$label}: ".($value === '(set)' ? $value : $value));
            }
        }

        if ($zernio->configured()) {
            $this->line('  ✓ Zernio client ready for production send/receive');
        } else {
            $this->warn('  ✗ Zernio not fully configured — WhatsApp runs in stub mode');
        }

        $webhookUrl = url('/api/v2/provider-events/zernio');
        $this->line('  Webhook URL: '.$webhookUrl);
        $this->line('  Subscribe to: message.received');

        $this->newLine();
        $this->info('Database');

        if (Schema::hasTable('ai_zernio_webhook_events')) {
            $this->line('  ✓ ai_zernio_webhook_events table exists');
        } else {
            $this->warn('  ✗ Run php artisan migrate (missing ai_zernio_webhook_events)');
        }

        $requiredTables = [
            'ai_conversations',
            'ai_messages',
            'ai_action_approvals',
            'ai_employee_settings',
        ];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("  ✗ Missing table: {$table}");
            }
        }

        $this->newLine();
        $this->line('Command Center: '.url('/ai-employee'));

        return self::SUCCESS;
    }
}
