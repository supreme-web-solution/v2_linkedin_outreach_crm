<script setup lang="ts">
import EmailEnrichmentInfoTooltip from '@/components/crm/EmailEnrichmentInfoTooltip.vue';
import type { DailyEnrichmentQuota } from '@/composables/useDailyEnrichmentQuota';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        quota: DailyEnrichmentQuota;
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const atLimit = computed(() => !props.quota.can_scrape);

const barClass = computed(() => {
    if (atLimit.value) {
        return 'from-red-500 to-red-600';
    }
    if (props.quota.percent >= 85) {
        return 'from-amber-500 to-amber-600';
    }

    return 'from-blue-500 to-blue-600';
});

const subtitle = computed(() => {
    if (atLimit.value) {
        return 'Limit reached';
    }
    if (props.quota.in_flight > 0) {
        return `${props.quota.in_flight} running · ${props.quota.effective_remaining} left`;
    }

    return `${props.quota.remaining} left`;
});
</script>

<template>
    <!-- Navbar: flush with header, no card chrome -->
    <div
        v-if="compact"
        class="hidden min-w-[152px] max-w-[168px] shrink-0 flex-col justify-center border-l border-border/40 pl-3 lg:flex"
        title="Daily contact enrichment quota"
    >
        <div class="flex items-center justify-between gap-2">
            <span class="inline-flex items-center gap-0.5 text-[11px] font-medium text-muted-foreground">
                Enrichment
                <EmailEnrichmentInfoTooltip side="bottom" align="end" />
            </span>
            <span
                class="text-[11px] font-semibold tabular-nums leading-none"
                :class="atLimit ? 'text-red-600 dark:text-red-400' : 'text-foreground/80'"
            >
                {{ quota.used }}/{{ quota.daily_limit }}
            </span>
        </div>

        <div class="mt-1 h-1 overflow-hidden rounded-full bg-black/[0.06] dark:bg-white/[0.08]">
            <div
                class="h-full rounded-full bg-gradient-to-r transition-all duration-300"
                :class="barClass"
                :style="{ width: `${Math.max(atLimit ? 100 : 2, quota.percent)}%` }"
            />
        </div>

        <p class="mt-0.5 truncate text-[10px] leading-none text-muted-foreground/80">
            {{ subtitle }}
        </p>
    </div>

    <!-- Standalone card (if reused on a page later) -->
    <div v-else class="min-w-[220px] rounded-lg border border-border bg-card px-3 py-2.5 shadow-sm">
        <div class="mb-1.5 flex items-center justify-between gap-2">
            <span class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground">
                Daily enrichment
                <EmailEnrichmentInfoTooltip side="bottom" align="end" />
            </span>
            <span
                class="text-xs font-semibold tabular-nums"
                :class="atLimit ? 'text-red-600 dark:text-red-400' : 'text-foreground'"
            >
                {{ quota.used }}/{{ quota.daily_limit }}
            </span>
        </div>

        <div class="h-2 overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full bg-gradient-to-r transition-all duration-300"
                :class="barClass"
                :style="{ width: `${Math.max(atLimit ? 100 : 2, quota.percent)}%` }"
            />
        </div>

        <p class="mt-1 text-[10px] text-muted-foreground">
            {{ subtitle }}
        </p>
    </div>
</template>
