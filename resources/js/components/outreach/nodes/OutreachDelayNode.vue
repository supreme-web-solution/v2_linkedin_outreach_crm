<script setup lang="ts">
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { Clock, Trash2 } from '@lucide/vue';
import { computed, inject } from 'vue';
import type { OutreachFlowNodeData } from '@/components/outreach/outreachFlowAdapter';
import OutreachNodeAddMenu from '@/components/outreach/OutreachNodeAddMenu.vue';

const props = defineProps<NodeProps<OutreachFlowNodeData>>();

const flowActions = inject<{ select: (key: number) => void; delete: (key: number) => void }>('outreachFlowActions');

const step = computed(() => props.data.step);
</script>

<template>
    <Handle type="target" :position="Position.Top" class="!border-amber-300 !bg-white" />

    <div
        class="group relative flex min-w-[220px] cursor-grab items-center gap-3 rounded-2xl border border-amber-200/80 bg-gradient-to-r from-amber-50 to-orange-50 px-4 py-3 shadow-[0_6px_18px_rgba(245,158,11,0.12)] transition-all hover:shadow-[0_10px_24px_rgba(245,158,11,0.16)] active:cursor-grabbing"
        :class="props.selected ? 'ring-2 ring-amber-400/50' : ''"
        @click.stop="flowActions?.select(step.key)"
    >
        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-amber-200 bg-white text-amber-600">
            <Clock class="h-4 w-4" stroke-width="2" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="text-[10px] font-semibold uppercase tracking-wide text-amber-600/80">Delay</div>
            <div class="text-sm font-semibold text-amber-950">
                Wait {{ step.value }} {{ step.time }}
            </div>
        </div>
        <button
            type="button"
            class="rounded-lg p-1.5 text-amber-500 opacity-0 transition-opacity group-hover:opacity-100 hover:bg-red-50 hover:text-red-500"
            @click.stop="flowActions?.delete(step.key)"
        >
            <Trash2 class="h-3.5 w-3.5" />
        </button>
    </div>

    <Handle id="main" type="source" :position="Position.Bottom" class="!border-amber-300 !bg-white" />

    <div class="absolute left-1/2 top-full mt-2 -translate-x-1/2">
        <OutreachNodeAddMenu :menu-id="`after-${step.key}`" :after-key="step.key" />
    </div>
</template>
