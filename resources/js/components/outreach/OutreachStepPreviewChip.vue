<script setup lang="ts">
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { stepChipLabel, type OutreachStep } from '@/components/outreach/types';
import { Clock, GitBranch } from '@lucide/vue';

defineProps<{
    step: OutreachStep;
    badgeClass?: string;
}>();
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-lg border bg-white px-2.5 py-1 text-[10px] font-medium shadow-sm" :class="badgeClass">
        <Clock v-if="step.type === 'delay'" class="h-3 w-3 shrink-0" />
        <GitBranch v-else-if="step.type === 'condition'" class="h-3 w-3 shrink-0" />
        <OutreachChannelIcon v-else-if="step.channel" :channel="step.channel" class="h-3 w-3 shrink-0" />
        {{ stepChipLabel(step) }}
    </span>
</template>
