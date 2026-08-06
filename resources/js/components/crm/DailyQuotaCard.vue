<script setup lang="ts">
import { computed } from 'vue';
import { ShieldCheck, Sparkles } from '@lucide/vue';
import EmailEnrichmentInfoTooltip from '@/components/crm/EmailEnrichmentInfoTooltip.vue';

export type DailyQuotaItem = {
    key: string;
    label: string;
    description: string;
    used: number;
    limit: number;
    remaining: number;
    unlimited: boolean;
    percent: number;
    at_limit: boolean;
};

const props = defineProps<{
    items: DailyQuotaItem[];
    resetsAt: string;
}>();

const resetsLabel = computed(() => {
    const date = new Date(props.resetsAt);
    if (Number.isNaN(date.getTime())) {
        return 'Resets at midnight';
    }

    return `Resets ${date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
});

const maxUsagePercent = computed(() => {
    const limited = props.items.filter((item) => !item.unlimited && item.limit > 0);
    if (limited.length === 0) {
        return 0;
    }

    return Math.max(...limited.map((item) => item.percent));
});

const accountHealth = computed(() => {
    const percent = maxUsagePercent.value;
    const anyAtLimit = props.items.some((item) => item.at_limit);

    if (anyAtLimit) {
        return {
            stars: 3,
            label: 'Capacity reached',
            risk: 'Elevated',
            badgeClass: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
            riskClass: 'text-amber-600 dark:text-amber-400',
        };
    }

    if (percent >= 85) {
        return {
            stars: 4,
            label: 'Active',
            risk: 'Moderate',
            badgeClass: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
            riskClass: 'text-amber-600 dark:text-amber-400',
        };
    }

    if (percent >= 50) {
        return {
            stars: 4,
            label: 'Good',
            risk: 'Low',
            badgeClass: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
            riskClass: 'text-emerald-600 dark:text-emerald-400',
        };
    }

    return {
        stars: 5,
        label: 'Excellent',
        risk: 'Low',
        badgeClass: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
        riskClass: 'text-emerald-600 dark:text-emerald-400',
    };
});

const healthStars = computed(() => '★'.repeat(accountHealth.value.stars) + '☆'.repeat(5 - accountHealth.value.stars));

function isLinkedInAction(item: DailyQuotaItem): boolean {
    return item.key !== 'email_enrichment';
}

function barClass(item: DailyQuotaItem): string {
    if (item.at_limit) {
        return 'from-amber-500 to-amber-600';
    }
    if (item.percent >= 85) {
        return 'from-amber-400 to-amber-500';
    }

    return 'from-blue-500 to-blue-600';
}

function safeLimitLabel(item: DailyQuotaItem): string {
    if (item.unlimited) {
        return `${item.used.toLocaleString()} today`;
    }

    return `${item.used.toLocaleString()} / ${item.limit.toLocaleString()}`;
}

function remainingLabel(item: DailyQuotaItem): string | null {
    if (item.unlimited) {
        return 'Unlimited safe capacity';
    }

    if (item.at_limit) {
        return 'Safe capacity reached for today';
    }

    return `${item.remaining.toLocaleString()} remaining in today's safe range`;
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
        <div class="border-b border-border/60 bg-muted/20 px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <ShieldCheck class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <h2 class="text-sm font-semibold">Account health</h2>
                    </div>
                    <p class="mt-1 text-xs leading-relaxed text-muted-foreground">
                        Today's safe capacity for LinkedIn actions. Limits adapt to protect your account from restrictions.
                        {{ resetsLabel }}.
                    </p>
                </div>

                <div
                    class="shrink-0 rounded-xl border px-3 py-2 text-right"
                    :class="accountHealth.badgeClass"
                >
                    <p class="text-[10px] font-semibold tracking-wide uppercase opacity-80">Health</p>
                    <p class="text-sm font-semibold leading-tight">{{ healthStars }}</p>
                    <p class="text-xs font-medium">{{ accountHealth.label }}</p>
                    <p class="mt-0.5 text-[11px]">
                        Risk:
                        <span class="font-semibold" :class="accountHealth.riskClass">{{ accountHealth.risk }}</span>
                    </p>
                </div>
            </div>
        </div>

        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <div
                v-for="item in items"
                :key="item.key"
                class="rounded-xl border border-border/60 bg-muted/10 p-4"
            >
                <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <span class="inline-flex items-center gap-1.5 text-sm font-medium">
                            {{ item.label }}
                            <EmailEnrichmentInfoTooltip
                                v-if="item.key === 'email_enrichment'"
                                side="right"
                            />
                        </span>
                        <p class="mt-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                            Today's safe limit
                        </p>
                    </div>
                    <p
                        class="text-right text-2xl font-bold tabular-nums leading-none"
                        :class="item.at_limit ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'"
                    >
                        {{ safeLimitLabel(item) }}
                    </p>
                </div>

                <div v-if="!item.unlimited" class="h-2.5 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-gradient-to-r transition-all duration-300"
                        :class="barClass(item)"
                        :style="{ width: `${Math.max(item.at_limit ? 100 : 2, item.percent)}%` }"
                    />
                </div>
                <div v-else class="h-2.5 overflow-hidden rounded-full bg-muted/60">
                    <div class="h-full w-full rounded-full bg-gradient-to-r from-muted-foreground/10 to-muted-foreground/20" />
                </div>

                <p
                    class="mt-2 text-xs"
                    :class="item.at_limit ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                >
                    {{ remainingLabel(item) }}
                </p>

                <p
                    v-if="isLinkedInAction(item)"
                    class="mt-1.5 inline-flex items-center gap-1 text-[11px] text-muted-foreground"
                >
                    <Sparkles class="size-3 shrink-0 text-blue-500/80" />
                    Safe limits adjust based on account activity and health.
                </p>
                <p v-else class="mt-1.5 text-[11px] text-muted-foreground">
                    {{ item.description }}
                </p>

                <p
                    v-if="item.at_limit"
                    class="mt-2 rounded-lg border border-amber-200/80 bg-amber-50/80 px-2.5 py-1.5 text-[11px] leading-relaxed text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                >
                    You've reached today's recommended safe capacity. Outreach resumes automatically after reset — this protects your LinkedIn account.
                </p>
            </div>
        </div>
    </div>
</template>
