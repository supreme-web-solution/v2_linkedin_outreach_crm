<script setup lang="ts">
import { computed } from 'vue';
import { Trash2 } from '@lucide/vue';
import FlowMessageAiHelp from '@/components/flow/FlowMessageAiHelp.vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import type { OutreachChannel, OutreachStep } from '@/components/outreach/types';

const props = defineProps<{
    step: OutreachStep;
    channelRegistry: {
        channels: Record<string, { label: string; color: string }>;
        actions: Record<string, Array<{ key: string; label: string }>>;
        conditions?: Record<string, Array<{ key: string; label: string }>>;
    };
}>();

const emit = defineEmits<{
    updateField: [field: string, value: unknown];
    updateConfig: [key: string, value: unknown];
    delete: [];
}>();

const isAction = computed(() => props.step.type === 'action');
const isDelay = computed(() => props.step.type === 'delay');
const isCondition = computed(() => props.step.type === 'condition');
const conditionOptions = computed(() => {
    const ch = props.step.channel;
    if (!ch) return [];
    return props.channelRegistry.conditions?.[ch] ?? [];
});
const defaultTimeoutDays = computed(() => {
    const cond = props.step.condition ?? '';
    return cond === 'invite_accepted' ? 7 : 3;
});
const channelColor = computed(() => {
    const ch = props.step.channel;
    return ch ? (props.channelRegistry.channels[ch]?.color ?? '#64748b') : '#64748b';
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Selected step</p>
                <p class="text-sm font-semibold">{{ step.label }}</p>
                <p v-if="step.channel" class="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground">
                    <OutreachChannelIcon :channel="step.channel as OutreachChannel" class="h-3 w-3" />
                    {{ channelRegistry.channels[step.channel]?.label ?? step.channel }}
                </p>
            </div>
            <button
                v-if="!isCondition"
                type="button"
                class="rounded-lg p-1.5 text-muted-foreground hover:bg-red-50 hover:text-red-500"
                title="Remove step"
                @click="emit('delete')"
            >
                <Trash2 class="h-4 w-4" />
            </button>
        </div>

        <template v-if="isDelay">
            <div class="flex items-center gap-2">
                <span class="text-xs font-medium text-amber-700">Wait</span>
                <input
                    type="number"
                    :value="step.value"
                    min="1"
                    max="365"
                    class="w-16 rounded-lg border border-amber-200 px-2 py-1 text-xs text-center"
                    @change="emit('updateField', 'value', +(($event.target as HTMLInputElement).value))"
                />
                <select
                    :value="step.time"
                    class="rounded-lg border border-amber-200 bg-white px-2 py-1 text-xs"
                    @change="emit('updateField', 'time', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="hours">hours</option>
                    <option value="days">days</option>
                </select>
            </div>
        </template>

        <template v-if="isAction">
            <template v-if="step.action === 'send_invite' || step.action === 'send_message'">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ step.action === 'send_invite' ? 'Invite note (optional)' : 'Message text' }}
                        </label>
                        <FlowMessageAiHelp
                            :channel="step.channel"
                            :action="step.action"
                            field="message"
                            :current-text="(step.config?.message as string) ?? ''"
                            @apply="emit('updateConfig', 'message', $event)"
                        />
                    </div>
                    <textarea
                        :value="(step.config?.message as string) ?? ''"
                        rows="4"
                        placeholder="Use {{firstName}}, {{lastName}}, {{company}}, {{position}}"
                        class="w-full resize-none rounded-lg border border-border bg-background px-3 py-2 text-xs outline-none focus:ring-1 focus:ring-blue-400/30"
                        @input="emit('updateConfig', 'message', ($event.target as HTMLTextAreaElement).value)"
                    />
                </div>
            </template>

            <template v-if="step.action === 'send_email'">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Subject</label>
                        <FlowMessageAiHelp
                            :channel="step.channel"
                            action="send_email"
                            field="subject"
                            :current-text="(step.config?.subject as string) ?? ''"
                            @apply="emit('updateConfig', 'subject', $event)"
                        />
                    </div>
                    <input
                        :value="(step.config?.subject as string) ?? ''"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs"
                        @input="emit('updateConfig', 'subject', ($event.target as HTMLInputElement).value)"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Body</label>
                        <FlowMessageAiHelp
                            :channel="step.channel"
                            action="send_email"
                            field="body"
                            :current-text="(step.config?.body as string) ?? ''"
                            :email-subject="(step.config?.subject as string) ?? ''"
                            @apply="emit('updateConfig', 'body', $event)"
                        />
                    </div>
                    <textarea
                        :value="(step.config?.body as string) ?? ''"
                        rows="4"
                        class="w-full resize-none rounded-lg border border-border bg-background px-3 py-2 text-xs"
                        @input="emit('updateConfig', 'body', ($event.target as HTMLTextAreaElement).value)"
                    />
                </div>
            </template>

            <template v-if="step.action === 'endorse'">
                <div class="flex items-center gap-3">
                    <label class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Skills to endorse</label>
                    <input
                        type="number"
                        :value="(step.config?.skills as number) ?? 3"
                        min="1"
                        max="10"
                        class="w-16 rounded-lg border border-border bg-background px-2 py-1 text-sm text-center"
                        @input="emit('updateConfig', 'skills', +(($event.target as HTMLInputElement).value))"
                    />
                </div>
            </template>
        </template>

        <template v-if="isCondition">
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Condition</label>
                <select
                    :value="step.condition ?? ''"
                    class="rounded-lg border border-orange-200 bg-white px-2 py-1.5 text-xs"
                    @change="emit('updateField', 'condition', ($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="opt in conditionOptions" :key="opt.key" :value="opt.key">{{ opt.label }}</option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                    Wait up to (days)
                </label>
                <input
                    type="number"
                    :value="(step.config?.timeout_days as number) ?? defaultTimeoutDays"
                    min="1"
                    max="90"
                    class="w-20 rounded-lg border border-orange-200 px-2 py-1 text-xs text-center"
                    @change="emit('updateConfig', 'timeout_days', +(($event.target as HTMLInputElement).value))"
                />
                <p class="text-[10px] text-muted-foreground">
                    Resolves automatically after this many days if the condition is not met.
                </p>
            </div>
            <p class="text-xs text-muted-foreground">
                Add steps to the Yes / No branches using the platform panel.
            </p>
        </template>
    </div>
</template>
