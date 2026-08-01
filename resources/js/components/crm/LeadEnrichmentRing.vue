<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        percent: number;
        size?: number;
        stroke?: number;
    }>(),
    {
        size: 44,
        stroke: 5,
    },
);

const radius = computed(() => (props.size - props.stroke) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);
const dashOffset = computed(() => circumference.value * (1 - Math.min(100, Math.max(0, props.percent)) / 100));
</script>

<template>
    <svg :width="size" :height="size" class="-rotate-90 shrink-0" aria-hidden="true">
        <circle
            :cx="size / 2"
            :cy="size / 2"
            :r="radius"
            fill="none"
            class="stroke-muted/60"
            :stroke-width="stroke"
        />
        <circle
            :cx="size / 2"
            :cy="size / 2"
            :r="radius"
            fill="none"
            class="stroke-primary transition-all duration-500"
            :stroke-width="stroke"
            stroke-linecap="round"
            :stroke-dasharray="circumference"
            :stroke-dashoffset="dashOffset"
        />
    </svg>
</template>
