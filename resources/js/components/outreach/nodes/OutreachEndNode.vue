<script setup lang="ts">
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { CircleStop } from '@lucide/vue';
import type { OutreachFlowNodeData } from '@/components/outreach/outreachFlowAdapter';

defineProps<NodeProps<OutreachFlowNodeData>>();
</script>

<template>
    <Handle type="target" :position="Position.Top" class="!border-slate-300 !bg-white" />

    <div class="flex min-w-[170px] cursor-grab items-center gap-2.5 rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-600 shadow-sm active:cursor-grabbing">
        <CircleStop class="h-3.5 w-3.5 text-slate-400" stroke-width="2" />
        End sequence
    </div>
</template>
