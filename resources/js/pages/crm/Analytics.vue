<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BarChart3 } from '@lucide/vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Analytics', href: '/analytics' }] },
});

defineProps<{
    latestMini: { connections: number; sent_invites: number; profile_views: number; created_at: string } | null;
    moduleActivity: Array<{ module: string; total: number; events: number }>;
    dailyActivity: Array<{ date: string; events: number; total: number }>;
    webhookActivity: Array<{ identifier: string; count: number }>;
    hasOrg: boolean;
}>();

const maxDailyTotal = (daily: Array<{ total: number }>) =>
    daily.length ? Math.max(...daily.map((d) => Number(d.total)), 1) : 1;
</script>

<template>
    <Head title="Analytics" />

    <div class="flex flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold">Analytics</h1>
            <p class="text-sm text-muted-foreground">Activity, stats, and event tracking.</p>
        </div>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400">
            Link your workspace through the extension first.
        </div>

        <template v-else>
            <!-- Mini stats -->
            <div v-if="latestMini" class="grid grid-cols-3 gap-3">
                <div class="rounded-xl border border-border bg-card p-4 shadow-sm text-center">
                    <div class="text-2xl font-bold">{{ latestMini.connections.toLocaleString() }}</div>
                    <div class="text-xs text-muted-foreground">Connections</div>
                </div>
                <div class="rounded-xl border border-border bg-card p-4 shadow-sm text-center">
                    <div class="text-2xl font-bold">{{ latestMini.sent_invites.toLocaleString() }}</div>
                    <div class="text-xs text-muted-foreground">Invites Sent</div>
                </div>
                <div class="rounded-xl border border-border bg-card p-4 shadow-sm text-center">
                    <div class="text-2xl font-bold">{{ latestMini.profile_views.toLocaleString() }}</div>
                    <div class="text-xs text-muted-foreground">Profile Views</div>
                </div>
            </div>
            <div v-else class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                <BarChart3 class="mx-auto mb-2 h-8 w-8 opacity-40" />
                No stats recorded yet. The extension pushes stats when you run campaigns.
            </div>

            <!-- Daily bar chart -->
            <div v-if="dailyActivity.length > 0" class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold">Daily Activity (last 14 days)</h2>
                <div class="flex items-end gap-1 h-24">
                    <div v-for="day in dailyActivity" :key="day.date"
                        class="group relative flex flex-1 flex-col items-center justify-end">
                        <div class="w-full rounded-t bg-primary/70 transition-all hover:bg-primary"
                            :style="{ height: Math.max(4, Math.round((Number(day.total) / maxDailyTotal(dailyActivity)) * 88)) + 'px' }">
                        </div>
                        <span class="absolute -bottom-5 left-1/2 -translate-x-1/2 text-[9px] text-muted-foreground">
                            {{ day.date.slice(5) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Module breakdown -->
            <div v-if="moduleActivity.length > 0" class="rounded-xl border border-border bg-card shadow-sm">
                <div class="border-b border-border px-4 py-3">
                    <h2 class="text-sm font-semibold">Activity by Module</h2>
                </div>
                <table class="w-full text-sm">
                    <thead class="border-b border-border bg-muted/40">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-muted-foreground">Module</th>
                            <th class="px-4 py-2 text-right font-medium text-muted-foreground">Events</th>
                            <th class="px-4 py-2 text-right font-medium text-muted-foreground">Total Stat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="mod in moduleActivity" :key="mod.module" class="transition hover:bg-muted/30">
                            <td class="px-4 py-2">
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">{{ mod.module }}</span>
                            </td>
                            <td class="px-4 py-2 text-right text-muted-foreground">{{ Number(mod.events).toLocaleString() }}</td>
                            <td class="px-4 py-2 text-right font-medium">{{ Number(mod.total).toLocaleString() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Webhook events -->
            <div v-if="webhookActivity.length > 0" class="rounded-xl border border-border bg-card shadow-sm">
                <div class="border-b border-border px-4 py-3">
                    <h2 class="text-sm font-semibold">Top Webhook Events</h2>
                </div>
                <ul class="divide-y divide-border">
                    <li v-for="wh in webhookActivity" :key="wh.identifier"
                        class="flex items-center justify-between px-4 py-2.5 text-sm">
                        <span class="font-mono text-xs">{{ wh.identifier }}</span>
                        <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ Number(wh.count).toLocaleString() }}</span>
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>
