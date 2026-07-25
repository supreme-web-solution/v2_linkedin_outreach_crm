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
                aria-label="About email enrichment"
            >
                <Info class="h-4 w-4" />
            </button>
        </TooltipTrigger>
        <TooltipContent :side="side" :align="align" class="max-w-[18rem] text-xs leading-relaxed">
            Email lookup follows LinkedIn’s rules. We can only retrieve an address when the member has chosen to share it in their Contact Info — usually with connections.
            If they haven’t shared it, no email is returned. This is not guessing or third-party data.
        </TooltipContent>
    </Tooltip>
</template>
