<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Inbox } from '@lucide/vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Unified Inbox', href: '/inbox' },
        ],
    },
});

defineProps<{
    platforms: Array<{
        key: string;
        label: string;
        color: string;
        connected: boolean;
        conversations_count: number;
        recent_inbound_count: number;
        href: string;
    }>;
}>();
</script>

<template>
    <Head title="Unified Inbox" />

    <div class="mx-auto flex max-w-4xl flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Unified Inbox</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                Replies to your multi-channel outreach campaigns — grouped by platform. Not your full WhatsApp or LinkedIn inbox. Call Manager chats live under
                <Link href="/calls" class="font-medium text-primary hover:underline">Call Manager</Link>.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <Link
                v-for="platform in platforms"
                :key="platform.key"
                :href="platform.href"
                class="group flex items-start gap-4 rounded-xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-primary/30 hover:bg-muted/20"
            >
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-muted/30 p-2">
                    <OutreachChannelIcon :channel="platform.key" :size="32" class="h-8 w-8" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="font-semibold">{{ platform.label }}</h2>
                        <span
                            class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide"
                            :class="platform.connected ? 'bg-emerald-100 text-emerald-700' : 'bg-muted text-muted-foreground'"
                        >
                            {{ platform.connected ? 'Connected' : 'Not connected' }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ platform.conversations_count }} conversation{{ platform.conversations_count === 1 ? '' : 's' }}
                        <span v-if="platform.recent_inbound_count > 0"> · {{ platform.recent_inbound_count }} replies this week</span>
                    </p>
                </div>
            </Link>
        </div>

        <div v-if="platforms.length === 0" class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center text-muted-foreground">
            <Inbox class="h-10 w-10 opacity-40" />
            <p class="text-sm">No multi-channel platforms configured yet.</p>
            <Link href="/integrations" class="text-sm font-medium text-primary hover:underline">Connect channels in Integrations</Link>
        </div>

        <p class="text-xs text-muted-foreground">
            Campaign activity and per-channel AI settings are managed on each
            <Link href="/outreach" class="text-primary hover:underline">outreach campaign</Link>
            detail page.
        </p>
    </div>
</template>
