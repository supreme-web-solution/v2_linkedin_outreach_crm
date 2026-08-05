<script setup lang="ts">
import { computed } from 'vue';
import { Info } from '@lucide/vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

const props = withDefaults(defineProps<{
    channel?: string;
    /** invite = LinkedIn noted-invite tip; action = general platform pacing */
    variant?: 'invite' | 'action';
    side?: 'top' | 'right' | 'bottom' | 'left';
    align?: 'start' | 'center' | 'end';
}>(), {
    channel: 'linkedin',
    variant: 'action',
    side: 'top',
    align: 'start',
});

const platformLabel = computed(() => {
    switch (props.channel) {
        case 'whatsapp': return 'WhatsApp';
        case 'instagram': return 'Instagram';
        case 'telegram': return 'Telegram';
        case 'twitter': return 'X';
        case 'email': return 'Email';
        default: return 'LinkedIn';
    }
});

const shortLabel = computed(() => {
    if (props.variant === 'invite') {
        return 'About 5 noted invites/day — extras queue automatically.';
    }
    return `${platformLabel.value} may pause sending — we queue and retry automatically.`;
});

const tooltipText = computed(() => {
    if (props.variant === 'invite') {
        return 'LinkedIn usually allows about 5 connection invites with a personal note each day. Anyone beyond that stays in queue and goes out automatically when LinkedIn lets you send more. Leaving the note blank often lets you reach more people sooner.';
    }
    return `${platformLabel.value} limits how fast messages and actions can go out. When that happens we pause and retry later — that is ${platformLabel.value}’s rule.`;
});

const ariaLabel = computed(() => `About ${platformLabel.value} send limits`);
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
