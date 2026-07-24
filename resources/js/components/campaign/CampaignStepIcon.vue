<script setup lang="ts">
import { computed, type Component } from 'vue';
import { Circle, Clock, GitBranch } from '@lucide/vue';
import CampaignActionIcon from '@/components/campaign/CampaignActionIcon.vue';
import type { CampaignStep } from '@/components/campaign/types';

const props = withDefaults(defineProps<{
    step: Pick<CampaignStep, 'type' | 'value' | 'label'>;
    size?: number;
    class?: string;
}>(), {
    size: 14,
});

const stepIcon = computed<Component | null>(() => {
    if (props.step.type === 'delay') return Clock;
    if (props.step.type === 'condition') return GitBranch;
    if (props.step.type === 'action') return null;

    return Circle;
});
</script>

<template>
    <CampaignActionIcon
        v-if="step.type === 'action'"
        :value="step.value"
        :size="size"
        :class="class"
    />
    <component
        v-else-if="stepIcon"
        :is="stepIcon"
        :size="size"
        :class="class"
        stroke-width="2"
    />
</template>
