<script setup lang="ts">
import { computed } from 'vue';
import { Info } from '@lucide/vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

export type ActionQuotaSnapshot = {
    limit: number;
    used: number;
    remaining: number;
    unlimited: boolean;
};

const props = withDefaults(defineProps<{
    /** invite = noted-invite tip; action = general LinkedIn pacing for any step */
    variant?: 'invite' | 'action';
    /** SociFusion daily invite/message cap from env. */
    quota?: ActionQuotaSnapshot | null;
    hasInviteNote?: boolean;
    side?: 'top' | 'right' | 'bottom' | 'left';
    align?: 'start' | 'center' | 'end';
}>(), {
    variant: 'invite',
    quota: null,
    hasInviteNote: false,
    side: 'top',
    align: 'start',
});

const shortLabel = computed(() => {
    if (props.variant === 'invite') {
        if (props.quota && !props.quota.unlimited && props.quota.limit > 0) {
            const noteBit = props.hasInviteNote
                ? ' · ~5/day with a note (LinkedIn)'
                : ' · ~5/day if you add a note';
            return `Up to ${props.quota.limit}/day (${props.quota.remaining} left)${noteBit} — extras wait until tomorrow, not lost.`;
        }
        return 'About 5 noted invites/day — extras queue automatically.';
    }
    if (props.quota && !props.quota.unlimited && props.quota.limit > 0) {
        return `Up to ${props.quota.limit}/day (${props.quota.remaining} left) — extras wait until tomorrow, not lost.`;
    }
    return 'LinkedIn may pause this step — we queue and retry automatically.';
});

const tooltipText = computed(() => {
    if (props.variant === 'invite') {
        const parts: string[] = [];
        if (props.quota && !props.quota.unlimited && props.quota.limit > 0) {
            parts.push(
                `SociFusion caps connection invites at ${props.quota.limit}/day (${props.quota.used} used, ${props.quota.remaining} left today). Anyone past that cap stays in the sequence and continues automatically the next day — they are not dropped.`,
            );
        } else {
            parts.push('Anyone past today’s invite limit stays queued and continues automatically later — not dropped.');
        }
        parts.push(
            'Separately, LinkedIn usually allows about 5 connection invites with a personal note each day. Leaving the note blank often lets you reach more people sooner under LinkedIn’s own rules.',
        );
        return parts.join(' ');
    }
    if (props.quota && !props.quota.unlimited && props.quota.limit > 0) {
        return `SociFusion caps LinkedIn messages at ${props.quota.limit}/day (${props.quota.used} used, ${props.quota.remaining} left). Anyone past the cap stays queued for the next day — not lost. LinkedIn may also pause sending; we retry when it’s safe.`;
    }
    return 'LinkedIn limits how fast invites, messages, profile views, endorsements, and likes can run. When that happens we pause and retry later — that is LinkedIn’s rule.';
});

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
            <TooltipContent :side="side" :align="align" class="max-w-[18rem] text-xs leading-relaxed">
                {{ tooltipText }}
            </TooltipContent>
        </Tooltip>
        <span>{{ shortLabel }}</span>
    </div>
</template>
