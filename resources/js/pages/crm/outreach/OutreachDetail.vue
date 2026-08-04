<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity, AlertCircle, CheckCircle2, Clock, Copy, Inbox, Info, Layers, Loader2,
    Pause, Play, Pencil, Radio, Rocket, Sparkles, Trash2, Users, XCircle, Zap,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import OutreachLeadReadinessPanel from '@/components/outreach/OutreachLeadReadinessPanel.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { ConnectedChannel, OutreachStep } from '@/components/outreach/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Multi-Channel', href: '/outreach' }, { title: 'Detail' }] },
});

const props = defineProps<{
    campaign: {
        id: number;
        name: string;
        template_type: string;
        status: string;
        node_model: OutreachStep[];
        meta: Record<string, unknown> | null;
        outreach_leads_count: number;
        created_at: string;
    };
    leads: { data: Array<{ id: number; full_name: string | null; status: string; email: string | null; progress: { current_node_label: string | null; next_run_at: string | null } | null }> };
    attachedLists: Array<{ id: number; list_name: string; list_hash: string; list_src: 'aud' | 'sn' | 'csv'; lead_count: number }>;
    connectedChannels: ConnectedChannel[];
    inboxSummary?: {
        replied_leads_count: number;
        platforms: Array<{
            channel: string;
            label: string;
            color: string;
            threads_count: number;
            inbox_href: string;
            recent_threads: Array<{ id: number; prospect_name: string | null; last_message_at: string | null; href: string }>;
            settings: { ai_context: string; auto_reply_enabled: boolean; pause_on_reply: boolean };
            settings_update_url: string;
        }>;
    };
    aiConfigured?: boolean;
    stats?: {
        total_leads: number;
        by_status: { pending: number; running: number; replied: number; done: number; error: number; skipped: number };
        completion_rate: number;
        reply_rate: number;
        invite_accepted_count: number;
        invite_accepted_rate: number;
        steps_completed: number;
        steps_failed: number;
        actions_by_channel: Record<string, number>;
        funnel: Array<{
            node_key: number;
            label: string;
            type: string;
            channel: string | null;
            reached: number;
            completed: number;
            failed: number;
            waiting: number;
            skipped: number;
            conversion_rate: number;
        }>;
    };
    concurrency?: { limit: number; in_flight: number; available: number };
}>();

const page = usePage();
type ActivityEvent = {
    id: number;
    message: string;
    status: string;
    executed_at: string;
    lead_name?: string;
    node_label?: string;
};

const launching = ref(false);
const duplicating = ref(false);
const savingTemplate = ref(false);
const togglingStatus = ref(false);
const activityEvents = ref<ActivityEvent[]>([]);
const activityLoading = ref(false);
const lastEventId = ref(0);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const isRunning = computed(() => ['active', 'running'].includes(props.campaign.status));
const statusColor = (s: string) => {
    if (s === 'active' || s === 'running') return 'bg-green-500/10 text-green-700 border-green-200';
    if (s === 'draft') return 'bg-slate-500/10 text-slate-600 border-slate-200';
    if (s === 'paused' || s === 'stopped') return 'bg-yellow-500/10 text-yellow-700 border-yellow-200';
    return 'bg-muted text-muted-foreground border-border';
};

const eventStatusColor = (status: string) => {
    if (status === 'completed' || status === 'sent') return 'text-green-600 bg-green-50 border-green-200';
    if (status === 'failed') return 'text-red-600 bg-red-50 border-red-200';
    if (status === 'started') return 'text-blue-600 bg-blue-50 border-blue-200';
    if (status === 'waiting' || status === 'scheduled' || status === 'pending') return 'text-amber-600 bg-amber-50 border-amber-200';
    if (status === 'paused') return 'text-violet-600 bg-violet-50 border-violet-200';
    if (status === 'skipped') return 'text-slate-500 bg-slate-50 border-slate-200';
    return 'text-muted-foreground bg-muted/40 border-border';
};

const leadsInProgress = computed(() =>
    props.leads.data.filter((l) => ['running', 'pending'].includes(l.status)).length,
);

const latestActivityMessage = computed(() => activityEvents.value[0]?.message ?? null);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);

type ChannelSettings = { ai_context: string; auto_reply_enabled: boolean; pause_on_reply: boolean };
const localChannelSettings = ref<Record<string, ChannelSettings>>({});
const savingChannel = ref<string | null>(null);

watch(
    () => props.inboxSummary?.platforms,
    (platforms) => {
        const next: Record<string, ChannelSettings> = {};
        for (const p of platforms ?? []) {
            next[p.channel] = { ...p.settings };
        }
        localChannelSettings.value = next;
    },
    { immediate: true },
);

function savePlatformSettings(platform: NonNullable<typeof props.inboxSummary>['platforms'][0]) {
    const payload = localChannelSettings.value[platform.channel];
    if (!payload) return;
    savingChannel.value = platform.channel;
    router.put(platform.settings_update_url, payload, {
        preserveScroll: true,
        onFinish: () => { savingChannel.value = null; },
    });
}

function flatten(nodes: OutreachStep[]): OutreachStep[] {
    const result: OutreachStep[] = [];
    for (const n of nodes) {
        if (n.type !== 'end') result.push(n);
        if (n.branches) {
            result.push(...flatten(n.branches.accepted || []), ...flatten(n.branches.not_accepted || []));
        }
    }
    return result;
}

const allSteps = computed(() => flatten(props.campaign.node_model ?? []));

const hasOutboundSendSteps = computed(() =>
    allSteps.value.some(
        (s) => s.type === 'action' && ['send_message', 'send_email', 'send_invite'].includes(s.action ?? ''),
    ),
);

function formatDateTime(iso: string | null) {
    if (!iso) return '';
    return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

async function fetchActivity(initial = false) {
    if (activityLoading.value && !initial) return;
    activityLoading.value = true;

    try {
        const params = new URLSearchParams({ limit: '50' });
        if (!initial && lastEventId.value > 0) {
            params.set('after_id', String(lastEventId.value));
        }
        const res = await fetch(`/outreach/${props.campaign.id}/activity?${params}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const json = await res.json();
        const events: ActivityEvent[] = json.events ?? [];

        if (initial) {
            activityEvents.value = events;
        } else if (events.length) {
            activityEvents.value = [...events, ...activityEvents.value].slice(0, 100);
        }
        if (activityEvents.value.length) {
            lastEventId.value = Math.max(...activityEvents.value.map((e) => e.id));
        }
    } finally {
        activityLoading.value = false;
    }
}

async function refreshLiveData() {
    if (!isRunning.value) return;
    router.reload({
        only: ['campaign', 'leads', 'stats', 'inboxSummary', 'concurrency'],
        preserveScroll: true,
    });
}

function startLiveUpdates() {
    stopLiveUpdates();
    fetchActivity(true);
    pollTimer = setInterval(() => {
        fetchActivity(false);
        refreshLiveData();
    }, 5000);
}

function stopLiveUpdates() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

watch(isRunning, (running) => {
    if (running) startLiveUpdates();
    else stopLiveUpdates();
});

onMounted(() => {
    if (isRunning.value) startLiveUpdates();
    else fetchActivity(true);
});
onUnmounted(stopLiveUpdates);

function launch() {
    launching.value = true;
    router.post(`/outreach/${props.campaign.id}/activate`, {}, {
        onFinish: () => { launching.value = false; },
        onSuccess: () => startLiveUpdates(),
    });
}

function toggleStatus() {
    togglingStatus.value = true;
    const next = isRunning.value ? 'paused' : 'active';
    router.put(`/outreach/${props.campaign.id}`, { status: next }, {
        preserveScroll: true,
        onFinish: () => { togglingStatus.value = false; },
        onSuccess: () => {
            if (next === 'paused') stopLiveUpdates();
            else startLiveUpdates();
        },
    });
}

function confirmDelete() {
    if (!confirm(`Delete "${props.campaign.name}"?`)) return;
    router.delete(`/outreach/${props.campaign.id}`);
}

function duplicateCampaign() {
    duplicating.value = true;
    router.post(`/outreach/${props.campaign.id}/duplicate`, {}, {
        onFinish: () => { duplicating.value = false; },
    });
}

function saveAsTemplate() {
    const name = prompt('Template name', `Template: ${props.campaign.name}`);
    if (name === null) return;
    savingTemplate.value = true;
    router.post(`/outreach/${props.campaign.id}/save-template`, { name: name.trim() || undefined }, {
        onFinish: () => { savingTemplate.value = false; },
    });
}

const channelActionEntries = computed(() =>
    Object.entries(props.stats?.actions_by_channel ?? {}).sort((a, b) => b[1] - a[1]),
);
</script>

<template>
    <Head :title="campaign.name" />

    <div class="flex max-w-5xl flex-col gap-5 p-4">
        <div v-if="flashError" class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <AlertCircle class="mt-0.5 h-4 w-4" /> {{ flashError }}
        </div>
        <div v-if="flashSuccess" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ flashSuccess }}</div>

        <div
            v-if="isRunning && concurrency"
            class="flex items-start gap-3 rounded-xl border border-sky-200/80 bg-sky-50/80 px-4 py-3 text-sm text-sky-900"
        >
            <Info class="mt-0.5 h-4 w-4 shrink-0 text-sky-600" />
            <div>
                <p class="font-medium">LinkedIn-safe pacing</p>
                <p class="mt-0.5 text-sky-800/90">
                    Up to {{ concurrency.limit }} leads run at once
                    <span v-if="concurrency.in_flight > 0"> ({{ concurrency.in_flight }} active now)</span>.
                    The rest stay queued and start automatically when a slot frees — this protects your account and keeps the server fair for other users.
                </p>
            </div>
        </div>

        <!-- Live campaign banner -->
        <div
            v-if="isRunning"
            class="relative overflow-hidden rounded-xl border border-emerald-200 bg-gradient-to-r from-emerald-50 via-green-50 to-teal-50 p-4 shadow-sm"
        >
            <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(90deg,transparent,rgba(16,185,129,0.08),transparent)] animate-[shimmer_2.5s_ease-in-out_infinite]" />
            <div class="relative flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <span class="relative mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100">
                        <Radio class="h-4 w-4 text-emerald-600" />
                        <span class="absolute inset-0 rounded-full bg-emerald-400/30 animate-ping" />
                    </span>
                    <div>
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-emerald-900">
                            Campaign is live
                            <span class="inline-flex items-center gap-1 rounded-full border border-emerald-300 bg-white/80 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-emerald-700">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                Processing
                            </span>
                        </p>
                        <p class="mt-1 text-xs text-emerald-800/80">
                            <template v-if="latestActivityMessage">{{ latestActivityMessage }}</template>
                            <template v-else-if="leadsInProgress > 0">{{ leadsInProgress }} lead(s) in queue — steps execute via the outreach worker.</template>
                            <template v-else>Waiting for the next scheduled step…</template>
                        </p>
                        <p class="mt-1 text-[10px] text-emerald-700/70">Auto-refreshes every 5 seconds</p>
                    </div>
                </div>
                <div v-if="stats" class="grid grid-cols-3 gap-2 sm:min-w-[240px]">
                    <div class="rounded-lg border border-emerald-200/80 bg-white/70 px-2.5 py-2 text-center">
                        <p class="text-[10px] uppercase text-emerald-700">Running</p>
                        <p class="text-lg font-semibold tabular-nums text-emerald-900">{{ stats.by_status.running + stats.by_status.pending }}</p>
                    </div>
                    <div class="rounded-lg border border-emerald-200/80 bg-white/70 px-2.5 py-2 text-center">
                        <p class="text-[10px] uppercase text-emerald-700">Done</p>
                        <p class="text-lg font-semibold tabular-nums text-emerald-900">{{ stats.by_status.done }}</p>
                    </div>
                    <div class="rounded-lg border border-emerald-200/80 bg-white/70 px-2.5 py-2 text-center">
                        <p class="text-[10px] uppercase text-emerald-700">Replied</p>
                        <p class="text-lg font-semibold tabular-nums text-emerald-900">{{ stats.by_status.replied }}</p>
                    </div>
                </div>
            </div>
            <div v-if="stats && stats.total_leads > 0" class="relative mt-3">
                <div class="mb-1 flex items-center justify-between text-[10px] text-emerald-800/80">
                    <span>Overall progress</span>
                    <span class="font-medium tabular-nums">{{ stats.completion_rate }}%</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-emerald-200/50">
                    <div
                        class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 transition-all duration-700 ease-out"
                        :style="{ width: `${Math.min(stats.completion_rate, 100)}%` }"
                    />
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-semibold">{{ campaign.name }}</h1>
                    <span class="rounded-full border px-2 py-0.5 text-xs font-medium capitalize" :class="statusColor(campaign.status)">
                        {{ campaign.status }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">{{ campaign.outreach_leads_count }} leads · {{ allSteps.length }} step{{ allSteps.length === 1 ? '' : 's' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <AppToolbarButton v-if="campaign.status === 'draft'" :disabled="launching" @click="launch">
                    <Rocket class="h-4 w-4" /> {{ launching ? 'Launching…' : 'Launch' }}
                </AppToolbarButton>
                <AppToolbarButton v-else :variant="isRunning ? 'amber' : 'success'" :disabled="togglingStatus" @click="toggleStatus">
                    <Loader2 v-if="togglingStatus" class="h-4 w-4 animate-spin" />
                    <Pause v-else-if="isRunning" class="h-4 w-4" />
                    <Play v-else class="h-4 w-4" />
                    {{ togglingStatus ? 'Updating…' : isRunning ? 'Pause' : 'Resume' }}
                </AppToolbarButton>
                <Tooltip :delay-duration="200">
                    <TooltipTrigger as-child>
                        <span class="inline-flex">
                            <Button v-if="isRunning" variant="violet" size="toolbar" disabled>
                                <Pencil class="h-4 w-4" /> Edit
                            </Button>
                            <Button v-else variant="violet" size="toolbar" as-child>
                                <Link :href="`/outreach/${campaign.id}/edit`">
                                    <Pencil class="h-4 w-4" /> Builder
                                </Link>
                            </Button>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent v-if="isRunning" side="bottom" class="text-xs">Pause the campaign to edit the sequence</TooltipContent>
                </Tooltip>
                <AppToolbarButton :disabled="duplicating || isRunning" @click="duplicateCampaign">
                    <Copy class="h-4 w-4" /> {{ duplicating ? 'Copying…' : 'Duplicate' }}
                </AppToolbarButton>
                <AppToolbarButton :disabled="savingTemplate" @click="saveAsTemplate">
                    <Layers class="h-4 w-4" /> {{ savingTemplate ? 'Saving…' : 'Save template' }}
                </AppToolbarButton>
                <AppToolbarButton variant="dangerGradient" :disabled="isRunning" @click="confirmDelete">
                    <Trash2 class="h-4 w-4" />
                </AppToolbarButton>
            </div>
        </div>

        <div
            v-if="!isRunning && attachedLists.length && !hasOutboundSendSteps"
            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
        >
            <Link :href="`/outreach/${campaign.id}/edit`" class="font-medium text-amber-900 underline underline-offset-2">
                Open builder
            </Link>
            to add send steps and prepare contacts on the same screen — then launch from here.
        </div>

        <OutreachLeadReadinessPanel
            v-if="attachedLists.length && !isRunning"
            compact
            :lead-lists="attachedLists.map(l => ({ list_hash: l.list_hash, list_src: l.list_src, list_name: l.list_name }))"
            :node-model="campaign.node_model ?? []"
        />

        <div v-if="stats && stats.total_leads > 0" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-xl border bg-card p-4" :class="isRunning ? 'ring-1 ring-emerald-200/60' : ''">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    Total leads
                    <span v-if="isRunning" class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ stats.total_leads }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4" :class="isRunning ? 'ring-1 ring-emerald-200/60' : ''">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    Completion rate
                    <Zap v-if="isRunning" class="h-3 w-3 text-amber-500 animate-pulse" />
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ stats.completion_rate }}%</p>
                <p class="text-xs text-muted-foreground">{{ stats.by_status.done }} done · {{ stats.by_status.replied }} replied</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Reply rate</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ stats.reply_rate }}%</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Invite accepted</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ stats.invite_accepted_rate }}%</p>
                <p class="text-xs text-muted-foreground">{{ stats.invite_accepted_count }} accepted</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Steps executed</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ stats.steps_completed }}</p>
                <p v-if="stats.steps_failed" class="text-xs text-red-600">{{ stats.steps_failed }} failed</p>
            </div>
        </div>

        <div v-if="stats?.funnel?.length" class="rounded-xl border bg-card p-4">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold">Step funnel</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">How many leads reached each step in the sequence.</p>
                </div>
                <span v-if="isRunning" class="inline-flex items-center gap-1 text-[10px] text-emerald-600">
                    <Loader2 class="h-3 w-3 animate-spin" /> Live
                </span>
            </div>
            <div class="mt-4 space-y-2">
                <div
                    v-for="step in stats.funnel"
                    :key="step.node_key"
                    class="flex items-center gap-3 rounded-lg border border-border/60 px-3 py-2 transition-colors"
                    :class="isRunning && step.waiting > 0 ? 'bg-blue-50/50 border-blue-200/60' : ''"
                >
                    <OutreachChannelIcon v-if="step.channel" :channel="step.channel" class="h-3.5 w-3.5 shrink-0" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="truncate text-xs font-medium">{{ step.label }}</p>
                            <Clock v-if="isRunning && step.waiting > 0" class="h-3 w-3 shrink-0 text-blue-500 animate-pulse" />
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-primary transition-all duration-700 ease-out"
                                :style="{ width: `${Math.min(step.conversion_rate, 100)}%` }"
                            />
                        </div>
                    </div>
                    <div class="shrink-0 text-right text-[10px] text-muted-foreground">
                        <p class="font-semibold text-foreground tabular-nums">{{ step.reached }}</p>
                        <p class="tabular-nums">{{ step.conversion_rate }}%</p>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="channelActionEntries.length" class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">Actions by channel</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                <span
                    v-for="[channel, count] in channelActionEntries"
                    :key="channel"
                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                >
                    <OutreachChannelIcon :channel="channel" class="h-3.5 w-3.5" />
                    {{ count }}
                </span>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-semibold">Sequence ({{ allSteps.length }} steps)</h2>
                <div class="mt-3 flex flex-col gap-1.5">
                    <div
                        v-for="(step, idx) in allSteps"
                        :key="step.key"
                        class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs transition-colors"
                        :class="isRunning && idx === 0 ? 'bg-blue-50 text-blue-800 ring-1 ring-blue-200/60' : 'bg-muted/30'"
                    >
                        <OutreachChannelIcon v-if="step.channel" :channel="step.channel" class="h-3.5 w-3.5" />
                        <span class="font-medium">{{ step.label }}</span>
                        <Loader2 v-if="isRunning && idx === 0" class="ml-auto h-3 w-3 animate-spin text-blue-600" />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border bg-card overflow-hidden" :class="isRunning ? 'ring-1 ring-emerald-200/60' : ''">
                <div class="flex items-center justify-between border-b border-border px-4 py-3">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <Activity class="h-4 w-4" />
                        Activity
                        <span v-if="activityEvents.length" class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums">
                            {{ activityEvents.length }}
                        </span>
                    </h2>
                    <span class="inline-flex items-center gap-1 text-[10px] text-muted-foreground">
                        <template v-if="isRunning">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                            Live · every 5s
                        </template>
                        <template v-else>Last run events</template>
                    </span>
                </div>
                <div v-if="activityLoading && activityEvents.length === 0" class="flex flex-col items-center gap-2 p-8 text-sm text-muted-foreground">
                    <Loader2 class="h-5 w-5 animate-spin" />
                    Loading activity…
                </div>
                <div v-else-if="activityEvents.length === 0" class="p-8 text-center text-sm text-muted-foreground">
                    <Activity class="mx-auto mb-2 h-8 w-8 opacity-30" />
                    <p v-if="isRunning">Waiting for the first step to execute…</p>
                    <p v-else>No activity yet. Launch the campaign to see each step here.</p>
                </div>
                <div v-else class="max-h-64 space-y-0 overflow-y-auto divide-y divide-border">
                    <div
                        v-for="(event, idx) in activityEvents"
                        :key="event.id"
                        class="flex items-start gap-3 px-4 py-2.5 text-xs transition-colors hover:bg-muted/20"
                        :class="isRunning && idx === 0 ? 'bg-emerald-50/50 animate-in fade-in slide-in-from-top-1 duration-300' : ''"
                    >
                        <span
                            class="mt-0.5 shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-medium capitalize"
                            :class="eventStatusColor(event.status)"
                        >
                            {{ event.status }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-foreground">{{ event.message }}</p>
                            <p v-if="event.node_label || event.lead_name" class="mt-0.5 text-[10px] text-muted-foreground">
                                <span v-if="event.node_label">Step: {{ event.node_label }}</span>
                                <span v-if="event.lead_name"> · {{ event.lead_name }}</span>
                            </p>
                        </div>
                        <span class="shrink-0 text-[10px] text-muted-foreground whitespace-nowrap">{{ formatDateTime(event.executed_at) }}</span>
                    </div>
                </div>
                <div v-if="isRunning && activityLoading && activityEvents.length > 0" class="border-t border-border px-4 py-2 text-center">
                    <span class="inline-flex items-center gap-1 text-[10px] text-muted-foreground">
                        <Loader2 class="h-3 w-3 animate-spin" /> Checking for new events…
                    </span>
                </div>
            </div>
        </div>

        <div v-if="inboxSummary?.platforms?.length" class="rounded-xl border bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="flex items-center gap-2 text-sm font-semibold"><Inbox class="h-4 w-4" /> Inbox & replies</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ inboxSummary.replied_leads_count }} lead(s) replied · configure AI per platform below
                    </p>
                </div>
            </div>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div
                    v-for="platform in inboxSummary.platforms"
                    :key="platform.channel"
                    class="rounded-lg border border-border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: platform.color }" />
                            <span class="text-sm font-medium">{{ platform.label }}</span>
                        </div>
                        <Link :href="platform.inbox_href" class="text-xs text-primary hover:underline">Open inbox</Link>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">{{ platform.threads_count }} thread(s)</p>
                    <ul v-if="platform.recent_threads.length" class="mt-2 space-y-1">
                        <li v-for="thread in platform.recent_threads" :key="thread.id">
                            <Link :href="thread.href" class="text-xs text-muted-foreground hover:text-foreground">
                                {{ thread.prospect_name ?? 'Unknown' }}
                            </Link>
                        </li>
                    </ul>
                    <form class="mt-3 space-y-3 border-t border-border/60 pt-3" @submit.prevent="savePlatformSettings(platform)">
                        <textarea
                            v-model="localChannelSettings[platform.channel].ai_context"
                            rows="2"
                            class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-xs"
                            :placeholder="`${platform.label} AI context for this campaign`"
                        />
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/20 px-2.5 py-2">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <Sparkles class="h-3.5 w-3.5 shrink-0 text-violet-600" />
                                <span class="text-xs font-medium">AI auto-reply</span>
                                <Tooltip :delay-duration="200">
                                    <TooltipTrigger as-child>
                                        <button type="button" class="rounded p-0.5 text-muted-foreground hover:text-foreground" aria-label="About AI auto-reply">
                                            <Info class="h-3.5 w-3.5" />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent side="top" class="max-w-[14rem] text-xs leading-relaxed">
                                        When enabled, inbound replies on {{ platform.label }} get an AI draft using this campaign context, thread summary, and last 5 messages.
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                            <Switch
                                v-model="localChannelSettings[platform.channel].auto_reply_enabled"
                                class="shrink-0"
                            />
                        </div>
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/20 px-2.5 py-2">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <Pause class="h-3.5 w-3.5 shrink-0 text-amber-600" />
                                <span class="text-xs font-medium">Pause on reply</span>
                                <Tooltip :delay-duration="200">
                                    <TooltipTrigger as-child>
                                        <button type="button" class="rounded p-0.5 text-muted-foreground hover:text-foreground" aria-label="About pause on reply">
                                            <Info class="h-3.5 w-3.5" />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent side="top" class="max-w-[14rem] text-xs leading-relaxed">
                                        Stops this lead's sequence when they reply on {{ platform.label }}. Other leads keep running.
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                            <Switch
                                v-model="localChannelSettings[platform.channel].pause_on_reply"
                                class="shrink-0"
                            />
                        </div>
                        <button type="submit" class="rounded-md bg-gradient-to-r from-blue-600 to-indigo-600 px-2.5 py-1 text-xs font-medium text-white disabled:opacity-50" :disabled="savingChannel === platform.channel">
                            Save {{ platform.label }} settings
                        </button>
                    </form>
                </div>
            </div>
            <p v-if="!aiConfigured" class="mt-3 text-xs text-amber-600">AI auto-replies are temporarily unavailable. Contact your administrator.</p>
        </div>

        <div class="rounded-xl border bg-card p-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Users class="h-4 w-4" />
                    Leads ({{ leads.data.length }})
                </h2>
                <span v-if="isRunning" class="text-[10px] text-emerald-600">Status updates live</span>
            </div>
            <table class="mt-3 w-full text-xs">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="py-2">Name</th>
                        <th>Step</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="lead in leads.data"
                        :key="lead.id"
                        class="border-b border-border/50 transition-colors"
                        :class="isRunning && ['running', 'pending'].includes(lead.status) ? 'bg-blue-50/40' : ''"
                    >
                        <td class="py-2 font-medium">{{ lead.full_name ?? 'Unknown' }}</td>
                        <td>
                            <span v-if="lead.progress?.current_node_label" class="text-blue-700">{{ lead.progress.current_node_label }}</span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td>
                            <div class="flex items-center gap-1 capitalize">
                                <CheckCircle2 v-if="lead.status === 'done'" class="h-3.5 w-3.5 text-green-500" />
                                <XCircle v-else-if="lead.status === 'error' || lead.status === 'skipped'" class="h-3.5 w-3.5 text-red-500" />
                                <Clock v-else-if="lead.status === 'running'" class="h-3.5 w-3.5 text-blue-500 animate-pulse" />
                                <Loader2 v-else-if="lead.status === 'pending' && isRunning" class="h-3.5 w-3.5 text-slate-400 animate-spin" />
                                <span v-else class="inline-block h-3.5 w-3.5 rounded-full border-2 border-muted-foreground/30" />
                                {{ lead.status }}
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>
</template>

<style scoped>
@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}
</style>
