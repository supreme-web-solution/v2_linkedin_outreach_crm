<script setup lang="ts">
import CampaignStepIcon from '@/components/campaign/CampaignStepIcon.vue';
import { stepChipLabel, type CampaignStep } from '@/components/campaign/types';

defineProps<{
    step: CampaignStep;
    badgeClass?: string;
}>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-medium"
        :class="badgeClass"
    >
        <CampaignStepIcon :step="step" :size="12" class="shrink-0" />
        {{ stepChipLabel(step) }}
    </span>
</template>
