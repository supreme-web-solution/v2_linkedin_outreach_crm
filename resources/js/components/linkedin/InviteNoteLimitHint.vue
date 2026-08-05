<script setup lang="ts">
import { computed } from 'vue';
import { Info } from '@lucide/vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

const props = withDefaults(defineProps<{
    /** invite = noted-invite tip; action = general LinkedIn pacing for any step */
    variant?: 'invite' | 'action';
    side?: 'top' | 'right' | 'bottom' | 'left';
    align?: 'start' | 'center' | 'end';
}>(), {
    variant: 'invite',
    side: 'top',
    align: 'start',
});

const shortLabel = computed(() =>
    props.variant === 'invite'
        ? 'About 5 noted invites/day — extras queue automatically.'
        : 'LinkedIn may pause this step — we queue and retry automatically.',
);

const tooltipText = computed(() =>
    props.variant === 'invite'
        ? 'LinkedIn usually allows about 5 connection invites with a personal note each day. Anyone beyond that stays in queue and goes out automatically when LinkedIn lets you send more. Leaving the note blank often lets you reach more people sooner.'
        : 'LinkedIn limits how fast invites, messages, profile views, endorsements, and likes can run. When that happens we pause and retry later — that is LinkedIn’s rule.',
);

const ariaLabel = computed(() =>
    props.variant === 'invite' ? 'About LinkedIn invite limits' : 'About LinkedIn action limits',
);
</script>

<template>
    <div class="flex items-start gap-1.5 text-[10px] leading-snug text-muted-foreground">
        <Tooltip :delay-duration="150">
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="mt-px inline-flex shrink-0 rounded-md p-0.5 text-amber-600 transition hover:bg-amber-50 hover:text-amber-700"
                    :aria-label="ariaLabel"
                >
                    <Info class="h-3.5 w-3.5" />
                </button>
            </TooltipTrigger>
            <TooltipContent :side="side" :align="align" class="max-w-[16rem] text-xs leading-relaxed">
                {{ tooltipText }}
            </TooltipContent>
        </Tooltip>
        <span>{{ shortLabel }}</span>
    </div>
</template>
