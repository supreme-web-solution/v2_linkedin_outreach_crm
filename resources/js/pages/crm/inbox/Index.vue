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

const props = defineProps<{
    platforms: Array<{
        key: string;
        label: string;
        color: string;
        connected: boolean;
        conversations_count: number;
        recent_inbound_count: number;
        unread_count: number;
        href: string;
    }>;
}>();

function isLoneLast(index: number): boolean {
    return props.platforms.length % 2 === 1 && index === props.platforms.length - 1;
}
</script>

<template>
    <Head title="Unified Inbox" />

    <div class="mx-auto flex max-w-4xl flex-col gap-6 p-4 sm:p-6">
        <div class="flex items-start gap-2.5">
            <Inbox class="mt-0.5 h-7 w-7 shrink-0 text-primary" />
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight text-foreground">Unified Inbox</h1>
                <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                    Replies to your multi-channel outreach campaigns — grouped by platform. Not your full WhatsApp or LinkedIn inbox. Call Manager chats live under
                    <Link href="/calls" class="font-medium text-primary hover:underline">Call Manager</Link>.
                </p>
            </div>
        </div>

        <div v-if="platforms.length" class="grid gap-4 sm:grid-cols-2">
            <Link
                v-for="(platform, index) in platforms"
                :key="platform.key"
                :href="platform.href"
                class="inbox-card group relative flex items-start gap-4 overflow-hidden rounded-xl border border-border p-4 shadow-sm"
                :class="isLoneLast(index) ? 'sm:col-span-2 sm:mx-auto sm:w-full sm:max-w-[calc(50%-0.5rem)]' : ''"
            >
                <div class="inbox-card-shine pointer-events-none absolute inset-0" aria-hidden="true" />
                <div class="relative z-[1] flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white/80 p-2 ring-1 ring-black/5">
                    <OutreachChannelIcon :channel="platform.key" :size="28" class="h-7 w-7" />
                </div>
                <div class="relative z-[1] min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="font-semibold">{{ platform.label }}</h2>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <span
                                v-if="platform.unread_count > 0"
                                class="flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[10px] font-bold text-primary-foreground"
                            >
                                {{ platform.unread_count > 99 ? '99+' : platform.unread_count }}
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide"
                                :class="platform.connected ? 'bg-emerald-100 text-emerald-700' : 'bg-muted text-muted-foreground'"
                            >
                                {{ platform.connected ? 'Connected' : 'Not connected' }}
                            </span>
                        </div>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ platform.conversations_count }} conversation{{ platform.conversations_count === 1 ? '' : 's' }}
                        <span v-if="platform.unread_count > 0" class="font-medium text-primary">
                            · {{ platform.unread_count }} unread
                        </span>
                        <span v-else-if="platform.recent_inbound_count > 0">
                            · {{ platform.recent_inbound_count }} replies this week
                        </span>
                    </p>
                </div>
            </Link>
        </div>

        <div
            v-else
            class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center text-muted-foreground"
        >
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

<style scoped>
.inbox-card {
    background:
        linear-gradient(180deg, rgb(239 246 255) 0%, rgb(255 255 255) 38%),
        #ffffff;
    transition:
        border-color 220ms ease,
        box-shadow 220ms ease;
}

.inbox-card:hover {
    border-color: rgb(56 189 248 / 0.55);
    box-shadow:
        0 0 0 1px rgb(14 165 233 / 0.08),
        0 8px 24px -10px rgb(14 165 233 / 0.35);
}

.inbox-card-shine {
    opacity: 0;
}

.inbox-card-shine::before {
    content: '';
    position: absolute;
    top: -40%;
    left: -60%;
    width: 45%;
    height: 180%;
    background: linear-gradient(
        105deg,
        transparent 0%,
        rgb(125 211 252 / 0.08) 35%,
        rgb(186 230 253 / 0.55) 48%,
        rgb(56 189 248 / 0.35) 52%,
        rgb(125 211 252 / 0.08) 65%,
        transparent 100%
    );
    transform: translateX(-120%) skewX(-18deg);
    filter: blur(0.5px);
}

.inbox-card:hover .inbox-card-shine {
    opacity: 1;
}

.inbox-card:hover .inbox-card-shine::before {
    animation: inbox-light-ray 0.85s ease-out forwards;
}

@keyframes inbox-light-ray {
    from {
        transform: translateX(-120%) skewX(-18deg);
    }
    to {
        transform: translateX(320%) skewX(-18deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .inbox-card:hover .inbox-card-shine::before {
        animation: none;
        transform: translateX(80%) skewX(-18deg);
        opacity: 0.35;
    }
}
</style>
