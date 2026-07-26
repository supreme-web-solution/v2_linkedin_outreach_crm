<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BarChart3, Eye, Send, UserPlus } from '@lucide/vue';
import DailyQuotaCard, { type DailyQuotaItem } from '@/components/crm/DailyQuotaCard.vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Analytics', href: '/analytics' }] },
});

const props = defineProps<{
    latestMini: { connections: number; sent_invites: number; profile_views: number; created_at: string } | null;
    moduleActivity: Array<{ module: string; total: number; events: number }>;
    dailyActivity: Array<{ date: string; events: number; total: number }>;
    webhookActivity: Array<{ identifier: string; count: number }>;
    hasOrg: boolean;
    dailyQuotas: { items: DailyQuotaItem[]; resets_at: string };
}>();

const miniStatCards = [
    {
        key: 'connections',
        label: 'Connections',
        value: () => props.latestMini?.connections ?? 0,
        icon: UserPlus,
        gradient: 'from-blue-400 to-blue-600',
        ring: 'ring-blue-500/10',
    },
    {
        key: 'sent_invites',
        label: 'Invites sent',
        value: () => props.latestMini?.sent_invites ?? 0,
        icon: Send,
        gradient: 'from-sky-400 to-sky-600',
        ring: 'ring-sky-500/10',
    },
    {
        key: 'profile_views',
        label: 'Profile views',
        value: () => props.latestMini?.profile_views ?? 0,
        icon: Eye,
        gradient: 'from-violet-400 to-violet-600',
        ring: 'ring-violet-500/10',
    },
] as const;

const maxDailyTotal = (daily: Array<{ total: number }>) =>
    daily.length ? Math.max(...daily.map((d) => Number(d.total)), 1) : 1;

function formatMiniUpdatedAt(iso: string): string {
    try {
        return new Date(iso).toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
    } catch {
        return iso.slice(0, 16);
    }
}
</script>

<template>
    <Head title="Analytics" />

    <div class="flex flex-col gap-5 p-4 md:p-5">
        <div>
            <h1 class="text-xl font-semibold">Analytics</h1>
            <p class="text-sm text-muted-foreground">Activity, stats, and event tracking from your extension.</p>
        </div>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400">
            Link your workspace through the extension first.
        </div>

        <template v-else>
            <!-- Extension stats -->
            <section>
                <div v-if="latestMini" class="mb-3 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold">LinkedIn snapshot</h2>
                        <p class="text-xs text-muted-foreground">Latest stats pushed from the extension</p>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Updated {{ formatMiniUpdatedAt(latestMini.created_at) }}
                    </p>
                </div>

                <div v-if="latestMini" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div
                        v-for="card in miniStatCards"
                        :key="card.key"
                        class="relative overflow-hidden rounded-2xl border border-border/60 bg-card p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                    {{ card.label }}
                                </p>
                                <p class="mt-2 text-3xl font-bold text-foreground">
                                    {{ card.value().toLocaleString() }}
                                </p>
                            </div>
                            <div
                                :class="[
                                    'flex size-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br text-white shadow-sm ring-4',
                                    card.gradient,
                                    card.ring,
                                ]"
                            >
                                <component :is="card.icon" class="size-5 stroke-[1.75]" />
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-10 text-center">
                    <div class="flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-400 to-blue-600 text-white shadow-sm ring-4 ring-blue-500/10">
                        <BarChart3 class="size-7 stroke-[1.75]" />
                    </div>
                    <div>
                        <p class="font-medium text-foreground">No extension stats yet</p>
                        <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                            Run a campaign in LinkedIn with the extension connected — stats will appear here automatically.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Daily bar chart -->
            <section v-if="dailyActivity.length > 0" class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
                <div class="border-b border-border/60 px-5 py-4">
                    <h2 class="text-sm font-semibold">Daily activity</h2>
                    <p class="text-xs text-muted-foreground">Last 14 days across your workspace</p>
                </div>
                <div class="px-5 pb-8 pt-5">
                    <div class="flex h-28 items-end gap-1.5">
                        <div
                            v-for="day in dailyActivity"
                            :key="day.date"
                            class="group relative flex flex-1 flex-col items-center justify-end"
                        >
                            <div
                                class="w-full rounded-t-md bg-gradient-to-t from-blue-600 to-blue-400 opacity-80 transition-all group-hover:opacity-100"
                                :style="{ height: Math.max(4, Math.round((Number(day.total) / maxDailyTotal(dailyActivity)) * 96)) + 'px' }"
                            />
                            <span
                                class="absolute -bottom-6 left-1/2 -translate-x-1/2 whitespace-nowrap text-[9px] text-muted-foreground"
                            >
                                {{ day.date.slice(5) }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Module breakdown -->
            <section v-if="moduleActivity.length > 0" class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
                <div class="border-b border-border/60 px-5 py-4">
                    <h2 class="text-sm font-semibold">Activity by module</h2>
                    <p class="text-xs text-muted-foreground">Events grouped by feature area</p>
                </div>
                <table class="w-full text-sm">
                    <thead class="border-b border-border/60 bg-muted/30">
                        <tr>
                            <th class="px-5 py-2.5 text-left font-medium text-muted-foreground">Module</th>
                            <th class="px-5 py-2.5 text-right font-medium text-muted-foreground">Events</th>
                            <th class="px-5 py-2.5 text-right font-medium text-muted-foreground">Total stat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="mod in moduleActivity" :key="mod.module" class="transition hover:bg-muted/20">
                            <td class="px-5 py-3">
                                <span class="inline-flex rounded-full bg-gradient-to-b from-blue-500/15 to-blue-600/10 px-2.5 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-300">
                                    {{ mod.module }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right text-muted-foreground">{{ Number(mod.events).toLocaleString() }}</td>
                            <td class="px-5 py-3 text-right font-semibold">{{ Number(mod.total).toLocaleString() }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Webhook events -->
            <section v-if="webhookActivity.length > 0" class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
                <div class="border-b border-border/60 px-5 py-4">
                    <h2 class="text-sm font-semibold">Top webhook events</h2>
                    <p class="text-xs text-muted-foreground">Most frequent inbound webhook identifiers</p>
                </div>
                <ul class="divide-y divide-border/60">
                    <li
                        v-for="wh in webhookActivity"
                        :key="wh.identifier"
                        class="flex items-center justify-between px-5 py-3 text-sm transition hover:bg-muted/20"
                    >
                        <span class="font-mono text-xs text-foreground">{{ wh.identifier }}</span>
                        <span class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                            {{ Number(wh.count).toLocaleString() }}
                        </span>
                    </li>
                </ul>
            </section>

            <!-- Daily limits — bottom -->
            <DailyQuotaCard
                :items="dailyQuotas.items"
                :resets-at="dailyQuotas.resets_at"
            />
        </template>
    </div>
</template>
