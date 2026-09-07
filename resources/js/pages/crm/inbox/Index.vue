<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Inbox, PauseCircle } from '@lucide/vue';
import InboxAiBriefBar, { type InboxBrief } from '@/components/crm/InboxAiBriefBar.vue';
import InboxNurtureQueue, { type NurtureQueue } from '@/components/crm/InboxNurtureQueue.vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { computed, ref, watch } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Unified Inbox', href: '/inbox' },
        ],
    },
});

type NurtureBrief = {
    headline: string;
    total: number;
    due_this_week: number;
    overdue: number;
};

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
    inbox_brief?: InboxBrief | null;
    nurture_brief?: NurtureBrief | null;
    nurture_queue?: NurtureQueue | null;
    tab?: 'active' | 'nurture';
}>();

const page = usePage();
const activeTab = ref<'active' | 'nurture'>(props.tab ?? 'active');

const flashSuccess = computed(() => (page.props.flash as { success?: string } | undefined)?.success ?? null);
const flashError = computed(() => (page.props.flash as { error?: string } | undefined)?.error ?? null);

watch(
    () => props.tab,
    (tab) => {
        if (tab) activeTab.value = tab;
    },
);

function switchTab(tab: 'active' | 'nurture') {
    if (tab === activeTab.value) return;
    router.get('/inbox', tab === 'nurture' ? { tab: 'nurture' } : {}, {
        preserveScroll: true,
        replace: true,
    });
}

function isLoneLast(index: number): boolean {
    return props.platforms.length % 2 === 1 && index === props.platforms.length - 1;
}
</script>

<template>
    <Head title="Unified Inbox" />

    <div class="mx-auto flex max-w-4xl flex-col gap-6 p-4 sm:p-6">
        <div class="flex items-start gap-2.5">
            <Inbox class="mt-0.5 h-7 w-7 shrink-0 text-primary" />
            <div class="min-w-0 flex-1">
                <h1 class="text-xl font-semibold tracking-tight text-foreground">Unified Inbox</h1>
                <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                    Replies to your multi-channel outreach campaigns — grouped by platform. Not your full WhatsApp or LinkedIn inbox. Call Manager chats live under
                    <Link href="/calls" class="font-medium text-primary hover:underline">Call Manager</Link>.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-border pb-1">
            <button
                type="button"
                class="relative -mb-px rounded-t-lg px-4 py-2 text-sm font-medium transition"
                :class="activeTab === 'active' ? 'border-b-2 border-primary text-primary' : 'text-muted-foreground hover:text-foreground'"
                @click="switchTab('active')"
            >
                Active
            </button>
            <button
                type="button"
                class="relative -mb-px inline-flex items-center gap-2 rounded-t-lg px-4 py-2 text-sm font-medium transition"
                :class="activeTab === 'nurture' ? 'border-b-2 border-violet-600 text-violet-700 dark:text-violet-300' : 'text-muted-foreground hover:text-foreground'"
                @click="switchTab('nurture')"
            >
                <PauseCircle class="size-4" />
                Nurture
                <span
                    v-if="(nurture_brief?.total ?? 0) > 0"
                    class="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold text-violet-800 dark:bg-violet-950 dark:text-violet-200"
                >
                    {{ nurture_brief?.total }}
                </span>
            </button>
        </div>

        <div v-if="flashSuccess" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            {{ flashSuccess }}
        </div>
        <div v-if="flashError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ flashError }}
        </div>

        <template v-if="activeTab === 'active'">
            <InboxAiBriefBar :brief="inbox_brief ?? null" :nurture-brief="nurture_brief ?? null" />

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
        </template>

        <InboxNurtureQueue v-else :queue="nurture_queue ?? null" />

        <p v-if="activeTab === 'active'" class="text-xs text-muted-foreground">
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
