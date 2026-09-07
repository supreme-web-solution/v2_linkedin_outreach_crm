<script setup lang="ts">
import { Check, ChevronDown, Loader2, Settings2 } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

export type EmployeeSettings = {
    enabled: boolean;
    kill_switch: boolean;
    autonomy_level: number;
    employee_name: string;
};

const props = defineProps<{
    settings: EmployeeSettings;
}>();

const emit = defineEmits<{
    updated: [settings: EmployeeSettings];
}>();

const open = ref(false);
const form = ref<EmployeeSettings>({ ...props.settings, enabled: props.settings.enabled !== false });
const saving = ref(false);
const savingEnabled = ref(false);
const saved = ref(false);
const error = ref<string | null>(null);
let skipEnabledPersist = false;

const alexActive = computed(() => form.value.enabled !== false && !props.settings.kill_switch);

const nameDirty = computed(() => form.value.employee_name !== props.settings.employee_name);

watch(
    () => props.settings,
    (next) => {
        skipEnabledPersist = true;
        form.value = { ...next, enabled: next.enabled !== false };
        void nextTick(() => {
            skipEnabledPersist = false;
        });
    },
    { deep: true },
);

watch(
    () => form.value.enabled,
    async (enabled) => {
        if (!enabled) {
            open.value = false;
        }
        if (skipEnabledPersist) {
            return;
        }
        if (enabled === (props.settings.enabled !== false)) {
            return;
        }
        await persistSettings({ enabled }, true);
    },
);

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
    );
}

async function persistSettings(payload: Partial<EmployeeSettings>, auto = false) {
    if (props.settings.kill_switch) return;

    if (auto) {
        savingEnabled.value = true;
    } else {
        saving.value = true;
    }
    saved.value = false;
    error.value = null;

    try {
        const res = await fetch('/ai-employee/settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                enabled: payload.enabled ?? form.value.enabled,
                employee_name: (payload.employee_name ?? form.value.employee_name).trim(),
            }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message ?? 'Could not save settings');

        form.value = { ...data.settings, enabled: data.settings.enabled !== false };
        emit('updated', form.value);

        if (!auto) {
            saved.value = true;
            window.setTimeout(() => {
                saved.value = false;
            }, 2000);
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Could not save settings';
        form.value.enabled = props.settings.enabled !== false;
    } finally {
        saving.value = false;
        savingEnabled.value = false;
    }
}

async function saveName() {
    if (!nameDirty.value || saving.value || !alexActive.value) return;

    await persistSettings({
        employee_name: form.value.employee_name,
    });
}
</script>

<template>
    <div class="shrink-0 overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-card">
        <div class="border-b px-4 py-3">
            <div class="flex items-center gap-2 text-sm font-medium">
                <Settings2 class="size-4 shrink-0" />
                Employee settings
            </div>

            <div
                class="mt-3 flex items-center justify-between gap-3 rounded-lg border px-3 py-2.5"
                :class="
                    alexActive
                        ? 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-900/40 dark:bg-emerald-950/20'
                        : 'border-amber-200 bg-amber-50/80 dark:border-amber-900/40 dark:bg-amber-950/20'
                "
            >
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <Label for="ai-enabled" class="text-xs font-semibold">
                            Alex status
                        </Label>
                        <span
                            class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                            :class="
                                alexActive
                                    ? 'bg-emerald-600 text-white'
                                    : 'bg-amber-600 text-white'
                            "
                        >
                            {{ alexActive ? 'On' : 'Paused' }}
                        </span>
                    </div>
                    <p class="text-muted-foreground mt-1 text-[11px] leading-snug">
                        <template v-if="alexActive">
                            Alex plans and stages work for your approval — Review & Launch before anything sends.
                        </template>
                        <template v-else>
                            Alex is paused — chat is disabled until you turn this back on.
                        </template>
                    </p>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1">
                    <Loader2 v-if="savingEnabled" class="size-4 animate-spin text-muted-foreground" />
                    <Switch
                        id="ai-enabled"
                        v-model="form.enabled"
                        :disabled="settings.kill_switch || savingEnabled"
                        class="data-[state=checked]:bg-emerald-600 data-[state=unchecked]:bg-muted"
                    />
                    <span class="text-[10px] font-medium text-muted-foreground">
                        {{ form.enabled ? 'Running' : 'Paused' }}
                    </span>
                </div>
            </div>

            <div
                v-if="settings.kill_switch"
                class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
            >
                AI is paused by an admin kill switch. Contact support to re-enable.
            </div>
        </div>

        <Collapsible v-model:open="open">
            <CollapsibleTrigger
                class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition"
                :class="alexActive ? 'hover:bg-muted/30' : 'cursor-not-allowed opacity-50'"
                :disabled="!alexActive"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 text-sm font-medium">
                        <span>Display name</span>
                        <span
                            v-if="nameDirty"
                            class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                        >
                            Unsaved
                        </span>
                    </div>
                    <p class="text-muted-foreground mt-0.5 text-xs leading-snug">
                        {{ form.employee_name || 'Alex' }}
                    </p>
                </div>
                <ChevronDown
                    class="size-4 shrink-0 text-muted-foreground transition-transform duration-200"
                    :class="open ? 'rotate-180' : ''"
                />
            </CollapsibleTrigger>

            <CollapsibleContent>
                <div class="border-t px-4 py-4">
                    <div class="space-y-1.5">
                        <Label for="employee-name" class="text-xs font-medium">Name shown in chat</Label>
                        <Input
                            id="employee-name"
                            v-model="form.employee_name"
                            maxlength="40"
                            class="h-9 text-sm"
                            :disabled="!alexActive"
                        />
                    </div>

                    <p v-if="error" class="mt-3 text-xs text-destructive">{{ error }}</p>

                    <Button
                        class="mt-4 w-full"
                        size="sm"
                        :disabled="!nameDirty || saving || !alexActive"
                        @click="saveName"
                    >
                        <Loader2 v-if="saving" class="mr-1 size-3.5 animate-spin" />
                        <Check v-else-if="saved" class="mr-1 size-3.5" />
                        {{ saving ? 'Saving…' : saved ? 'Saved' : 'Save name' }}
                    </Button>
                </div>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>
