<script setup lang="ts">
import { nextTick, watch } from 'vue';
import { useNodesInitialized, useVueFlow } from '@vue-flow/core';
import { FLOW_CANVAS_FIT_VIEW } from '@/components/flow/flowCanvasConfig';

const { fitView } = useVueFlow();
const nodesInitialized = useNodesInitialized();
let hasFitted = false;

watch(
    nodesInitialized,
    async (ready) => {
        if (!ready || hasFitted) return;
        hasFitted = true;
        await nextTick();
        await fitView({ ...FLOW_CANVAS_FIT_VIEW });
    },
    { immediate: true },
);
</script>

<template />
