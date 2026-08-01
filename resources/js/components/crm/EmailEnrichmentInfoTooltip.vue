<script setup lang="ts">
import { Info } from '@lucide/vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

withDefaults(defineProps<{
    side?: 'top' | 'right' | 'bottom' | 'left';
    align?: 'start' | 'center' | 'end';
}>(), {
    side: 'top',
    align: 'start',
});
</script>

<template>
    <Tooltip :delay-duration="200">
        <TooltipTrigger as-child>
            <button
                type="button"
                class="inline-flex rounded-md p-0.5 text-muted-foreground transition hover:bg-muted/60 hover:text-foreground"
                aria-label="About contact enrichment"
            >
                <Info class="h-4 w-4" />
            </button>
        </TooltipTrigger>
        <TooltipContent :side="side" :align="align" class="max-w-[18rem] text-xs leading-relaxed">
            We search the LinkedIn profile for work email, phone, and social links first.
            If anything is still missing, additional verified lookups run automatically until we find a match or exhaust available sources.
        </TooltipContent>
    </Tooltip>
</template>
