<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import {
    ArrowLeft,
    Download,
    ExternalLink,
    Loader2,
    Mail,
    MailCheck,
    RefreshCw,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

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
    source: 'aud' | 'sn';
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
    };
    listId: string;
    listRecordId: number | null;
    listName: string;
    src: 'aud' | 'sn';
    emailFilter: string;
    search: string;
    counts: Record<string, number>;
    dailyLimit: DailyLimit | null;
    pendingCount: number;
}>();

const searchTerm = ref(props.search ?? '');
const selected = ref<Set<number>>(new Set());
const busy = ref(false);
const fetchingId = ref<number | null>(null);
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

const hasPending = computed(() => props.leads.data.some((l) => ['pending', 'processing'].includes(l.email_fetch_status ?? '')));

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

async function fetchEmail(lead: Lead) {
    fetchingId.value = lead.id;
    try {
        const body = props.src === 'sn'
            ? { lead_id: lead.id }
            : { audience_list_id: lead.id };
        const res = await fetch(`/leads/${props.listId}/fetch-email?src=${props.src}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (res.ok) {
            setFlash(data.message);
            router.reload({ only: ['leads', 'counts', 'dailyLimit', 'pendingCount'] });
        } else {
            setFlash(data.message || 'Failed to fetch email.', true);
        }
    } catch {
        setFlash('Network error.', true);
    } finally {
        fetchingId.value = null;
    }
}

async function fetchBatch() {
    if (selected.value.size === 0) return;
    busy.value = true;
    try {
        const res = await fetch(`/leads/${props.listId}/fetch-email-batch?src=aud`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify({ audience_list_ids: Array.from(selected.value) }),
        });
        const data = await res.json();
        if (res.ok) {
            setFlash(data.message);
            selected.value = new Set();
            router.reload({ only: ['leads', 'counts', 'dailyLimit', 'pendingCount'] });
        } else {
            setFlash(data.message || 'Failed to fetch emails.', true);
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
        const res = await fetch(`/leads/${props.listId}/export?src=${props.src}`, { headers: { Accept: 'application/json' } });
        const json = await res.json();
        const rows: Record<string, unknown>[] = json.data ?? [];
        if (rows.length === 0) {
            setFlash('Nothing to export.', true);
            return;
        }
        const headers = Object.keys(rows[0]);
        const escape = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`;
        const csv = [headers.join(','), ...rows.map((r) => headers.map((h) => escape(r[h])).join(','))].join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${props.listName.replace(/[^a-z0-9]+/gi, '_')}_leads.csv`;
        a.click();
        URL.revokeObjectURL(url);
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
            router.reload({ only: ['leads', 'counts', 'dailyLimit', 'pendingCount'] });
        }
    }, 15000);
});
onBeforeUnmount(() => {
    if (poll) clearInterval(poll);
});

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
                    <h1 class="text-xl font-semibold text-foreground">{{ listName }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ leads.total.toLocaleString() }} leads ·
                        <span :class="src === 'aud' ? 'text-blue-600' : 'text-amber-600'">{{ src === 'aud' ? 'Audience' : 'Sales Navigator' }}</span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <AppToolbarButton variant="success" :disabled="busy" @click="exportCsv">
                    <Download class="h-4 w-4" /> Export CSV
                </AppToolbarButton>
                <AppToolbarButton variant="dangerGradient" @click="deleteList">
                    <Trash2 class="h-4 w-4" /> Delete list
                </AppToolbarButton>
                <AppToolbarButton variant="info" @click="router.reload({ only: ['leads', 'counts', 'dailyLimit', 'pendingCount'] })">
                    <RefreshCw class="h-4 w-4" /> Refresh
                </AppToolbarButton>
            </div>
        </div>

        <p v-if="flash" class="rounded-lg bg-green-100 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ flash }}</p>
        <p v-if="flashError" class="rounded-lg bg-red-100 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ flashError }}</p>

        <!-- Daily limit -->
        <div v-if="dailyLimit" class="rounded-xl border border-border bg-card p-4">
            <div class="mb-1 flex items-center justify-between text-sm">
                <span class="font-medium">Daily email enrichment</span>
                <span class="text-muted-foreground">{{ dailyLimit.used }} / {{ dailyLimit.daily_limit }} used · {{ dailyLimit.remaining }} left</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div class="h-full rounded-full bg-gradient-to-b from-blue-500 to-blue-600 transition-all" :style="{ width: `${Math.min(100, (dailyLimit.used / dailyLimit.daily_limit) * 100)}%` }"></div>
            </div>
            <p v-if="src === 'aud' && pendingCount > 0" class="mt-2 text-xs text-amber-600">{{ pendingCount }} email fetch job(s) in progress…</p>
            <p v-else-if="src === 'sn'" class="mt-2 text-xs text-muted-foreground">
                Sales Navigator lists: click <strong>Fetch</strong> per lead to load email from the LinkedIn profile via Unipile when the member has shared it.
            </p>
            <p v-else class="mt-2 text-xs text-muted-foreground">Email enrichment uses Unipile profile lookup when the member has shared an address.</p>
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
            <button v-if="src === 'aud'" type="button" :disabled="busy" class="inline-flex items-center gap-1.5 rounded-md bg-gradient-to-b from-blue-500 to-blue-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-60" @click="fetchBatch">
                <Loader2 v-if="busy" class="h-3.5 w-3.5 animate-spin" /><Mail v-else class="h-3.5 w-3.5" /> Fetch emails
            </button>
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
        <div v-else class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40 text-left text-xs uppercase text-muted-foreground">
                    <tr>
                        <th class="w-10 px-4 py-3">
                            <button type="button" @click="toggleAll">
                                <AppSelectionCheckbox :checked="allSelected" />
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Headline</th>
                        <th class="px-4 py-3 font-medium">Location</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th v-if="src === 'sn'" class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="lead in leads.data" :key="lead.id" class="hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <button type="button" @click="toggle(lead.id)">
                                <AppSelectionCheckbox :checked="selected.has(lead.id)" />
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-foreground">{{ lead.name }}</span>
                                <span v-if="lead.network_distance" class="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">{{ distanceLabel(lead.network_distance) }}</span>
                            </div>
                        </td>
                        <td class="max-w-[260px] truncate px-4 py-3 text-muted-foreground">{{ lead.headline || '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ lead.location || '—' }}</td>
                        <td class="px-4 py-3">
                            <span v-if="lead.email" class="inline-flex items-center gap-1.5 text-green-600">
                                <MailCheck class="h-4 w-4" /> {{ lead.email }}
                            </span>
                            <span v-else-if="['pending', 'processing'].includes(lead.email_fetch_status ?? '')" class="inline-flex items-center gap-1.5 text-amber-600">
                                <Loader2 class="h-3.5 w-3.5 animate-spin" /> Fetching…
                            </span>
                            <span v-else-if="lead.email_fetch_status === 'completed'" class="text-xs text-muted-foreground">Not found</span>
                            <button
                                v-else
                                type="button"
                                :disabled="fetchingId === lead.id || (dailyLimit ? !dailyLimit.can_scrape : false)"
                                class="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1 text-xs font-medium hover:bg-muted disabled:opacity-50"
                                @click="fetchEmail(lead)"
                            >
                                <Loader2 v-if="fetchingId === lead.id" class="h-3.5 w-3.5 animate-spin" /><Mail v-else class="h-3.5 w-3.5" /> Fetch
                            </button>
                        </td>
                        <td v-if="src === 'sn'" class="px-4 py-3">
                            <select
                                class="rounded-md border border-border bg-background px-2 py-1 text-xs outline-none focus:border-primary"
                                :value="lead.outreach_status || 'new'"
                                @change="updateLeadStatus(lead, ($event.target as HTMLSelectElement).value)"
                            >
                                <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <a v-if="lead.profile_url" :href="lead.profile_url" target="_blank" rel="noopener noreferrer" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="View profile"><ExternalLink class="h-4 w-4" /></a>
                                <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-red-500" title="Delete" @click="deleteLead(lead)"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="leads.data.length" class="flex items-center justify-between text-sm text-muted-foreground">
            <span>Page {{ leads.current_page }} of {{ leads.last_page }}</span>
            <div class="flex gap-2">
                <Link v-if="leads.prev_page_url" :href="leads.prev_page_url" class="rounded border border-border px-3 py-1 hover:bg-muted" preserve-scroll>Prev</Link>
                <Link v-if="leads.next_page_url" :href="leads.next_page_url" class="rounded border border-border px-3 py-1 hover:bg-muted" preserve-scroll>Next</Link>
            </div>
        </div>
    </div>
</template>
