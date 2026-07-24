<script setup lang="ts">
import { BaseEdge, EdgeLabelRenderer, getSmoothStepPath, type EdgeProps } from '@vue-flow/core';
import { X } from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import type { FlowEdgeData } from '@/components/flow/flowEdgeUtils';

const props = defineProps<EdgeProps<FlowEdgeData>>();

const disconnect = inject<(targetKey: number) => void>('flowDisconnectEdge');
const hovered = ref(false);

const path = computed(() =>
    getSmoothStepPath({
        sourceX: props.sourceX,
        sourceY: props.sourceY,
        targetX: props.targetX,
        targetY: props.targetY,
        sourcePosition: props.sourcePosition,
        targetPosition: props.targetPosition,
    }),
);

const showDelete = computed(() => props.data?.deletable !== false && props.data?.targetKey != null);

function onDisconnect() {
    if (props.data?.targetKey != null) {
        disconnect?.(props.data.targetKey);
    }
}
</script>

<template>
    <g @mouseenter="hovered = true" @mouseleave="hovered = false">
        <BaseEdge :id="props.id" :path="path[0]" :style="props.style" :marker-end="props.markerEnd" :interaction-width="24" />
    </g>

    <EdgeLabelRenderer v-if="showDelete && hovered">
        <div
            class="nodrag nopan pointer-events-auto absolute"
            :style="{
                transform: `translate(-50%, -50%) translate(${path[1]}px, ${path[2]}px)`,
            }"
        >
            <button
                type="button"
                class="flex h-5 w-5 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:border-red-200 hover:bg-red-50 hover:text-red-500"
                title="Disconnect — removes this step from the flow"
                @click.stop="onDisconnect"
            >
                <X class="h-3 w-3" />
            </button>
        </div>
    </EdgeLabelRenderer>
</template>
