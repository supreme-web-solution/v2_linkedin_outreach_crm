<script setup lang="ts">
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { Check, GitBranch, Trash2, X } from '@lucide/vue';
import { computed, inject } from 'vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import OutreachNodeAddMenu from '@/components/outreach/OutreachNodeAddMenu.vue';
import type { OutreachFlowNodeData } from '@/components/outreach/outreachFlowAdapter';
import type { OutreachChannel } from '@/components/outreach/types';

const props = defineProps<NodeProps<OutreachFlowNodeData>>();

const flowActions = inject<{ select: (key: number) => void; delete: (key: number) => void }>('outreachFlowActions');
const channelRegistry = inject<{ channels: Record<string, { label: string; color: string }> }>('outreachChannelRegistry');

const step = computed(() => props.data.step);
const channelLabel = computed(() => {
    const ch = step.value.channel;
    return ch ? (channelRegistry?.channels[ch]?.label ?? ch) : '';
});
</script>

<template>
    <Handle type="target" :position="Position.Top" class="!border-orange-300 !bg-white" />

    <div
        class="group relative flex min-w-[260px] cursor-grab items-center gap-3 rounded-2xl border border-orange-200 bg-gradient-to-br from-orange-50 via-white to-amber-50 px-4 py-3.5 shadow-[0_8px_24px_rgba(249,115,22,0.1)] active:cursor-grabbing"
        :class="props.selected ? 'ring-2 ring-orange-400/50' : ''"
        @click.stop="flowActions?.select(step.key)"
    >
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-orange-200 bg-white text-orange-600">
            <GitBranch class="h-4 w-4" stroke-width="2" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-orange-600/80">
                <OutreachChannelIcon v-if="step.channel" :channel="step.channel as OutreachChannel" class="h-3 w-3" />
                {{ channelLabel }} · Condition
            </div>
            <div class="text-sm font-semibold text-orange-950">{{ step.label }}</div>
        </div>
        <button
            type="button"
            class="rounded-lg p-1.5 text-orange-400 opacity-0 transition-opacity group-hover:opacity-100 hover:bg-red-50 hover:text-red-500"
            title="Remove condition and Yes/No steps"
            @click.stop="flowActions?.delete(step.key)"
        >
            <Trash2 class="h-3.5 w-3.5" />
        </button>
    </div>

    <div class="pointer-events-auto absolute -left-[4.5rem] top-1/2 flex -translate-y-1/2 flex-col items-center gap-1">
        <div class="flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700 shadow-sm">
            <Check class="h-3 w-3" />
            Yes
        </div>
        <OutreachNodeAddMenu
            :menu-id="`branch-${step.key}-yes`"
            :after-key="step.key"
            branch="accepted"
            :condition-key="step.key"
            align="left"
        />
    </div>
    <div class="pointer-events-auto absolute -right-[5.5rem] top-1/2 flex -translate-y-1/2 flex-col items-center gap-1">
        <div class="flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-semibold text-slate-600 shadow-sm">
            <X class="h-3 w-3" />
            No
        </div>
        <OutreachNodeAddMenu
            :menu-id="`branch-${step.key}-no`"
            :after-key="step.key"
            branch="not_accepted"
            :condition-key="step.key"
            align="right"
        />
    </div>

    <Handle id="accepted" type="source" :position="Position.Left" class="!border-emerald-400 !bg-white" />
    <Handle id="not_accepted" type="source" :position="Position.Right" class="!border-slate-400 !bg-white" />
    <Handle id="main" type="source" :position="Position.Bottom" class="!border-orange-300 !bg-white" />

    <div class="absolute left-1/2 top-full mt-2 -translate-x-1/2">
        <OutreachNodeAddMenu :menu-id="`after-${step.key}`" :after-key="step.key" />
    </div>
</template>
