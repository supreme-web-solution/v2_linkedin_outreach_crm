<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import {
    Pencil, Trash2, Play, Pause, Users, ChevronRight, CheckCircle2, Clock, XCircle,
    AlertCircle, Layers, TrendingUp, Rocket, Activity, Loader2, ScrollText, LayoutGrid, Info,
} from '@lucide/vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import ListPagination from '@/components/crm/ListPagination.vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import { Button } from '@/components/ui/button';
import LinkedInDisconnectBanner from '@/components/campaign/LinkedInDisconnectBanner.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import CampaignStepIcon from '@/components/campaign/CampaignStepIcon.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Campaigns', href: '/campaigns' },
            { title: 'Detail' },
        ],
    },
});

type ActivityEvent = {
    id: number;
    campaign_lead_id: number | null;
    lead_name: string | null;
    node_key: number | null;
    node_label: string | null;
    step_type: string | null;
    status: string;
    message: string;
    executed_at: string;
};

type LeadRow = {
    id: number;
    full_name: string | null;
    headline: string | null;
    status: string;
    profile_url: string | null;
    progress: {
        run_status: number;
        current_node_key: number;
        next_node_key: number | null;
        current_node_label: string | null;
        completed_keys: number[];
        acceptance_status: boolean | null;
        next_run_at: string | null;
    } | null;
};

const props = defineProps<{
    campaign: {
        id: number;
        name: string;
        sequence_type: string;
        status: string;
        node_model: Array<Record<string, unknown>>;
        meta: Record<string, unknown> | null;
        campaign_leads_count: number;
        campaign_lists_count: number;
        accept_rate: number;
        created_at: string;
    };
    attachedLists: Array<{
        id: number;
        list_hash: string;
        list_src: string;
        list_name: string;
        lead_count: number;
    }>;
    leads: {
        data: LeadRow[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        from?: number | null;
        to?: number | null;
    };
    leadFilters: { search: string | null; status: string | null };
    concurrency?: { limit: number; in_flight: number; available: number };
}>();

const leadSearch = ref(props.leadFilters?.search ?? '');
const leadStatusFilter = ref(props.leadFilters?.status ?? 'all');

function applyLeadFilters() {
    router.get(`/campaigns/${props.campaign.id}`, {
        lead_search: leadSearch.value || undefined,
        lead_status: leadStatusFilter.value !== 'all' ? leadStatusFilter.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true, only: ['leads', 'leadFilters'] });
}

const deleting = ref(false);
const launching = ref(false);
const activeTab = ref<'overview' | 'activity'>('overview');
const activityEvents = ref<ActivityEvent[]>([]);
const activityLoading = ref(false);
const lastEventId = ref(0);
const leadLogOpen = ref(false);
const leadLogLead = ref<LeadRow | null>(null);
const leadLogEvents = ref<ActivityEvent[]>([]);
const leadLogLoading = ref(false);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const isRunning = computed(() => ['active', 'running', 'preparing'].includes(props.campaign.status));
const isPreparing = computed(() => props.campaign.status === 'preparing' || (props.campaign.meta as any)?.lead_sync?.status === 'syncing');

const linkedInPauseMessage = computed(() => {
    const meta = props.campaign.meta ?? {};
    if (meta.pause_reason === 'linkedin_disconnected') {
        return (meta.pause_message as string) || 'This campaign was paused because LinkedIn disconnected.';
    }

    return null;
});

const statusColor = (s: string) => {
    if (s === 'active' || s === 'running') return 'bg-green-500/10 text-green-700 border-green-200';
    if (s === 'preparing') return 'bg-amber-500/10 text-amber-800 border-amber-200';
    if (s === 'draft') return 'bg-slate-500/10 text-slate-600 border-slate-200';
    if (s === 'paused' || s === 'stopped') return 'bg-yellow-500/10 text-yellow-700 border-yellow-200';
    return 'bg-muted text-muted-foreground border-border';
};

const typeLabel: Record<string, string> = {
    lead_gen: 'Lead Generation', endorse: 'Endorse Skills',
    profile_views: 'Profile Views', custom: 'Custom',
};

const runStatusLabel = (rs: number) => {
    const labels: Record<number, string> = { 0: 'Pending', 1: 'Invite sent', 2: 'Accepted', 3: 'Messaging', 4: 'Done', 9: 'Error' };
    return labels[rs] ?? `Step ${rs}`;
};

const eventStatusColor = (status: string) => {
    if (status === 'completed') return 'text-green-600 bg-green-50 border-green-200';
    if (status === 'failed') return 'text-red-600 bg-red-50 border-red-200';
    if (status === 'started') return 'text-blue-600 bg-blue-50 border-blue-200';
    if (status === 'waiting' || status === 'scheduled') return 'text-amber-600 bg-amber-50 border-amber-200';
    if (status === 'skipped') return 'text-slate-500 bg-slate-50 border-slate-200';
    return 'text-muted-foreground bg-muted/40 border-border';
};

function flatten(nodes: Array<Record<string, unknown>>): Array<Record<string, unknown>> {
    const result: Array<Record<string, unknown>> = [];
    for (const n of nodes) {
        if (n.type !== 'end') result.push(n);
        if (n.branches) {
            const b = n.branches as Record<string, Array<Record<string, unknown>>>;
            result.push(...flatten(b.accepted || []), ...flatten(b.not_accepted || []));
        }
    }
    return result;
}

const allSteps = flatten(props.campaign.node_model ?? []);

function formatTime(iso: string | null) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function formatDateTime(iso: string | null) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

async function fetchActivity(initial = false, leadId?: number) {
    if (activityLoading.value && !leadId) return;
    if (leadId) leadLogLoading.value = true;
    else activityLoading.value = true;

    try {
        const params = new URLSearchParams({ limit: leadId ? '100' : '50' });
        if (!leadId && !initial && lastEventId.value > 0) {
            params.set('after_id', String(lastEventId.value));
        }
        if (leadId) {
            params.set('lead_id', String(leadId));
        }
        const res = await fetch(`/campaigns/${props.campaign.id}/activity?${params}`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return;
        const json = await res.json();
        const events: ActivityEvent[] = json.events ?? [];

        if (leadId) {
            leadLogEvents.value = events;
            return;
        }

        if (initial) {
            activityEvents.value = events;
        } else if (events.length) {
            activityEvents.value = [...events, ...activityEvents.value].slice(0, 100);
        }
        if (activityEvents.value.length) {
            lastEventId.value = Math.max(...activityEvents.value.map(e => e.id));
        }
    } finally {
        if (leadId) leadLogLoading.value = false;
        else activityLoading.value = false;
    }
}

async function refreshPageData() {
    if (!isRunning.value) return;
    router.reload({ only: ['leads', 'campaign', 'concurrency'], preserveScroll: true });
}

function startPolling() {
    stopPolling();
    fetchActivity(true);
    pollTimer = setInterval(() => {
        fetchActivity(false);
        if (leadLogOpen.value && leadLogLead.value) {
            fetchActivity(true, leadLogLead.value.id);
        }
        refreshPageData();
    }, 5000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function openLeadLogs(lead: LeadRow) {
    leadLogLead.value = lead;
    leadLogOpen.value = true;
    fetchActivity(true, lead.id);
}

watch(leadLogOpen, (open) => {
    if (!open) {
        leadLogLead.value = null;
        leadLogEvents.value = [];
    }
});

onMounted(() => {
    if (isRunning.value) startPolling();
    else fetchActivity(true);
});

onUnmounted(stopPolling);

function confirmDelete() {
    if (!confirm(`Delete campaign "${props.campaign.name}"? This cannot be undone.`)) return;
    deleting.value = true;
    router.delete(`/campaigns/${props.campaign.id}`, {
        onFinish: () => { deleting.value = false; },
    });
}

function launchCampaign() {
    launching.value = true;
    router.post(`/campaigns/${props.campaign.id}/activate`, {}, {
        onFinish: () => { launching.value = false; },
        onSuccess: () => startPolling(),
    });
}

function toggleStatus() {
    const newStatus = props.campaign.status === 'running' || props.campaign.status === 'active'
        ? 'paused'
        : 'active';
    router.put(`/campaigns/${props.campaign.id}`, { status: newStatus }, {
        preserveState: true,
        onSuccess: () => {
            if (newStatus === 'paused') stopPolling();
            else startPolling();
        },
    });
}
</script>

<template>
    <Head :title="campaign.name" />

    <div class="flex flex-col gap-5 p-4 max-w-5xl">
        <LinkedInDisconnectBanner :campaign-pause-message="linkedInPauseMessage" />

        <div
            v-if="isPreparing"
            class="flex items-start gap-3 rounded-xl border border-amber-200/80 bg-amber-50/80 px-4 py-3 text-sm text-amber-950"
        >
            <Loader2 class="mt-0.5 h-4 w-4 shrink-0 animate-spin text-amber-600" />
            <div>
                <p class="font-medium">Preparing leads</p>
                <p class="mt-0.5 text-amber-900/90">
                    Copying contacts from your lists in the background. The campaign starts automatically when this finishes — you can leave this page open.
                </p>
            </div>
        </div>

        <div
            v-if="isRunning && !isPreparing && concurrency"
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

        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-start gap-4">
            <div class="flex-1 min-w-0">
                <LinkedInPageHeading
                    inline
                    :icon-size="24"
                    heading-class="truncate text-xl font-semibold"
                    :title="campaign.name"
                    show-badge
                >
                    <template #trailing>
                        <span class="rounded-full border px-2 py-0.5 text-xs font-medium capitalize" :class="statusColor(campaign.status)">
                            {{ campaign.status }}
                        </span>
                        <span v-if="isRunning" class="inline-flex items-center gap-1 text-xs text-green-600">
                            <Loader2 class="h-3 w-3 animate-spin" /> Live
                        </span>
                    </template>
                </LinkedInPageHeading>
                <div class="flex items-center gap-3 mt-1 text-sm text-muted-foreground flex-wrap">
                    <span>{{ typeLabel[campaign.sequence_type] ?? campaign.sequence_type }}</span>
                    <ChevronRight class="h-3 w-3" />
                    <span class="flex items-center gap-1"><Users class="h-3.5 w-3.5" /> {{ campaign.campaign_leads_count }} leads</span>
                    <ChevronRight class="h-3 w-3" />
                    <span class="flex items-center gap-1"><TrendingUp class="h-3.5 w-3.5" /> {{ campaign.accept_rate }}% accept</span>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0 flex-wrap">
                <AppToolbarButton v-if="campaign.status === 'draft'" :disabled="launching" @click="launchCampaign">
                    <Rocket class="h-4 w-4" /> {{ launching ? 'Launching…' : 'Launch' }}
                </AppToolbarButton>
                <AppToolbarButton
                    v-else
                    :variant="campaign.status === 'running' || campaign.status === 'active' ? 'amber' : 'success'"
                    @click="toggleStatus"
                >
                    <Pause v-if="campaign.status === 'running' || campaign.status === 'active'" class="h-4 w-4" />
                    <Play v-else class="h-4 w-4" />
                    {{ campaign.status === 'running' || campaign.status === 'active' ? 'Pause' : 'Activate' }}
                </AppToolbarButton>
                <Button variant="violet" size="toolbar" as-child>
                    <Link :href="`/campaigns/${campaign.id}/edit`">
                        <Pencil class="h-4 w-4" /> Edit
                    </Link>
                </Button>
                <AppToolbarButton variant="dangerGradient" :disabled="deleting" @click="confirmDelete">
                    <Trash2 class="h-4 w-4" />
                </AppToolbarButton>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex items-center gap-1 border-b border-border">
            <button
                type="button"
                @click="activeTab = 'overview'"
                class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
                :class="activeTab === 'overview'
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground'">
                <LayoutGrid class="h-4 w-4" /> Overview
            </button>
            <button
                type="button"
                @click="activeTab = 'activity'"
                class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
                :class="activeTab === 'activity'
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground'">
                <Activity class="h-4 w-4" /> Activity log
                <span v-if="activityEvents.length" class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                    {{ activityEvents.length }}
                </span>
            </button>
        </div>

        <!-- Activity tab -->
        <div v-show="activeTab === 'activity'" class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-4 py-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">All campaign activity</h2>
                <span class="text-[10px] text-muted-foreground">
                    {{ isRunning ? 'Updates every 5s' : 'Last run events' }}
                </span>
            </div>
            <div v-if="activityLoading && activityEvents.length === 0" class="p-8 text-center text-sm text-muted-foreground">
                <Loader2 class="h-5 w-5 animate-spin mx-auto mb-2" /> Loading…
            </div>
            <div v-else-if="activityEvents.length === 0" class="p-8 text-center text-sm text-muted-foreground">
                No activity yet. Launch the campaign to see each step execute here.
            </div>
            <div v-else class="max-h-[480px] overflow-y-auto divide-y divide-border">
                <div v-for="event in activityEvents" :key="event.id"
                    class="px-4 py-2.5 flex items-start gap-3 text-xs hover:bg-muted/20">
                    <span class="shrink-0 mt-0.5 rounded border px-1.5 py-0.5 text-[10px] font-medium capitalize"
                        :class="eventStatusColor(event.status)">
                        {{ event.status }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-foreground">{{ event.message }}</p>
                        <p v-if="event.node_label || event.lead_name" class="text-[10px] text-muted-foreground mt-0.5">
                            <span v-if="event.node_label">Step: {{ event.node_label }}</span>
                            <span v-if="event.lead_name"> · {{ event.lead_name }}</span>
                        </p>
                    </div>
                    <span class="shrink-0 text-[10px] text-muted-foreground">{{ formatDateTime(event.executed_at) }}</span>
                </div>
            </div>
        </div>

        <!-- Overview tab -->
        <div v-show="activeTab === 'overview'" class="flex flex-col gap-5">

            <div v-if="attachedLists.length" class="rounded-xl border border-border bg-card p-4">
                <h2 class="text-sm font-semibold mb-3">Lead lists ({{ attachedLists.length }})</h2>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div v-for="list in attachedLists" :key="list.id" class="flex items-center gap-3 rounded-lg border border-border bg-muted/20 px-3 py-2.5">
                        <Layers class="h-4 w-4 text-muted-foreground shrink-0" />
                        <div class="min-w-0">
                            <p class="text-sm font-medium truncate">{{ list.list_name }}</p>
                            <p class="text-xs text-muted-foreground">{{ list.list_src === 'aud' ? 'Audience' : 'Sales Navigator' }} · {{ list.lead_count.toLocaleString() }} leads</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">
                <div class="lg:col-span-2 rounded-xl border border-border bg-card overflow-hidden">
                    <div class="border-b border-border px-4 py-3">
                        <h2 class="text-sm font-semibold">Sequence ({{ allSteps.length }} steps)</h2>
                    </div>
                    <div class="p-3 flex flex-col gap-1.5 max-h-96 overflow-y-auto">
                        <template v-for="step in allSteps" :key="step.key as number">
                            <div class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs"
                                :class="step.type === 'delay' ? 'bg-amber-50 text-amber-700'
                                      : step.type === 'condition' ? 'bg-orange-50 text-orange-700'
                                      : 'bg-muted/40 text-foreground'">
                                <CampaignStepIcon :step="step" :size="14" class="shrink-0 opacity-80" />
                                <span class="font-medium">{{ step.label }}</span>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="lg:col-span-3 rounded-xl border border-border bg-card overflow-hidden flex flex-col">
                    <div class="border-b border-border px-4 py-3 flex flex-col gap-3">
                        <h2 class="text-sm font-semibold">Leads ({{ leads.total }})</h2>
                        <ListSearchBar v-model="leadSearch" placeholder="Search leads…" @search="applyLeadFilters">
                            <template #filters>
                                <select
                                    v-model="leadStatusFilter"
                                    class="rounded-lg border border-border bg-card px-3 py-2 text-sm"
                                    @change="applyLeadFilters"
                                >
                                    <option value="all">All statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="running">Running</option>
                                    <option value="done">Done</option>
                                    <option value="error">Error</option>
                                    <option value="skipped">Skipped</option>
                                </select>
                            </template>
                        </ListSearchBar>
                    </div>

                    <div v-if="leads.data.length === 0" class="flex flex-col items-center gap-3 p-8 text-center">
                        <Users class="h-8 w-8 text-muted-foreground/40" />
                        <p class="text-sm font-medium">No leads yet</p>
                    </div>

                    <div v-else class="overflow-auto">
                        <table class="w-full text-xs">
                            <thead class="border-b border-border bg-muted/40 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground">Lead</th>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground">Current step</th>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground">Progress</th>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                                    <th class="px-3 py-2 text-right font-medium text-muted-foreground">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="lead in leads.data" :key="lead.id" class="hover:bg-muted/20 transition-colors">
                                    <td class="px-3 py-2.5">
                                        <div class="font-medium text-foreground">{{ lead.full_name ?? '—' }}</div>
                                        <div class="text-[10px] text-muted-foreground truncate max-w-[140px]">{{ lead.headline ?? '' }}</div>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <template v-if="lead.progress?.current_node_label">
                                            <div class="font-medium text-blue-700">{{ lead.progress.current_node_label }}</div>
                                            <div v-if="lead.progress.next_run_at" class="text-[10px] text-amber-600">
                                                Scheduled {{ formatTime(lead.progress.next_run_at) }}
                                            </div>
                                        </template>
                                        <span v-else class="text-muted-foreground">Not started</span>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <template v-if="lead.progress">
                                            <div>{{ runStatusLabel(lead.progress.run_status) }}</div>
                                            <div v-if="lead.progress.acceptance_status !== null" class="text-[10px] text-muted-foreground">
                                                {{ lead.progress.acceptance_status ? '✓ Accepted' : '✕ Not accepted' }}
                                            </div>
                                        </template>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-1">
                                            <CheckCircle2 v-if="lead.status === 'done'" class="h-3.5 w-3.5 text-green-500" />
                                            <XCircle v-else-if="lead.status === 'error'" class="h-3.5 w-3.5 text-red-500" />
                                            <Clock v-else-if="lead.status === 'running'" class="h-3.5 w-3.5 text-blue-500 animate-pulse" />
                                            <AlertCircle v-else-if="lead.status === 'skipped'" class="h-3.5 w-3.5 text-yellow-500" />
                                            <span v-else class="h-3.5 w-3.5 rounded-full border-2 border-muted-foreground/30 inline-block" />
                                            <span class="capitalize text-muted-foreground">{{ lead.status }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2.5 text-right">
                                        <button
                                            type="button"
                                            @click="openLeadLogs(lead)"
                                            class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-[10px] font-medium text-muted-foreground hover:bg-muted hover:text-foreground transition-colors">
                                            <ScrollText class="h-3 w-3" /> Logs
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <ListPagination v-if="leads.data.length" :paginator="leads" label="leads" />
                </div>
            </div>

           
        </div>
    </div>

    <!-- Per-lead activity modal -->
    <Dialog v-model:open="leadLogOpen">
        <DialogContent class="sm:max-w-lg max-h-[85vh] flex flex-col">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <ScrollText class="h-4 w-4" />
                    {{ leadLogLead?.full_name ?? 'Lead' }} — activity
                </DialogTitle>
                <DialogDescription>
                    Step-by-step log for this lead only.
                    <span v-if="isRunning" class="text-green-600"> Updates live.</span>
                </DialogDescription>
            </DialogHeader>

            <div v-if="leadLogLoading" class="py-10 text-center text-sm text-muted-foreground">
                <Loader2 class="h-5 w-5 animate-spin mx-auto mb-2" /> Loading logs…
            </div>
            <div v-else-if="leadLogEvents.length === 0" class="py-10 text-center text-sm text-muted-foreground">
                No activity recorded for this lead yet.
            </div>
            <div v-else class="flex-1 overflow-y-auto -mx-1 px-1 divide-y divide-border min-h-0 max-h-[50vh]">
                <div v-for="event in leadLogEvents" :key="event.id"
                    class="py-2.5 flex items-start gap-3 text-xs first:pt-0">
                    <span class="shrink-0 mt-0.5 rounded border px-1.5 py-0.5 text-[10px] font-medium capitalize"
                        :class="eventStatusColor(event.status)">
                        {{ event.status }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-foreground">{{ event.message }}</p>
                        <p v-if="event.node_label" class="text-[10px] text-muted-foreground mt-0.5">
                            Step: {{ event.node_label }}
                        </p>
                    </div>
                    <span class="shrink-0 text-[10px] text-muted-foreground whitespace-nowrap">
                        {{ formatDateTime(event.executed_at) }}
                    </span>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
