<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import BulkEnrichButton from '@/components/crm/BulkEnrichButton.vue';
import EmailEnrichmentInfoTooltip from '@/components/crm/EmailEnrichmentInfoTooltip.vue';
import { refreshDailyEnrichmentQuota } from '@/composables/useDailyEnrichmentQuota';
import LeadContactTags, { type LeadContacts } from '@/components/crm/LeadContactTags.vue';
import LeadEnrichmentField from '@/components/crm/LeadEnrichmentField.vue';
import LeadEnrichmentStatCard from '@/components/crm/LeadEnrichmentStatCard.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import ListPagination from '@/components/crm/ListPagination.vue';
import {
    ArrowLeft,
    Download,
    ExternalLink,
    Loader2,
    Mail,
    Phone,
    RefreshCw,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Leads', href: '/leads' },
        ],
    },
});

interface Lead {
    id: number;
    name: string;
    email: string | null;
    headline: string | null;
    location: string | null;
    profileid: string | null;
    public_identifier: string | null;
    profile_url: string | null;
    network_distance: string | null;
    outreach_status: string | null;
    email_fetch_status: string | null;
    email_fetch_attempted_at: string | null;
    contacts: LeadContacts;
    company_name: string | null;
    company_domain: string | null;
    company_logo_url: string | null;
    source: 'aud' | 'sn';
}

interface ContactStatBucket {
    found: number;
    total: number;
    pending: number;
    searched: number;
    fill_percent: number;
    hit_rate: number;
}

interface ContactStats {
    total: number;
    running: boolean;
    processed: number;
    fetchable: number;
    emails: ContactStatBucket;
    phones: ContactStatBucket;
}

interface DailyLimit {
    daily_limit: number;
    used: number;
    remaining: number;
    can_scrape: boolean;
    reset_date: string | null;
}

const props = defineProps<{
    leads: {
        data: Lead[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    listId: string;
    listRecordId: number | null;
    listName: string;
    src: 'aud' | 'sn';
    emailFilter: string;
    search: string;
    counts: Record<string, number>;
    contactStats: ContactStats | null;
    dailyLimit: DailyLimit | null;
    pendingCount: number;
    enrichBatchSize?: number;
}>();

const ENRICH_BATCH_SIZE = computed(() => Math.max(1, props.enrichBatchSize ?? 25));

const searchTerm = ref(props.search ?? '');
const selected = ref<Set<number>>(new Set());
const busy = ref(false);
const enrichingIds = ref<Set<number>>(new Set());
const flash = ref('');
const flashError = ref('');

const filters = [
    { key: 'all', label: 'All' },
    { key: 'with_email', label: 'With email' },
    { key: 'without_email', label: 'No email found' },
    { key: 'not_fetched', label: 'Not fetched' },
    { key: 'pending', label: 'Pending' },
];

const statusOptions = [
    { value: 'new', label: 'New' },
    { value: 'contacted', label: 'Contacted' },
    { value: 'connected', label: 'Connected' },
    { value: 'replied', label: 'Replied' },
    { value: 'not_interested', label: 'Not interested' },
];

const hasPending = computed(() =>
    enrichingIds.value.size > 0
    || props.pendingCount > 0
    || props.leads.data.some((l) => ['pending', 'processing'].includes(l.email_fetch_status ?? '')),
);

const inFlightEnrichments = computed(() =>
    Math.max(props.pendingCount, enrichingIds.value.size),
);

const bulkQueueNow = computed(() => {
    const fetchable = props.contactStats?.fetchable ?? 0;
    if (fetchable <= 0 || !canStartEnrich()) {
        return 0;
    }
    const dailyRemaining = props.dailyLimit?.remaining ?? fetchable;
    const slots = Math.max(0, ENRICH_BATCH_SIZE.value - props.pendingCount);

    return Math.min(
        ENRICH_BATCH_SIZE.value,
        fetchable,
        slots,
        Math.max(0, dailyRemaining),
    );
});

function isLeadEnriching(lead: Lead): boolean {
    return enrichingIds.value.has(lead.id)
        || ['pending', 'processing'].includes(lead.email_fetch_status ?? '');
}

function canStartEnrich(excludeLeadId?: number): boolean {
    if (props.dailyLimit && !props.dailyLimit.can_scrape) {
        return false;
    }
    const localCount = excludeLeadId
        ? [...enrichingIds.value].filter((id) => id !== excludeLeadId).length
        : enrichingIds.value.size;

    // Same rule as backend: one batch wave — block when a full batch is already in flight.
    return Math.max(props.pendingCount, localCount) < ENRICH_BATCH_SIZE.value;
}

watch(
    () => props.leads.data.map((l) => ({ id: l.id, status: l.email_fetch_status })),
    (rows) => {
        const next = new Set(enrichingIds.value);
        let changed = false;

        for (const row of rows) {
            if (!next.has(row.id)) {
                continue;
            }
            if (row.status === 'completed' || row.status === 'failed') {
                next.delete(row.id);
                changed = true;
            }
        }

        if (changed) {
            enrichingIds.value = next;
        }
    },
    { deep: true },
);

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

function go(params: Record<string, string | undefined>) {
    router.get(
        `/leads/${props.listId}`,
        { src: props.src, email_filter: props.emailFilter, search: searchTerm.value || undefined, ...params },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setFilter(key: string) {
    go({ email_filter: key });
}

function runSearch() {
    go({ search: searchTerm.value || undefined });
}

function toggle(id: number) {
    if (selected.value.has(id)) selected.value.delete(id);
    else selected.value.add(id);
    selected.value = new Set(selected.value);
}

const allSelected = computed(() => props.leads.data.length > 0 && props.leads.data.every((l) => selected.value.has(l.id)));

function toggleAll() {
    if (allSelected.value) {
        selected.value = new Set();
    } else {
        selected.value = new Set(props.leads.data.map((l) => l.id));
    }
}

function setFlash(msg: string, error = false) {
    flash.value = error ? '' : msg;
    flashError.value = error ? msg : '';
    setTimeout(() => {
        flash.value = '';
        flashError.value = '';
    }, 5000);
}

let reloadTimer: ReturnType<typeof setTimeout> | null = null;

function scheduleLeadsReload() {
    if (reloadTimer) {
        clearTimeout(reloadTimer);
    }
    reloadTimer = setTimeout(() => {
        router.reload({
            only: ['leads', 'counts', 'contactStats', 'dailyLimit', 'pendingCount'],
            preserveScroll: true,
        });
        void refreshDailyEnrichmentQuota();
    }, 400);
}

function removeEnrichingId(id: number) {
    const next = new Set(enrichingIds.value);
    next.delete(id);
    enrichingIds.value = next;
}

async function pollEnrichmentResult(audienceListId: number): Promise<'found' | 'not_found' | 'timeout'> {
    for (let attempt = 0; attempt < 50; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, attempt === 0 ? 1000 : 2000));

        const res = await fetch(`/leads/${props.listId}/check-email/${audienceListId}?src=aud`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));

        if (data.has_email) {
            return 'found';
        }
        if (data.email_fetch_completed) {
            return 'not_found';
        }
    }

    return 'timeout';
}

async function enrichLead(lead: Lead) {
    if (isLeadEnriching(lead)) {
        return;
    }

    if (!canStartEnrich()) {
        setFlash(
            `You have ${inFlightEnrichments.value} enrichment${inFlightEnrichments.value === 1 ? '' : 's'} running. Wait for the current batch (up to ${ENRICH_BATCH_SIZE.value}) to finish.`,
            true,
        );
        return;
    }

    enrichingIds.value = new Set(enrichingIds.value).add(lead.id);

    try {
        const body = props.src === 'sn'
            ? { lead_id: lead.id }
            : { audience_list_id: lead.id };
        const res = await fetch(`/leads/${props.listId}/enrich?src=${props.src}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();

        if (!res.ok) {
            setFlash(data.message || 'Failed to enrich.', true);
            removeEnrichingId(lead.id);
            return;
        }

        setFlash(data.message);
        scheduleLeadsReload();
        void refreshDailyEnrichmentQuota();

        if (props.src === 'aud' && !data.email) {
            await pollEnrichmentResult(lead.id);
            scheduleLeadsReload();
        }
    } catch {
        setFlash('Network error.', true);
        removeEnrichingId(lead.id);
    } finally {
        removeEnrichingId(lead.id);
    }
}

const canBulkEnrich = computed(() => (props.contactStats?.fetchable ?? 0) > 0 && canStartEnrich());

async function enrichNextBatch() {
    if (!canBulkEnrich.value || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const res = await fetch(`/leads/${props.listId}/enrich-batch?src=${props.src}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify({ auto_batch: true }),
        });
        const data = await res.json();
        if (res.ok) {
            setFlash(data.message);
            scheduleLeadsReload();
            void refreshDailyEnrichmentQuota();
        } else {
            setFlash(data.message || 'Failed to enrich leads.', true);
        }
    } catch {
        setFlash('Network error.', true);
    } finally {
        busy.value = false;
    }
}

function deleteSelected() {
    if (selected.value.size === 0) return;
    if (!confirm(`Delete ${selected.value.size} selected lead(s)?`)) return;
    router.delete('/leads/bulk', {
        data: { src: props.src, ids: Array.from(selected.value) },
        preserveScroll: true,
        onSuccess: () => {
            selected.value = new Set();
        },
    });
}

function deleteLead(lead: Lead) {
    if (!confirm('Delete this lead?')) return;
    router.delete(`/leads/lead/${lead.id}?src=${props.src}`, { preserveScroll: true });
}

function deleteList() {
    if (!confirm(`Delete "${props.listName}" and all its leads? This cannot be undone.`)) return;
    router.delete(`/leads/lists/${encodeURIComponent(props.listId)}?src=${props.src}`);
}

function updateLeadStatus(lead: Lead, outreach_status: string) {
    router.patch(`/leads/lead/${lead.id}/status`, { src: props.src, outreach_status }, { preserveScroll: true });
}

async function exportCsv() {
    busy.value = true;
    try {
        const a = document.createElement('a');
        a.href = `/leads/${props.listId}/export?src=${props.src}`;
        a.download = `${props.listName.replace(/[^a-z0-9]+/gi, '_')}_leads.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
    } catch {
        setFlash('Export failed.', true);
    } finally {
        busy.value = false;
    }
}

let poll: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    poll = setInterval(() => {
        if (hasPending.value) {
            scheduleLeadsReload();
        }
    }, 5000);
});
onBeforeUnmount(() => {
    if (poll) {
        clearInterval(poll);
    }
    if (reloadTimer) {
        clearTimeout(reloadTimer);
    }
});

function leadContacts(lead: Lead): LeadContacts {
    return {
        ...lead.contacts,
        email_fetch_status: lead.contacts.email_fetch_status ?? lead.email_fetch_status,
    };
}

function distanceLabel(d: string | null): string {
    if (!d) return '';
    const map: Record<string, string> = { DISTANCE_1: '1st', DISTANCE_2: '2nd', DISTANCE_3: '3rd' };
    return map[d] ?? d;
}
</script>

<template>
    <Head :title="listName" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Link href="/leads" class="rounded-lg border border-border p-2 hover:bg-muted"><ArrowLeft class="h-4 w-4" /></Link>
                <div>
                    <LinkedInPageHeading :title="listName" show-badge>
                        <template #subtitle>
                            {{ leads.total.toLocaleString() }} leads ·
                            <span :class="src === 'aud' ? 'text-blue-600' : 'text-amber-600'">{{ src === 'aud' ? 'Audience' : 'Sales Navigator' }}</span>
                        </template>
                    </LinkedInPageHeading>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <AppToolbarButton variant="success" :disabled="busy" @click="exportCsv">
                    <Download class="h-4 w-4" /> Export CSV
                </AppToolbarButton>
                <AppToolbarButton variant="dangerGradient" @click="deleteList">
                    <Trash2 class="h-4 w-4" /> Delete list
                </AppToolbarButton>
                <AppToolbarButton variant="info" @click="router.reload({ only: ['leads', 'counts', 'contactStats', 'dailyLimit', 'pendingCount'] })">
                    <RefreshCw class="h-4 w-4" /> Refresh
                </AppToolbarButton>
            </div>
        </div>

        <p v-if="flash" class="rounded-lg bg-green-100 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ flash }}</p>
        <p v-if="flashError" class="rounded-lg bg-red-100 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ flashError }}</p>

        <!-- Enrichment stats -->
        <div v-if="contactStats" class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Contact enrichment</p>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        {{ contactStats.total.toLocaleString() }} leads
                    </p>
                </div>
                <div v-if="inFlightEnrichments > 0" class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1.5 text-xs font-medium">
                    <Loader2 class="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                    Enriching
                    <span class="rounded-full bg-muted px-2 py-0.5 tabular-nums text-foreground">
                        {{ inFlightEnrichments }} pending
                    </span>
                </div>
                <div v-else-if="contactStats.running" class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1.5 text-xs font-medium">
                    <Loader2 class="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                    Running
                    <span class="rounded-full bg-muted px-2 py-0.5 tabular-nums text-foreground">
                        {{ contactStats.processed }}/{{ contactStats.total }}
                    </span>
                </div>
                <BulkEnrichButton
                    v-if="canBulkEnrich || busy"
                    :loading="busy"
                    :disabled="!canBulkEnrich"
                    :remaining="contactStats.fetchable"
                    :queue-now="bulkQueueNow"
                    :batch-size="ENRICH_BATCH_SIZE"
                    @click="enrichNextBatch"
                />
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <LeadEnrichmentStatCard
                    :icon="Mail"
                    label="Work emails"
                    :found="contactStats.emails.found"
                    :total="contactStats.emails.total"
                    :fill-percent="contactStats.emails.fill_percent"
                    :hit-rate="contactStats.emails.searched > 0 ? contactStats.emails.hit_rate : contactStats.emails.fill_percent"
                    source-label="LinkedIn"
                />
                <LeadEnrichmentStatCard
                    :icon="Phone"
                    label="Mobile phones"
                    :found="contactStats.phones.found"
                    :total="contactStats.phones.total"
                    :fill-percent="contactStats.phones.fill_percent"
                    :hit-rate="contactStats.phones.searched > 0 ? contactStats.phones.hit_rate : contactStats.phones.fill_percent"
                    source-label="LinkedIn"
                />
            </div>
        </div>

        <!-- Filters + search -->
        <div class="flex flex-wrap items-center gap-3">
            <div v-if="src === 'aud'" class="flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1">
                <button
                    v-for="f in filters"
                    :key="f.key"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="emailFilter === f.key ? 'bg-gradient-to-b from-blue-500 to-blue-600 text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
                    @click="setFilter(f.key)"
                >
                    {{ f.label }}<span v-if="counts[f.key] !== undefined" class="ml-1 opacity-70">({{ counts[f.key] }})</span>
                </button>
            </div>
            <div class="flex flex-1 items-center gap-2 rounded-lg border border-border bg-card px-3 py-2" style="min-width: 200px">
                <Search class="h-4 w-4 text-muted-foreground" />
                <input v-model="searchTerm" type="text" placeholder="Search leads…" class="w-full bg-transparent text-sm outline-none" @keyup.enter="runSearch" />
            </div>
        </div>

        <!-- Bulk bar -->
        <div v-if="selected.size > 0" class="flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm">
            <span class="font-medium">{{ selected.size }} selected</span>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-md bg-gradient-to-b from-red-500 to-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm shadow-red-950/20 ring-1 ring-inset ring-white/15 hover:from-red-500 hover:to-red-700 disabled:opacity-60" @click="deleteSelected">
                <Trash2 class="h-3.5 w-3.5" /> Delete
            </button>
            <button type="button" class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selected = new Set()">Clear</button>
        </div>

        <!-- Empty -->
        <div v-if="leads.data.length === 0" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <Mail class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No leads match this view</p>
        </div>

        <!-- Table -->
        <div v-else class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                            <th class="w-10 px-3 py-3">
                                <button type="button" @click="toggleAll">
                                    <AppSelectionCheckbox :checked="allSelected" />
                                </button>
                            </th>
                            <th class="px-4 py-3">Contact</th>
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3">Phone</th>
                            <th class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5">
                                    Work email
                                    <EmailEnrichmentInfoTooltip side="top" align="start" />
                                </span>
                            </th>
                            <th class="px-4 py-3">Channels</th>
                            <th v-if="src === 'sn'" class="px-4 py-3">Status</th>
                            <th class="w-20 px-3 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="lead in leads.data"
                            :key="lead.id"
                            class="group border-b border-border/70 transition-colors last:border-b-0 hover:bg-muted/20"
                            :class="selected.has(lead.id) ? 'bg-muted/30 ring-1 ring-inset ring-primary/15' : ''"
                        >
                            <td class="relative px-3 py-4">
                                <span
                                    v-if="selected.has(lead.id)"
                                    class="absolute inset-y-0 left-0 w-0.5 bg-primary"
                                />
                                <button type="button" @click="toggle(lead.id)">
                                    <AppSelectionCheckbox :checked="selected.has(lead.id)" />
                                </button>
                            </td>
                            <td class="px-4 py-4">
                                <div class="min-w-[180px]">
                                    <div class="flex items-center gap-2">
                                        <a
                                            v-if="lead.profile_url"
                                            :href="lead.profile_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="font-semibold text-foreground hover:underline"
                                        >
                                            {{ lead.name }}
                                        </a>
                                        <p v-else class="font-semibold text-foreground">{{ lead.name }}</p>
                                        <span v-if="lead.network_distance" class="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">{{ distanceLabel(lead.network_distance) }}</span>
                                    </div>
                                    <p v-if="lead.headline" class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{{ lead.headline }}</p>
                                    <p v-else-if="lead.location" class="mt-0.5 text-xs text-muted-foreground">{{ lead.location }}</p>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div v-if="lead.company_domain || lead.company_name" class="flex min-w-[140px] items-center gap-2.5">
                                    <img
                                        v-if="lead.company_logo_url"
                                        :src="lead.company_logo_url"
                                        :alt="lead.company_name || lead.company_domain || 'Company'"
                                        class="h-7 w-7 shrink-0 rounded-md border border-border bg-white object-contain p-0.5"
                                        loading="lazy"
                                    />
                                    <div v-else class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-[10px] font-semibold uppercase text-muted-foreground">
                                        {{ (lead.company_name || lead.company_domain || '?').slice(0, 1) }}
                                    </div>
                                    <span class="truncate text-sm text-foreground" :title="lead.company_domain || lead.company_name || ''">
                                        {{ lead.company_domain || lead.company_name }}
                                    </span>
                                </div>
                                <span v-else class="text-sm text-muted-foreground/50">—</span>
                            </td>
                            <td class="px-4 py-4">
                                <LeadEnrichmentField
                                    type="phone"
                                    :value="lead.contacts.phone"
                                    :fetch-status="isLeadEnriching(lead) ? 'processing' : lead.contacts.phone_fetch_status"
                                    :fetch-attempted="lead.contacts.phone_fetch_attempted === true"
                                    :fetching="isLeadEnriching(lead)"
                                />
                            </td>
                            <td class="px-4 py-4">
                                <LeadEnrichmentField
                                    type="email"
                                    :value="lead.email"
                                    :fetch-status="isLeadEnriching(lead) ? 'processing' : lead.email_fetch_status"
                                    :fetch-attempted="!!lead.email_fetch_attempted_at"
                                    :fetching="isLeadEnriching(lead)"
                                    :can-fetch="true"
                                    :fetch-disabled="!canStartEnrich(lead.id) || (dailyLimit ? !dailyLimit.can_scrape : false)"
                                    @fetch="enrichLead(lead)"
                                />
                            </td>
                            <td class="px-4 py-4">
                                <LeadContactTags :contacts="leadContacts(lead)" :show-email="false" />
                            </td>
                            <td v-if="src === 'sn'" class="px-4 py-4">
                                <select
                                    class="rounded-md border border-border bg-background px-2 py-1 text-xs outline-none focus:border-primary"
                                    :value="lead.outreach_status || 'new'"
                                    @change="updateLeadStatus(lead, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-4">
                                <div class="flex items-center justify-end gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
                                    <a v-if="lead.profile_url" :href="lead.profile_url" target="_blank" rel="noopener noreferrer" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="View profile"><ExternalLink class="h-4 w-4" /></a>
                                    <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-red-500" title="Delete" @click="deleteLead(lead)"><Trash2 class="h-4 w-4" /></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ListPagination v-if="leads.data.length" :paginator="leads" label="leads" />
        </div>
    </div>
</template>
