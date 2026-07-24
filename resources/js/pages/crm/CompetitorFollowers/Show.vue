<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Download, Loader2, Mail } from '@lucide/vue';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ListPagination from '@/components/crm/ListPagination.vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Competitor Active Followers', href: '/competitor-followers' },
        ],
    },
});

interface FollowerRow {
    id: number;
    con_first_name: string | null;
    con_last_name: string | null;
    con_job_title: string | null;
    con_company_name: string | null;
    con_location: string | null;
    con_profile_url: string | null;
    con_email: string | null;
    con_public_identifier: string | null;
    email_fetch_status: string | null;
    email_fetch_attempted_at: string | null;
}

const props = defineProps<{
    audience: {
        id: number;
        audience_id: number;
        audience_name: string | null;
        company_url: string | null;
        followers_count: number;
    };
    list: {
        data: FollowerRow[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    emailFilter: string;
    filters: { search: string | null };
    pendingEmailFetchCount: number;
    dailyLimit: { daily_limit: number; used: number; remaining: number; can_scrape: boolean; reset_date: string | null };
}>();

const emailFilters = [
    { key: 'all', label: 'All' },
    { key: 'with_email', label: 'With email' },
    { key: 'without_email', label: 'No email' },
    { key: 'not_found', label: 'Not found' },
    { key: 'not_fetched', label: 'Not fetched' },
    { key: 'pending', label: 'Pending' },
];

function csrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function api(url: string, method = 'POST', body?: unknown) {
    const res = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: method === 'GET' || method === 'HEAD' ? undefined : body ? JSON.stringify(body) : undefined,
    });
    return { ok: res.ok, data: await res.json().catch(() => ({})) };
}

function isFetching(row: FollowerRow): boolean {
    return fetchingIds.value.has(row.id)
        || row.email_fetch_status === 'pending'
        || row.email_fetch_status === 'processing';
}

async function pollEmailResult(audienceListId: number, maxAttempts = 25): Promise<{ found: boolean; email?: string }> {
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, attempt === 0 ? 800 : 2000));

        const res = await fetch(`/competitor-followers/${props.audience.id}/check-email/${audienceListId}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));

        if (data.has_email && data.email) {
            return { found: true, email: data.email as string };
        }
        if (data.email_fetch_completed) {
            return { found: false };
        }
    }

    return { found: false };
}

const busy = ref<Record<number, boolean>>({});
const fetchingIds = ref<Set<number>>(new Set());
const batchBusy = ref(false);
const message = ref<{ type: 'success' | 'error' | 'info'; text: string } | null>(null);
const followerSearch = ref(props.filters?.search ?? '');

function applyFollowerSearch() {
    router.get(`/competitor-followers/${props.audience.id}`, {
        search: followerSearch.value || undefined,
        email_filter: props.emailFilter !== 'all' ? props.emailFilter : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true, only: ['list', 'filters'] });
}

function flash(type: 'success' | 'error' | 'info', text: string) {
    message.value = { type, text };
    setTimeout(() => (message.value = null), 6000);
}

function messageClass(type: 'success' | 'error' | 'info'): string {
    if (type === 'success') return 'bg-green-500/10 text-green-600';
    if (type === 'info') return 'bg-blue-500/10 text-blue-600 dark:text-blue-400';
    return 'bg-red-500/10 text-red-600';
}

async function findEmail(row: FollowerRow) {
    if (isFetching(row) || !props.dailyLimit.can_scrape) {
        return;
    }

    fetchingIds.value = new Set(fetchingIds.value).add(row.id);
    busy.value[row.id] = true;

    try {
        const { ok, data } = await api(`/competitor-followers/${props.audience.id}/fetch-email`, 'POST', {
            audience_list_id: row.id,
        });

        if (!ok) {
            flash('error', data.message || 'Failed to queue email lookup.');
            return;
        }

        if (data.email) {
            flash('success', data.message || 'Email found.');
            router.reload({ only: ['list', 'dailyLimit', 'pendingEmailFetchCount'] });
            return;
        }

        router.reload({ only: ['list', 'dailyLimit', 'pendingEmailFetchCount'] });
        startPolling();

        const result = await pollEmailResult(row.id);
        router.reload({ only: ['list', 'dailyLimit', 'pendingEmailFetchCount'] });

        if (result.found && result.email) {
            flash('success', `Email found: ${result.email}`);
        } else {
            flash('info', 'No email on this LinkedIn profile. We only returns addresses the member has shared.');
        }
    } finally {
        busy.value[row.id] = false;
        const next = new Set(fetchingIds.value);
        next.delete(row.id);
        fetchingIds.value = next;
    }
}

const selected = ref<Set<number>>(new Set());
function toggle(id: number) {
    if (selected.value.has(id)) selected.value.delete(id);
    else selected.value.add(id);
    selected.value = new Set(selected.value);
}
const selectableIds = computed(() =>
    props.list.data.filter((r) => !r.con_email && !r.email_fetch_attempted_at).map((r) => r.id),
);
function toggleAll() {
    if (selectableIds.value.every((id) => selected.value.has(id))) {
        selected.value = new Set();
    } else {
        selected.value = new Set(selectableIds.value);
    }
}

async function findEmailsBatch() {
    if (selected.value.size === 0 || batchBusy.value) return;
    batchBusy.value = true;
    try {
        const { ok, data } = await api(`/competitor-followers/${props.audience.id}/fetch-email-batch`, 'POST', {
            audience_list_ids: Array.from(selected.value),
        });
        flash(ok ? 'success' : 'error', data.message || (ok ? 'Batch queued' : 'Failed'));
        if (ok) {
            selected.value = new Set();
            router.reload({ only: ['list', 'dailyLimit', 'pendingEmailFetchCount'] });
            startPolling();
        }
    } finally {
        batchBusy.value = false;
    }
}

let timer: ReturnType<typeof setInterval> | null = null;
function startPolling() {
    if (timer) return;
    timer = setInterval(async () => {
        const { data } = await api('/competitor-followers/pending-count', 'GET');
        if ((data.pending_count ?? 0) === 0) {
            stopPolling();
            router.reload({ only: ['list', 'dailyLimit', 'pendingEmailFetchCount'] });
        } else {
            router.reload({ only: ['list'] });
        }
    }, 6000);
}
function stopPolling() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

onMounted(() => {
    if (props.pendingEmailFetchCount > 0) startPolling();
});
onBeforeUnmount(stopPolling);

function fullName(r: FollowerRow): string {
    return `${r.con_first_name ?? ''} ${r.con_last_name ?? ''}`.trim() || '—';
}
</script>

<template>
    <Head :title="audience.audience_name || 'Competitor Followers'" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link href="/competitor-followers" class="mb-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="h-3 w-3" /> Back to audiences
                </Link>
                <h1 class="text-xl font-semibold text-foreground">{{ audience.audience_name || 'Competitor Followers' }}</h1>
                <p class="text-sm text-muted-foreground">{{ audience.followers_count.toLocaleString() }} followers captured</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="rounded-lg border border-border bg-card px-3 py-2 text-xs text-muted-foreground">
                    Email quota:
                    <span class="font-semibold text-foreground">{{ dailyLimit.used }}/{{ dailyLimit.daily_limit }}</span>
                    used today
                </div>
                <a
                    :href="`/competitor-followers/${audience.id}/export`"
                    class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium hover:bg-muted"
                >
                    <Download class="h-4 w-4" /> Export CSV
                </a>
            </div>
        </div>

        <div v-if="message" :class="['rounded-lg px-4 py-2 text-sm', messageClass(message.type)]">
            {{ message.text }}
        </div>

        <!-- Filters -->
        <div class="flex flex-col gap-3">
            <ListSearchBar v-model="followerSearch" placeholder="Search followers…" @search="applyFollowerSearch" />
            <div class="flex flex-wrap items-center gap-2">
            <Link
                v-for="f in emailFilters"
                :key="f.key"
                :href="`/competitor-followers/${audience.id}?email_filter=${f.key}${followerSearch ? `&search=${encodeURIComponent(followerSearch)}` : ''}`"
                :class="[
                    'rounded-full border px-3 py-1 text-xs font-medium transition',
                    emailFilter === f.key ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted',
                ]"
            >
                {{ f.label }}
            </Link>

            <button
                v-if="selected.size > 0"
                type="button"
                :disabled="batchBusy"
                class="ml-auto inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-50"
                @click="findEmailsBatch"
            >
                <Loader2 v-if="batchBusy" class="h-3.5 w-3.5 animate-spin" />
                <Mail v-else class="h-3.5 w-3.5" />
                Find emails for {{ selected.size }} selected
            </button>
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th class="px-3 py-3 text-left">
                            <button type="button" @click="toggleAll">
                                <AppSelectionCheckbox :checked="selectableIds.length > 0 && selectableIds.every((id) => selected.has(id))" />
                            </button>
                        </th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                        <th class="hidden px-4 py-3 text-left font-medium text-muted-foreground md:table-cell">Title</th>
                        <th class="hidden px-4 py-3 text-left font-medium text-muted-foreground lg:table-cell">Company</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Email</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in list.data" :key="row.id" class="transition hover:bg-muted/30">
                        <td class="px-3 py-3">
                            <button
                                v-if="!row.con_email && !row.email_fetch_attempted_at"
                                type="button"
                                @click="toggle(row.id)"
                            >
                                <AppSelectionCheckbox :checked="selected.has(row.id)" />
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <a
                                v-if="row.con_profile_url"
                                :href="row.con_profile_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-medium text-foreground hover:text-primary hover:underline"
                                >{{ fullName(row) }}</a
                            >
                            <span v-else class="font-medium">{{ fullName(row) }}</span>
                            <div v-if="row.con_location" class="text-xs text-muted-foreground">{{ row.con_location }}</div>
                        </td>
                        <td class="hidden px-4 py-3 text-muted-foreground md:table-cell">{{ row.con_job_title || '—' }}</td>
                        <td class="hidden px-4 py-3 text-muted-foreground lg:table-cell">{{ row.con_company_name || '—' }}</td>
                        <td class="px-4 py-3">
                            <a v-if="row.con_email" :href="`mailto:${row.con_email}`" class="text-primary hover:underline">{{ row.con_email }}</a>
                            <span v-else-if="isFetching(row)" class="inline-flex items-center gap-1.5 text-xs text-blue-600">
                                <Loader2 class="h-3.5 w-3.5 animate-spin" /> Fetching…
                            </span>
                            <span v-else-if="row.email_fetch_attempted_at" class="text-xs text-muted-foreground" title="LinkedIn did not expose an email on this profile">
                                Not on profile
                            </span>
                            <button
                                v-else
                                type="button"
                                :disabled="isFetching(row) || !dailyLimit.can_scrape"
                                class="inline-flex items-center gap-1.5 rounded border border-border px-2 py-1 text-xs font-medium hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                                @click="findEmail(row)"
                            >
                                <Loader2 v-if="isFetching(row)" class="h-3.5 w-3.5 animate-spin" />
                                <Mail v-else class="h-3.5 w-3.5" />
                                Find email
                            </button>
                        </td>
                    </tr>
                    <tr v-if="list.data.length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-muted-foreground">No followers match this filter.</td>
                    </tr>
                </tbody>
            </table>

            <ListPagination :paginator="list" label="followers" />
        </div>
    </div>
</template>
