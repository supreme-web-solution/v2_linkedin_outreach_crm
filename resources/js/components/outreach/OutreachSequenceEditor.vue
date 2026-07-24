<script setup lang="ts">
import { Clock, GitBranch, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { nextStepKey, type ConnectedChannel, type OutreachChannel, type OutreachStep } from '@/components/outreach/types';

const props = defineProps<{
    steps: OutreachStep[];
    channelRegistry: {
        channels: Record<string, { label: string; color: string }>;
        actions: Record<string, Array<{ key: string; label: string }>>;
    };
    connectedChannels: ConnectedChannel[];
}>();

const emit = defineEmits<{ stepsChanged: [steps: OutreachStep[]] }>();

const showAddMenu = ref(false);
const pickChannel = ref<OutreachChannel | null>(null);

function isConnected(channel: string) {
    return props.connectedChannels.some((c) => c.channel === channel && c.connected);
}

function connectedChannelKeys(): OutreachChannel[] {
    return props.connectedChannels.filter((c) => c.connected).map((c) => c.channel);
}

function push(next: OutreachStep[]) {
    emit('stepsChanged', next);
}

function removeAt(index: number) {
    const next = [...props.steps];
    next.splice(index, 1);
    push(next.length ? next : [{ key: 99, type: 'end', label: 'End' }]);
}

function addAction(channel: OutreachChannel, actionKey: string, actionLabel: string) {
    const endIdx = props.steps.findIndex((s) => s.type === 'end');
    const key = nextStepKey(props.steps);
    const step: OutreachStep = {
        key,
        type: 'action',
        channel,
        action: actionKey,
        label: actionLabel,
        config: actionKey === 'send_invite' || actionKey === 'send_message'
            ? { message: '' }
            : actionKey === 'send_email'
                ? { subject: '', body: '' }
                : {},
    };

    const next = [...props.steps];
    if (endIdx >= 0) {
        next.splice(endIdx, 0, step);
    } else {
        next.push(step, { key: 99, type: 'end', label: 'End' });
    }

    push(next);
    showAddMenu.value = false;
    pickChannel.value = null;
}

function addWait() {
    const endIdx = props.steps.findIndex((s) => s.type === 'end');
    const step: OutreachStep = { key: nextStepKey(props.steps), type: 'delay', value: 1, time: 'days', label: 'Wait 1 day' };
    const next = [...props.steps];
    if (endIdx >= 0) next.splice(endIdx, 0, step);
    else next.push(step, { key: 99, type: 'end', label: 'End' });
    push(next);
    showAddMenu.value = false;
}

function updateStep(index: number, patch: Partial<OutreachStep>) {
    const next = props.steps.map((s, i) => (i === index ? { ...s, ...patch } : s));
    push(next);
}

const displaySteps = () => props.steps.filter((s) => s.type !== 'end');
</script>

<template>
    <div class="flex flex-col gap-2 p-4">
        <div
            v-for="(step, index) in displaySteps()"
            :key="step.key"
            class="rounded-xl border bg-card p-3 shadow-sm"
            :class="step.type === 'delay' ? 'border-amber-200' : step.type === 'condition' ? 'border-orange-200' : 'border-border'"
        >
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                    <Clock v-if="step.type === 'delay'" class="h-4 w-4 text-amber-600" />
                    <GitBranch v-else-if="step.type === 'condition'" class="h-4 w-4 text-orange-600" />
                    <OutreachChannelIcon v-else :channel="step.channel" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span v-if="step.channel" class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ channelRegistry.channels[step.channel]?.label ?? step.channel }}
                        </span>
                        <span class="text-sm font-medium">{{ step.label }}</span>
                    </div>

                    <div v-if="step.type === 'delay'" class="mt-2 flex items-center gap-2">
                        <input
                            type="number"
                            min="1"
                            class="w-16 rounded border px-2 py-1 text-xs"
                            :value="step.value"
                            @change="updateStep(index, { value: +(($event.target as HTMLInputElement).value) })"
                        />
                        <select
                            class="rounded border px-2 py-1 text-xs"
                            :value="step.time"
                            @change="updateStep(index, { time: ($event.target as HTMLSelectElement).value as 'hours' | 'days' })"
                        >
                            <option value="hours">hours</option>
                            <option value="days">days</option>
                        </select>
                    </div>

                    <textarea
                        v-if="step.action === 'send_invite' || step.action === 'send_message'"
                        class="mt-2 w-full rounded-lg border px-2 py-1.5 text-xs"
                        rows="2"
                        placeholder="Message…"
                        :value="(step.config?.message as string) ?? ''"
                        @input="updateStep(index, { config: { ...step.config, message: ($event.target as HTMLTextAreaElement).value } })"
                    />

                    <template v-if="step.action === 'send_email'">
                        <input
                            class="mt-2 w-full rounded-lg border px-2 py-1.5 text-xs"
                            placeholder="Subject"
                            :value="(step.config?.subject as string) ?? ''"
                            @input="updateStep(index, { config: { ...step.config, subject: ($event.target as HTMLInputElement).value } })"
                        />
                        <textarea
                            class="mt-2 w-full rounded-lg border px-2 py-1.5 text-xs"
                            rows="3"
                            placeholder="Email body…"
                            :value="(step.config?.body as string) ?? ''"
                            @input="updateStep(index, { config: { ...step.config, body: ($event.target as HTMLTextAreaElement).value } })"
                        />
                    </template>
                </div>
                <button type="button" class="text-muted-foreground hover:text-red-500" @click="removeAt(index)">
                    <Trash2 class="h-4 w-4" />
                </button>
            </div>
        </div>

        <div class="relative pt-2">
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-lg border border-dashed border-border px-4 py-2 text-sm font-medium hover:bg-muted"
                @click="showAddMenu = !showAddMenu"
            >
                <Plus class="h-4 w-4" /> Add step
            </button>

            <div
                v-if="showAddMenu"
                class="absolute left-0 top-full z-20 mt-2 w-64 rounded-xl border border-border bg-card p-2 shadow-lg"
            >
                <template v-if="!pickChannel">
                    <p class="px-2 py-1 text-[10px] font-semibold uppercase text-muted-foreground">Channel</p>
                    <button
                        v-for="ch in connectedChannelKeys()"
                        :key="ch"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-sm hover:bg-muted"
                        @click="pickChannel = ch"
                    >
                        <OutreachChannelIcon :channel="ch" class="h-4 w-4" />
                        {{ channelRegistry.channels[ch]?.label ?? ch }}
                    </button>
                    <p v-if="connectedChannelKeys().length === 0" class="px-2 py-2 text-xs text-amber-700">
                        Connect channels on Integrations first.
                    </p>
                    <div class="my-1 border-t border-border" />
                    <button type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-sm hover:bg-amber-50" @click="addWait">
                        <Clock class="h-4 w-4 text-amber-600" /> Wait / Delay
                    </button>
                </template>
                <template v-else>
                    <button type="button" class="mb-1 px-2 text-xs text-muted-foreground hover:underline" @click="pickChannel = null">← Back</button>
                    <p class="px-2 py-1 text-[10px] font-semibold uppercase text-muted-foreground">Action</p>
                    <button
                        v-for="action in channelRegistry.actions[pickChannel] ?? []"
                        :key="action.key"
                        type="button"
                        class="flex w-full rounded-lg px-2 py-2 text-left text-sm hover:bg-muted"
                        @click="addAction(pickChannel!, action.key, action.label)"
                    >
                        {{ action.label }}
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
