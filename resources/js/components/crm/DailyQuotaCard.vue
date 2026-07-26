<script setup lang="ts">
import { computed } from 'vue';
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

function barClass(item: DailyQuotaItem): string {
    if (item.at_limit) {
        return 'from-red-500 to-red-600';
    }
    if (item.percent >= 85) {
        return 'from-amber-500 to-amber-600';
    }

    return 'from-blue-500 to-blue-600';
}

function usageLabel(item: DailyQuotaItem): string {
    if (item.unlimited) {
        return `${item.used.toLocaleString()} used today · unlimited`;
    }

    return `${item.used.toLocaleString()} / ${item.limit.toLocaleString()} used · ${item.remaining.toLocaleString()} left`;
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
        <div class="border-b border-border/60 bg-muted/20 px-5 py-4">
            <h2 class="text-sm font-semibold">Daily usage limits</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
                LinkedIn actions are paced automatically to protect your account. {{ resetsLabel }}.
            </p>
        </div>

        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <div
                v-for="item in items"
                :key="item.key"
                class="rounded-xl border border-border/60 bg-muted/10 p-4"
            >
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <span class="inline-flex items-center gap-1.5 text-sm font-medium">
                        {{ item.label }}
                        <EmailEnrichmentInfoTooltip
                            v-if="item.key === 'email_enrichment'"
                            side="right"
                        />
                    </span>
                    <span
                        class="text-xs sm:text-sm"
                        :class="item.at_limit ? 'font-medium text-red-600 dark:text-red-400' : 'text-muted-foreground'"
                    >
                        {{ usageLabel(item) }}
                    </span>
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

                <p class="mt-2 text-xs leading-relaxed text-muted-foreground">{{ item.description }}</p>
                <p v-if="item.at_limit" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">
                    Daily limit reached — remaining actions resume tomorrow automatically.
                </p>
            </div>
        </div>
    </div>
</template>
