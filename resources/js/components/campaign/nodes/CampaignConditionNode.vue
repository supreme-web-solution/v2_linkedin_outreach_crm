<script setup lang="ts">
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { Check, GitBranch, X } from '@lucide/vue';
import { computed, inject } from 'vue';
import type { CampaignFlowNodeData } from '@/components/campaign/flowAdapter';

const props = defineProps<NodeProps<CampaignFlowNodeData>>();

const flowActions = inject<{ select: (key: number) => void; delete: (key: number) => void }>('campaignFlowActions');

const step = computed(() => props.data.step);
</script>

<template>
    <Handle type="target" :position="Position.Top" class="!border-orange-300 !bg-white" />

    <div
        class="flex min-w-[260px] cursor-pointer items-center gap-3 rounded-2xl border border-orange-200 bg-gradient-to-br from-orange-50 via-white to-amber-50 px-4 py-3.5 shadow-[0_8px_24px_rgba(249,115,22,0.1)]"
        :class="props.selected ? 'ring-2 ring-orange-400/50' : ''"
        @click.stop="flowActions?.select(step.key)"
    >
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-orange-200 bg-white text-orange-600">
            <GitBranch class="h-4 w-4" stroke-width="2" />
        </div>
        <div>
            <div class="text-[10px] font-semibold uppercase tracking-wide text-orange-600/80">Condition</div>
            <div class="text-sm font-semibold text-orange-950">Invite Accepted?</div>
        </div>
    </div>

    <div class="pointer-events-none absolute -left-[4.5rem] top-1/2 flex -translate-y-1/2 items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700 shadow-sm">
        <Check class="h-3 w-3" />
        Accepted
    </div>
    <div class="pointer-events-none absolute -right-[5.5rem] top-1/2 flex -translate-y-1/2 items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-semibold text-slate-600 shadow-sm">
        <X class="h-3 w-3" />
        Not accepted
    </div>

    <Handle id="accepted" type="source" :position="Position.Left" class="!border-emerald-400 !bg-white" />
    <Handle id="not_accepted" type="source" :position="Position.Right" class="!border-slate-400 !bg-white" />
    <Handle id="main" type="source" :position="Position.Bottom" class="!border-orange-300 !bg-white" />
</template>
