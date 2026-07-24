<script setup lang="ts">
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { Trash2 } from '@lucide/vue';
import { computed, inject } from 'vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import OutreachNodeAddMenu from '@/components/outreach/OutreachNodeAddMenu.vue';
import type { OutreachFlowNodeData } from '@/components/outreach/outreachFlowAdapter';
import type { OutreachChannel } from '@/components/outreach/types';

const props = defineProps<NodeProps<OutreachFlowNodeData>>();

const flowActions = inject<{ select: (key: number) => void; delete: (key: number) => void }>('outreachFlowActions');
const channelRegistry = inject<{ channels: Record<string, { label: string; color: string }> }>('outreachChannelRegistry');

const step = computed(() => props.data.step);
const channelColor = computed(() => {
    const ch = step.value.channel;
    return ch ? (channelRegistry?.channels[ch]?.color ?? '#64748b') : '#64748b';
});
const channelLabel = computed(() => {
    const ch = step.value.channel;
    return ch ? (channelRegistry?.channels[ch]?.label ?? ch) : '';
});
</script>

<template>
    <Handle type="target" :position="Position.Top" class="!border-slate-300 !bg-white" />

    <div
        class="group relative flex min-w-[260px] cursor-grab items-center gap-3 rounded-2xl border bg-white px-4 py-3.5 shadow-[0_8px_24px_rgba(15,23,42,0.06)] transition-all hover:shadow-[0_12px_28px_rgba(15,23,42,0.1)] active:cursor-grabbing"
        :class="props.selected ? 'ring-2' : ''"
        :style="{
            borderColor: props.selected ? channelColor : '#e2e8f0',
            boxShadow: props.selected ? `0 0 0 1px ${channelColor}22, 0 12px 28px rgba(15,23,42,0.1)` : undefined,
            '--tw-ring-color': `${channelColor}55`,
        }"
        @click.stop="flowActions?.select(step.key)"
    >
        <div
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border"
            :style="{ background: `${channelColor}14`, borderColor: `${channelColor}33`, color: channelColor }"
        >
            <OutreachChannelIcon v-if="step.channel" :channel="step.channel as OutreachChannel" class="h-5 w-5" />
        </div>

        <div class="min-w-0 flex-1">
            <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ channelLabel }}</div>
            <div class="text-sm font-semibold text-slate-900">{{ step.label }}</div>
            <div v-if="step.config?.message" class="max-w-[170px] truncate text-[11px] text-slate-500">
                {{ (step.config.message as string) || 'No message set' }}
            </div>
            <div v-else-if="step.config?.subject" class="max-w-[170px] truncate text-[11px] text-slate-500">
                {{ (step.config.subject as string) || 'No subject set' }}
            </div>
        </div>

        <button
            type="button"
            class="rounded-lg p-1.5 text-slate-400 opacity-0 transition-opacity group-hover:opacity-100 hover:bg-red-50 hover:text-red-500"
            @click.stop="flowActions?.delete(step.key)"
        >
            <Trash2 class="h-3.5 w-3.5" />
        </button>
    </div>

    <Handle id="main" type="source" :position="Position.Bottom" class="!border-slate-300 !bg-white" />

    <div class="absolute left-1/2 top-full mt-2 -translate-x-1/2">
        <OutreachNodeAddMenu :menu-id="`after-${step.key}`" :after-key="step.key" />
    </div>
</template>
