<script setup lang="ts">
import { ref } from 'vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

const props = defineProps<{
    text: string;
    labelClass?: string;
}>();

const showTooltip = ref(false);
const labelRef = ref<HTMLElement | null>(null);

function updateTruncation() {
    const el = labelRef.value;
    showTooltip.value = el ? el.scrollWidth > el.clientWidth + 1 : false;
}
</script>

<template>
    <Tooltip :disabled="!showTooltip" :delay-duration="200">
        <TooltipTrigger as-child>
            <span
                ref="labelRef"
                :class="['min-w-0 truncate', props.labelClass]"
                @mouseenter="updateTruncation"
            >
                {{ text }}
            </span>
        </TooltipTrigger>
        <TooltipContent side="right" align="center" class="max-w-xs text-xs">
            {{ text }}
        </TooltipContent>
    </Tooltip>
</template>
