<?php

namespace App\V2\Ai\Services;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Workspace messaging prefs + proof library (domain-agnostic).
 * Stored in ai_employee_settings.meta.copy_prefs — additive to ICP.
 */
class OutboundCopyPrefsService
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
    ) {}

    /**
     * @return array{
     *     tone:?string,
     *     preferred_angle:?string,
     *     style_notes:?string,
     *     do_not_say:list<string>,
     *     proof_points:list<array<string,mixed>>,
     *     updated_at:?string
     * }
     */
    public function for(AiEmployeeSetting $settings): array
    {
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $prefs = is_array($meta['copy_prefs'] ?? null) ? $meta['copy_prefs'] : [];
        $icp = is_array($meta['stored_icp']['icp'] ?? null) ? $meta['stored_icp']['icp'] : [];

        $doNotSay = $this->stringList($prefs['do_not_say'] ?? null);
        $icpDoNotSay = $this->stringList($icp['do_not_say'] ?? null);
        foreach ($icpDoNotSay as $phrase) {
            if (! in_array($phrase, $doNotSay, true)) {
                $doNotSay[] = $phrase;
            }
        }

        return [
            'tone' => $this->nullableString($prefs['tone'] ?? null),
            'preferred_angle' => $this->nullableString($prefs['preferred_angle'] ?? null),
            'style_notes' => $this->nullableString($prefs['style_notes'] ?? null),
            'do_not_say' => array_slice($doNotSay, 0, 20),
            'proof_points' => $this->normalizeProofPoints($prefs['proof_points'] ?? null),
            'updated_at' => $this->nullableString($prefs['updated_at'] ?? null),
        ];
    }

    /**
     * Compact block for composer / agent briefs.
     */
    public function briefBlock(AiEmployeeSetting $settings): ?array
    {
        $prefs = $this->for($settings);
        $block = array_filter([
            'tone' => $prefs['tone'],
            'preferred_angle' => $prefs['preferred_angle'],
            'style_notes' => $prefs['style_notes'],
            'do_not_say' => $prefs['do_not_say'] !== [] ? $prefs['do_not_say'] : null,
            'proof_points' => $prefs['proof_points'] !== [] ? $prefs['proof_points'] : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $block !== [] ? $block : null;
    }

    /**
     * Additive merge into meta.copy_prefs.
     *
     * @param  array{
     *     tone?:?string,
     *     preferred_angle?:?string,
     *     style_notes?:?string,
     *     do_not_say?:list<string>|string,
     *     proof_points?:list<array<string,mixed>>,
     *     append_do_not_say?:list<string>|string,
     *     append_style_note?:?string
     * }  $patch
     */
    public function merge(User $user, int $organizationId, array $patch): AiEmployeeSetting
    {
        $current = $this->settingsService->for($user, $organizationId);
        $row = AiEmployeeSetting::query()->firstOrNew([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
        ]);

        if (! $row->exists) {
            $row->fill([
                'enabled' => $current->enabled,
                'kill_switch' => false,
                'autonomy_level' => $current->autonomy_level,
                'employee_name' => $current->employee_name,
                'allowed_execute_tools' => $current->allowed_execute_tools,
                'meta' => is_array($current->meta) ? $current->meta : [],
            ]);
        }

        $meta = is_array($row->meta) ? $row->meta : (is_array($current->meta) ? $current->meta : []);
        $prefs = $this->for($current);

        if (array_key_exists('tone', $patch)) {
            $prefs['tone'] = $this->nullableString($patch['tone']);
        }
        if (array_key_exists('preferred_angle', $patch)) {
            $prefs['preferred_angle'] = $this->nullableString($patch['preferred_angle']);
        }
        if (array_key_exists('style_notes', $patch)) {
            $prefs['style_notes'] = $this->nullableString($patch['style_notes']);
        }
        if (array_key_exists('do_not_say', $patch)) {
            $prefs['do_not_say'] = $this->stringList($patch['do_not_say']);
        }
        if (array_key_exists('append_do_not_say', $patch)) {
            foreach ($this->stringList($patch['append_do_not_say']) as $phrase) {
                if (! in_array($phrase, $prefs['do_not_say'], true)) {
                    $prefs['do_not_say'][] = $phrase;
                }
            }
        }
        if (array_key_exists('append_style_note', $patch)) {
            $note = $this->nullableString($patch['append_style_note']);
            if ($note !== null) {
                $existing = trim((string) ($prefs['style_notes'] ?? ''));
                $prefs['style_notes'] = $existing !== ''
                    ? Str::limit($existing."\n".$note, 1200, '')
                    : $note;
            }
        }
        if (array_key_exists('proof_points', $patch)) {
            $prefs['proof_points'] = $this->normalizeProofPoints($patch['proof_points']);
        }

        $prefs['do_not_say'] = array_slice($prefs['do_not_say'], 0, 20);
        $prefs['proof_points'] = array_slice($prefs['proof_points'], 0, 5);
        $prefs['updated_at'] = now()->toIso8601String();

        $meta['copy_prefs'] = $prefs;
        $row->meta = $meta;
        $row->save();

        return $row->fresh() ?? $row;
    }

    /**
     * Persist lessons from an approved/edited outbound plan.
     *
     * @param  array<string, mixed>  $payload
     */
    public function learnFromLaunchedPlan(User $user, int $organizationId, array $payload): void
    {
        $patch = [];

        $offer = trim((string) ($payload['offer_override'] ?? ''));
        if ($offer !== '') {
            $patch['preferred_angle'] = Str::limit($offer, 300, '');
        }

        if (! empty($payload['message_correction']) || ! empty($payload['draft_edited_by_owner'])) {
            $patch['append_style_note'] = 'Owner edited prior outbound copy — prefer their revised tone and avoid repeating the rejected angle.';
        }

        $doNotSay = $this->stringList($payload['do_not_say'] ?? null);
        if ($doNotSay !== []) {
            $patch['append_do_not_say'] = $doNotSay;
        }

        $tone = $this->nullableString($payload['tone_pref'] ?? $payload['tone'] ?? null);
        if ($tone !== null) {
            $patch['tone'] = $tone;
        }

        if ($patch === []) {
            return;
        }

        try {
            $this->merge($user, $organizationId, $patch);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\n,]+/', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $s = trim((string) $item);
            if ($s !== '' && ! in_array($s, $out, true)) {
                $out[] = Str::limit($s, 120, '');
            }
        }

        return $out;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s !== '' ? Str::limit($s, 500, '') : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeProofPoints(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }
            $point = array_filter([
                'title' => $this->nullableString($row['title'] ?? null),
                'outcome' => $this->nullableString($row['outcome'] ?? null),
                'industry' => $this->nullableString($row['industry'] ?? null),
                'integration' => $this->nullableString($row['integration'] ?? null),
                'summary' => $this->nullableString($row['summary'] ?? null),
                'url' => $this->nullableString($row['url'] ?? null),
            ], fn ($v) => $v !== null);
            if ($point === []) {
                continue;
            }
            $out[] = $point;
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }
}
