<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import ChannelLimitsNoticeModal from '@/components/crm/ChannelLimitsNoticeModal.vue';
import type { ConnectedChannel, OutreachChannel } from '@/components/outreach/types';
import { Bot, Copy, LayoutGrid, List, Layers, Pause, Play, Plus, Search, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Multi-Channel Outreach', href: '/outreach' }] },
});

type CampaignRow = {
    id: number;
    name: string;
    template_type: string;
    status: string;
    created_at: string;
    outreach_leads_count: number;
    outreach_lists_count: number;
    primary_channel: OutreachChannel | string | null;
    channel_label: string | null;
};

const props = defineProps<{
    campaigns: {
        data: CampaignRow[];
        total: number;
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    hasOrg: boolean;
    connectedChannels: ConnectedChannel[];
    channelOptions: Array<{ channel: string; label: string }>;
    filters: { search: string | null; status: string | null; channel: string | null };
}>();

const VIEW_STORAGE_KEY = 'sf:outreach:view-mode';

const actionId = ref<number | null>(null);
const search = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? 'all');
const channelFilter = ref(props.filters.channel ?? 'all');
const viewMode = ref<'grid' | 'table'>('grid');
const selected = ref<Set<number>>(new Set());
const bulkBusy = ref(false);

onMounted(() => {
    const stored = localStorage.getItem(VIEW_STORAGE_KEY);
    if (stored === 'grid' || stored === 'table') {
        viewMode.value = stored;
    }
});

watch(viewMode, (mode) => {
    localStorage.setItem(VIEW_STORAGE_KEY, mode);
});

const selectableCampaigns = computed(() => props.campaigns.data);
const allSelected = computed(
    () => selectableCampaigns.value.length > 0 && selectableCampaigns.value.every((c) => selected.value.has(c.id)),
);
const selectedCount = computed(() => selected.value.size);

watch(
    () => props.campaigns.data.map((c) => c.id).join(','),
    () => {
        const visible = new Set(props.campaigns.data.map((c) => c.id));
        selected.value = new Set([...selected.value].filter((id) => visible.has(id)));
    },
);

function toggleSelect(id: number) {
    if (selected.value.has(id)) selected.value.delete(id);
    else selected.value.add(id);
    selected.value = new Set(selected.value);
}

function toggleSelectAll() {
    if (allSelected.value) {
        selected.value = new Set();
        return;
    }
    selected.value = new Set(selectableCampaigns.value.map((c) => c.id));
}

function applyFilters() {
    router.get('/outreach', {
        search: search.value.trim() || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
        channel: channelFilter.value !== 'all' ? channelFilter.value : undefined,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

function clearFilters() {
    search.value = '';
    statusFilter.value = 'all';
    channelFilter.value = 'all';
    applyFilters();
}

const hasActiveFilters = computed(
    () => (props.filters.search ?? '') !== '' || (props.filters.status ?? '') !== '' || (props.filters.channel ?? '') !== '',
);

const statusColor = (s: string) => {
    if (s === 'active' || s === 'running' || s === 'preparing') return 'bg-green-500/10 text-green-700 border-green-200';
    if (s === 'paused') return 'bg-yellow-500/10 text-yellow-700 border-yellow-200';
    if (s === 'draft') return 'bg-slate-500/10 text-slate-600 border-slate-200';
    if (s === 'completed') return 'bg-blue-500/10 text-blue-700 border-blue-200';
    return 'bg-muted text-muted-foreground border-border';
};

const statusLabel = (s: string) => {
    if (s === 'active' || s === 'running' || s === 'preparing') return 'Running';
    return s;
};

const connectedCount = () => props.connectedChannels.filter((c) => c.connected).length;

function canLaunch(c: CampaignRow) {
    return !['completed', 'running', 'active', 'preparing'].includes(c.status);
}

function canPause(c: CampaignRow) {
    return ['running', 'active', 'preparing'].includes(c.status);
}

function pauseCampaign(c: CampaignRow) {
    if (!confirm(`Pause "${c.name}"?`)) return;
    actionId.value = c.id;
    router.put(`/outreach/${c.id}`, { status: 'paused' }, { preserveScroll: true, onFinish: () => { actionId.value = null; } });
}

function launchCampaign(c: CampaignRow) {
    actionId.value = c.id;
    router.post(`/outreach/${c.id}/activate`, {}, { preserveScroll: true, onFinish: () => { actionId.value = null; } });
}

function deleteCampaign(c: CampaignRow) {
    if (!confirm(`Delete "${c.name}"?`)) return;
    actionId.value = c.id;
    router.delete(`/outreach/${c.id}`, { preserveScroll: true, onFinish: () => { actionId.value = null; } });
}

function duplicateCampaign(c: CampaignRow) {
    actionId.value = c.id;
    router.post(`/outreach/${c.id}/duplicate`, {}, { preserveScroll: true, onFinish: () => { actionId.value = null; } });
}

function bulkAction(action: 'pause' | 'launch' | 'delete') {
    const ids = Array.from(selected.value);
    if (ids.length === 0) return;

    const verb = action === 'delete' ? 'delete' : action === 'pause' ? 'pause' : 'launch';
    if (!confirm(`${verb.charAt(0).toUpperCase()}${verb.slice(1)} ${ids.length} campaign(s)?`)) return;

    bulkBusy.value = true;
    router.post('/outreach/bulk', { ids, action }, {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
            selected.value = new Set();
        },
    });
}

function formatCreatedAt(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>

<template>
    <Head title="Multi-Channel Outreach" />

    <div class="flex flex-col gap-5 p-4">
        <ChannelLimitsNoticeModal
            storage-key="sf:notice:channel-limits:outreach"
            variant="outreach"
        />
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold">Multi-Channel Outreach</h1>
                <p class="text-sm text-muted-foreground">Run LinkedIn, email, and messaging sequences — separate from extension campaigns.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Link href="/ai-employee" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700">
                    <Bot class="h-4 w-4" /> Ask Soci
                </Link>
                <Link href="/outreach/create" class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-medium hover:bg-muted">
                    <Plus class="h-4 w-4" /> New outreach
                </Link>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <p class="text-xs font-semibold uppercase text-muted-foreground">Connected channels</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <span
                    v-for="ch in connectedChannels"
                    :key="ch.channel"
                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                    :class="ch.connected ? 'border-green-200 bg-green-50 text-green-700' : 'border-border bg-muted/40 text-muted-foreground'"
                >
                    <OutreachChannelIcon :channel="ch.channel" class="h-3.5 w-3.5" />
                    {{ ch.label }}
                </span>
                <Link href="/integrations" class="text-xs text-primary hover:underline">{{ connectedCount() }}/{{ connectedChannels.length }} connected · Manage</Link>
            </div>
        </div>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800">
            Link your workspace to create outreach campaigns.
        </div>

        <template v-else>
            <div class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex min-w-[200px] flex-1 max-w-md items-center gap-2 rounded-lg border border-border bg-background px-3 py-2">
                        <Search class="h-4 w-4 shrink-0 text-muted-foreground" />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search campaigns…"
                            class="w-full bg-transparent text-sm outline-none"
                            @keydown.enter="applyFilters"
                        />
                    </div>
                    <select
                        v-model="statusFilter"
                        class="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                        @change="applyFilters"
                    >
                        <option value="all">All statuses</option>
                        <option value="running">Running</option>
                        <option value="paused">Paused</option>
                        <option value="draft">Draft</option>
                        <option value="completed">Completed</option>
                        <option value="stopped">Stopped</option>
                    </select>
                    <select
                        v-model="channelFilter"
                        class="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                        @change="applyFilters"
                    >
                        <option value="all">All channels</option>
                        <option v-for="opt in channelOptions" :key="opt.channel" :value="opt.channel">
                            {{ opt.label }}
                        </option>
                    </select>
                    <button type="button" class="rounded-lg border border-border bg-background px-3 py-2 text-sm font-medium hover:bg-muted" @click="applyFilters">
                        Apply
                    </button>
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="text-sm text-muted-foreground hover:text-foreground"
                        @click="clearFilters"
                    >
                        Clear
                    </button>
                    <div class="ml-auto flex items-center gap-1 rounded-lg border border-border bg-background p-1">
                        <button
                            type="button"
                            class="rounded-md p-1.5"
                            :class="viewMode === 'grid' ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                            title="Grid view"
                            @click="viewMode = 'grid'"
                        >
                            <LayoutGrid class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-1.5"
                            :class="viewMode === 'table' ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                            title="Table view"
                            @click="viewMode = 'table'"
                        >
                            <List class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div v-if="selectedCount > 0" class="flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm">
                    <span class="font-medium">{{ selectedCount }} selected</span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-green-200 bg-white px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-50"
                        :disabled="bulkBusy"
                        @click="bulkAction('launch')"
                    >
                        <Play class="h-3.5 w-3.5" /> Launch
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-yellow-200 bg-white px-2.5 py-1 text-xs font-medium text-yellow-700 hover:bg-yellow-50 disabled:opacity-50"
                        :disabled="bulkBusy"
                        @click="bulkAction('pause')"
                    >
                        <Pause class="h-3.5 w-3.5" /> Pause
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-white px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"
                        :disabled="bulkBusy"
                        @click="bulkAction('delete')"
                    >
                        <Trash2 class="h-3.5 w-3.5" /> Delete
                    </button>
                    <button type="button" class="text-xs text-muted-foreground hover:text-foreground" @click="selected = new Set()">
                        Clear
                    </button>
                </div>
            </div>

            <div v-if="campaigns.data.length === 0 && hasActiveFilters" class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center">
                <Search class="h-10 w-10 text-muted-foreground/40" />
                <p class="font-medium">No campaigns match your filters</p>
                <button type="button" class="text-sm text-primary hover:underline" @click="clearFilters">Clear filters</button>
            </div>

            <div v-else-if="campaigns.data.length === 0" class="flex flex-col items-center gap-4 rounded-xl border border-dashed p-10 text-center sm:p-14">
                <Layers class="h-10 w-10 text-muted-foreground/40" />
                <div>
                    <p class="font-medium">No outreach campaigns yet</p>
                    <p class="text-sm text-muted-foreground">Build a multichannel sequence and launch when channels are connected.</p>
                </div>
                <div class="flex flex-wrap justify-center gap-2">
                    <Link href="/ai-employee" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground">
                        <Bot class="h-4 w-4" /> Ask Soci
                    </Link>
                    <Link href="/outreach/create" class="rounded-lg border border-border bg-card px-4 py-2 text-sm font-medium hover:bg-muted">Create outreach</Link>
                </div>
            </div>

            <!-- Grid view -->
            <div v-else-if="viewMode === 'grid'" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="c in campaigns.data"
                    :key="c.id"
                    class="rounded-xl border border-border bg-card p-4 shadow-sm"
                    :class="selected.has(c.id) ? 'ring-2 ring-primary/30' : ''"
                >
                    <div class="flex items-start gap-3">
                        <button
                            type="button"
                            class="mt-0.5 rounded p-0.5 hover:bg-muted"
                            title="Select campaign"
                            @click="toggleSelect(c.id)"
                        >
                            <AppSelectionCheckbox :checked="selected.has(c.id)" />
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex min-w-0 items-start gap-2">
                                    <OutreachChannelIcon
                                        v-if="c.primary_channel"
                                        :channel="c.primary_channel"
                                        :size="22"
                                        class="mt-0.5"
                                        :title="c.channel_label ?? undefined"
                                    />
                                    <div class="min-w-0">
                                        <Link :href="`/outreach/${c.id}`" class="line-clamp-2 font-semibold hover:text-primary">{{ c.name }}</Link>
                                        <p v-if="c.channel_label" class="mt-0.5 text-[11px] text-muted-foreground">{{ c.channel_label }}</p>
                                    </div>
                                </div>
                                <span class="shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-medium capitalize" :class="statusColor(c.status)">
                                    {{ statusLabel(c.status) }}
                                </span>
                            </div>
                            <p class="mt-2 text-xs text-muted-foreground">
                                {{ c.outreach_leads_count }} leads · {{ c.outreach_lists_count }} lists
                                <span v-if="formatCreatedAt(c.created_at)"> · Created {{ formatCreatedAt(c.created_at) }}</span>
                            </p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 pl-8">
                        <button
                            v-if="canPause(c)"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-border bg-white px-2 py-1 text-xs font-medium shadow-sm hover:bg-muted disabled:opacity-50"
                            :disabled="actionId === c.id"
                            @click="pauseCampaign(c)"
                        >
                            <Pause class="h-3 w-3" /> Pause
                        </button>
                        <button
                            v-if="canLaunch(c)"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-green-200 bg-green-50 px-2 py-1 text-xs text-green-700 disabled:opacity-50"
                            :disabled="actionId === c.id"
                            @click="launchCampaign(c)"
                        >
                            <Play class="h-3 w-3" /> Launch
                        </button>
                        <Link :href="`/outreach/${c.id}/edit`" class="inline-flex items-center gap-1 rounded-lg border border-border bg-white px-2 py-1 text-xs font-medium shadow-sm hover:bg-muted">Edit</Link>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-border bg-white px-2 py-1 text-xs font-medium shadow-sm hover:bg-muted disabled:opacity-50" :disabled="actionId === c.id" @click="duplicateCampaign(c)">
                            <Copy class="h-3 w-3" /> Copy
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-white px-2 py-1 text-xs font-medium text-red-600 shadow-sm hover:bg-red-50 disabled:opacity-50" :disabled="actionId === c.id" @click="deleteCampaign(c)">
                            <Trash2 class="h-3 w-3" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Table view -->
            <div v-else class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b border-border bg-muted/40">
                        <tr>
                            <th class="w-10 px-3 py-3">
                                <button
                                    type="button"
                                    class="rounded p-0.5 hover:bg-muted disabled:opacity-40"
                                    :disabled="selectableCampaigns.length === 0"
                                    title="Select all on this page"
                                    @click="toggleSelectAll"
                                >
                                    <AppSelectionCheckbox :checked="allSelected" />
                                </button>
                            </th>
                            <th class="w-10 px-2 py-3 text-left font-medium text-muted-foreground">Channel</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Campaign</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Leads</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Created</th>
                            <th class="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="c in campaigns.data"
                            :key="c.id"
                            class="transition hover:bg-muted/30"
                            :class="selected.has(c.id) ? 'bg-primary/[0.03]' : ''"
                        >
                            <td class="px-3 py-3">
                                <button type="button" class="rounded p-0.5 hover:bg-muted" @click="toggleSelect(c.id)">
                                    <AppSelectionCheckbox :checked="selected.has(c.id)" />
                                </button>
                            </td>
                            <td class="px-2 py-3">
                                <OutreachChannelIcon
                                    v-if="c.primary_channel"
                                    :channel="c.primary_channel"
                                    :size="20"
                                    :title="c.channel_label ?? undefined"
                                />
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td class="max-w-xs px-4 py-3">
                                <Link :href="`/outreach/${c.id}`" class="font-medium hover:text-primary">{{ c.name }}</Link>
                                <p v-if="c.channel_label" class="text-[11px] text-muted-foreground">{{ c.channel_label }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full border px-2 py-0.5 text-[10px] font-medium capitalize" :class="statusColor(c.status)">
                                    {{ statusLabel(c.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ c.outreach_leads_count }} · {{ c.outreach_lists_count }} lists</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ formatCreatedAt(c.created_at) || '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1">
                                    <button
                                        v-if="canPause(c)"
                                        type="button"
                                        class="rounded border border-border px-2 py-1 text-xs hover:bg-muted disabled:opacity-50"
                                        :disabled="actionId === c.id"
                                        title="Pause"
                                        @click="pauseCampaign(c)"
                                    >
                                        <Pause class="h-3 w-3" />
                                    </button>
                                    <button
                                        v-if="canLaunch(c)"
                                        type="button"
                                        class="rounded border border-green-200 bg-green-50 px-2 py-1 text-xs text-green-700 disabled:opacity-50"
                                        :disabled="actionId === c.id"
                                        title="Launch"
                                        @click="launchCampaign(c)"
                                    >
                                        <Play class="h-3 w-3" />
                                    </button>
                                    <Link :href="`/outreach/${c.id}/edit`" class="rounded border border-border px-2 py-1 text-xs hover:bg-muted">Edit</Link>
                                    <button type="button" class="rounded border border-border px-2 py-1 text-xs hover:bg-muted disabled:opacity-50" :disabled="actionId === c.id" @click="duplicateCampaign(c)">
                                        <Copy class="h-3 w-3" />
                                    </button>
                                    <button type="button" class="rounded border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-50" :disabled="actionId === c.id" @click="deleteCampaign(c)">
                                        <Trash2 class="h-3 w-3" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="campaigns.links && campaigns.links.length > 3" class="flex flex-wrap justify-center gap-1 pt-2">
                <template v-for="(link, i) in campaigns.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="rounded-md border px-3 py-1 text-xs"
                        :class="link.active ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted'"
                        v-html="link.label"
                    />
                    <span v-else class="px-2 py-1 text-xs text-muted-foreground" v-html="link.label" />
                </template>
            </div>
        </template>
    </div>
</template>
