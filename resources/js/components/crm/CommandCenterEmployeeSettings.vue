<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { ChevronDown, Loader2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

type Settings = {
    enabled: boolean;
    kill_switch: boolean;
    autonomy_level: number;
    employee_name: string;
};

const props = defineProps<{
    settings: Settings;
    isPlatformAdmin?: boolean;
}>();

const emit = defineEmits<{
    updated: [settings: Settings];
}>();

function normalizeSettings(settings: Settings): Settings {
    return {
        ...settings,
        enabled: Boolean(settings.enabled),
        kill_switch: Boolean(settings.kill_switch),
        autonomy_level: Number(settings.autonomy_level),
    };
}

const local = ref(normalizeSettings(props.settings));
const saving = ref(false);
const open = ref(false);
const feedback = ref<{ type: 'success' | 'error'; message: string } | null>(null);
let feedbackTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => props.settings,
    (next) => {
        local.value = normalizeSettings(next);
    },
    { deep: true },
);

const autonomyOptions = computed(() => {
    const options = [
        {
            value: 1,
            label: 'Copilot',
            description: 'Alex recommends only — you run everything in the app.',
        },
        {
            value: 2,
            label: 'Assisted',
            description: 'Alex stages plans; you Launch or say "go ahead".',
        },
        {
            value: 3,
            label: 'Autopilot',
            description: 'Alex auto-searches LinkedIn, stages plans, and launches campaigns without "go ahead".',
        },
    ];

    if (props.isPlatformAdmin) {
        options.push({
            value: 4,
            label: 'Autonomous',
            description: 'Admin only — full plan-and-execute: discover, launch, and optimize with minimal gates.',
        });
    }

    return options;
});

const autonomyLabel = computed(() => {
    const map: Record<number, string> = {
        1: 'Copilot',
        2: 'Assisted',
        3: 'Autopilot',
        4: 'Autonomous',
    };
    return map[local.value.autonomy_level] ?? 'Assisted';
});

const enabledModel = computed({
    get: () => local.value.enabled,
    set: (value: boolean) => {
        void onToggleEnabled(value);
    },
});

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
    );
}

function showFeedback(type: 'success' | 'error', message: string) {
    feedback.value = { type, message };
    if (feedbackTimer) {
        clearTimeout(feedbackTimer);
    }
    feedbackTimer = setTimeout(() => {
        feedback.value = null;
    }, 2500);
}

async function save(patch: Partial<Settings>, successMessage: string) {
    saving.value = true;
    try {
        const res = await fetch('/ai-employee/settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(patch),
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message ?? 'Failed to save settings');
        }
        local.value = normalizeSettings({ ...local.value, ...data.settings });
        emit('updated', local.value);
        showFeedback('success', successMessage);
    } catch (e) {
        const message = e instanceof Error ? e.message : 'Failed to save settings';
        showFeedback('error', message);
        throw e;
    } finally {
        saving.value = false;
    }
}

async function onToggleEnabled(enabled: boolean) {
    const previous = local.value.enabled;
    local.value.enabled = enabled;
    try {
        await save({ enabled }, enabled ? 'Alex enabled' : 'Alex paused');
    } catch {
        local.value.enabled = previous;
    }
}

async function onNameBlur() {
    const name = local.value.employee_name.trim();
    if (name && name !== props.settings.employee_name) {
        try {
            await save({ employee_name: name }, 'Display name saved');
        } catch {
            local.value.employee_name = props.settings.employee_name;
        }
    }
}

async function onAutonomyChange(level: number) {
    if (level === local.value.autonomy_level) {
        return;
    }
    const previous = local.value.autonomy_level;
    local.value.autonomy_level = level;
    const label = autonomyOptions.value.find((o) => o.value === level)?.label ?? 'Mode';
    try {
        await save({ autonomy_level: level }, `${label} mode saved`);
    } catch {
        local.value.autonomy_level = previous;
    }
}
</script>

<template>
    <Collapsible v-model:open="open" class="rounded-lg border bg-card">
        <CollapsibleTrigger
            class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium hover:bg-muted/50"
        >
            <span class="flex items-center gap-2">
                Alex settings
                <Loader2 v-if="saving" class="size-3.5 animate-spin text-muted-foreground" />
            </span>
            <span class="flex items-center gap-2 text-xs font-normal text-muted-foreground">
                {{ autonomyLabel }}
                <ChevronDown class="size-4 shrink-0 transition-transform" :class="open ? 'rotate-180' : ''" />
            </span>
        </CollapsibleTrigger>
        <CollapsibleContent class="space-y-4 border-t px-4 py-4">
            <p
                v-if="feedback"
                class="rounded-md px-3 py-2 text-xs"
                :class="
                    feedback.type === 'success'
                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                        : 'bg-destructive/10 text-destructive'
                "
            >
                {{ feedback.message }}
            </p>

            <div class="flex items-center justify-between gap-3">
                <div>
                    <Label for="alex-enabled">Enable Alex</Label>
                    <p class="text-xs text-muted-foreground">Turn off to pause Command Center replies.</p>
                </div>
                <Switch
                    id="alex-enabled"
                    v-model="enabledModel"
                    :disabled="saving || local.kill_switch"
                />
            </div>

            <div class="space-y-2">
                <Label for="alex-name">Display name</Label>
                <input
                    id="alex-name"
                    v-model="local.employee_name"
                    type="text"
                    maxlength="40"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                    :disabled="saving"
                    @blur="onNameBlur"
                />
            </div>

            <div class="space-y-2">
                <Label>Autonomy mode</Label>
                <p class="text-xs text-muted-foreground">
                    Controls how much Alex can do without your explicit approval. Changes save automatically.
                </p>
                <div class="space-y-2">
                    <button
                        v-for="opt in autonomyOptions"
                        :key="opt.value"
                        type="button"
                        class="w-full rounded-md border px-3 py-2 text-left text-sm transition-colors"
                        :class="
                            local.autonomy_level === opt.value
                                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                : 'border-border hover:bg-muted/50'
                        "
                        :disabled="saving"
                        @click="onAutonomyChange(opt.value)"
                    >
                        <span class="font-medium">{{ opt.label }}</span>
                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ opt.description }}</span>
                    </button>
                </div>
            </div>

            <p v-if="local.kill_switch" class="text-xs text-destructive">
                Alex is paused by an admin kill switch. Contact support to restore access.
            </p>
        </CollapsibleContent>
    </Collapsible>
</template>
