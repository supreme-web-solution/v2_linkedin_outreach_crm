<script setup lang="ts">
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { Trash2 } from '@lucide/vue';
import { computed, inject } from 'vue';
import CampaignActionIcon from '@/components/campaign/CampaignActionIcon.vue';
import CampaignNodeAddMenu from '@/components/campaign/CampaignNodeAddMenu.vue';
import { actionMeta } from '@/components/campaign/types';
import type { CampaignFlowNodeData } from '@/components/campaign/flowAdapter';

const props = defineProps<NodeProps<CampaignFlowNodeData>>();

const flowActions = inject<{ select: (key: number) => void; delete: (key: number) => void }>('campaignFlowActions');

const meta = computed(() => actionMeta(props.data.step.value));
const step = computed(() => props.data.step);
</script>

<template>
    <Handle type="target" :position="Position.Top" class="!border-slate-300 !bg-white" />

    <div
        class="group relative flex min-w-[260px] cursor-pointer items-center gap-3 rounded-2xl border bg-white px-4 py-3.5 shadow-[0_8px_24px_rgba(15,23,42,0.06)] transition-all hover:shadow-[0_12px_28px_rgba(15,23,42,0.1)]"
        :class="props.selected ? 'ring-2' : ''"
        :style="{
            borderColor: props.selected ? meta.accent : '#e2e8f0',
            boxShadow: props.selected ? `0 0 0 1px ${meta.accent}22, 0 12px 28px rgba(15,23,42,0.1)` : undefined,
            '--tw-ring-color': meta.ring,
        }"
        @click.stop="flowActions?.select(step.key)"
    >
        <div
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border"
            :style="{ background: meta.light, borderColor: meta.border, color: meta.accent }"
        >
            <CampaignActionIcon :value="step.value" :size="18" />
        </div>

        <div class="min-w-0 flex-1">
            <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Action</div>
            <div class="text-sm font-semibold text-slate-900">{{ step.label }}</div>
            <div v-if="step.config?.message" class="max-w-[170px] truncate text-[11px] text-slate-500">
                {{ (step.config.message as string) || 'No message set' }}
            </div>
            <div v-else-if="step.value === 'endorse'" class="text-[11px] text-slate-500">
                Endorse {{ step.config?.skills ?? 3 }} skills
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
        <CampaignNodeAddMenu :menu-id="`after-${step.key}`" :after-key="step.key" />
    </div>
</template>
