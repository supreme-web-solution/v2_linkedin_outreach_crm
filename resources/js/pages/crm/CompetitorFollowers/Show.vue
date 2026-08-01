<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import BulkEnrichButton from '@/components/crm/BulkEnrichButton.vue';
import EmailEnrichmentInfoTooltip from '@/components/crm/EmailEnrichmentInfoTooltip.vue';
import { refreshDailyEnrichmentQuota } from '@/composables/useDailyEnrichmentQuota';
import { Button } from '@/components/ui/button';
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
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Competitor Active Followers', href: '/competitor-followers' },
        ],
    },
});

interface Follower {
    id: number;
    name: string;
    email: string | null;
    headline: string | null;
    location: string | null;
    profile_url: string | null;
    network_distance: string | null;
    email_fetch_status: string | null;
    email_fetch_attempted_at: string | null;
    contacts: LeadContacts;
    company_name: string | null;
    company_domain: string | null;
    company_logo_url: string | null;
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
    audience: {
        id: number;
        audience_id: number;
        audience_name: string | null;
        company_url: string | null;
        followers_count: number;
    };
    followers: {
        data: Follower[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    emailFilter: string;
    search: string;
    counts: Record<string, number>;
    contactStats: ContactStats;
    pendingCount: number;
    dailyLimit: DailyLimit;
}>();

const MAX_CONCURRENT_ENRICHMENTS = 5;

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

const hasPending = computed(() =>
    enrichingIds.value.size > 0
    || props.pendingCount > 0
    || props.followers.data.some((f) => ['pending', 'processing'].includes(f.email_fetch_status ?? '')),
);

const inFlightEnrichments = computed(() =>
    Math.max(props.pendingCount, enrichingIds.value.size),
);

function isFollowerEnriching(follower: Follower): boolean {
    return enrichingIds.value.has(follower.id)
        || ['pending', 'processing'].includes(follower.email_fetch_status ?? '');
}

function canStartEnrich(excludeId?: number): boolean {
    if (!props.dailyLimit.can_scrape) {
        return false;
    }
    const localCount = excludeId
        ? [...enrichingIds.value].filter((id) => id !== excludeId).length
        : enrichingIds.value.size;

    return Math.max(props.pendingCount, localCount) < MAX_CONCURRENT_ENRICHMENTS;
}

watch(
    () => props.followers.data.map((f) => ({ id: f.id, status: f.email_fetch_status })),
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
        `/competitor-followers/${props.audience.id}`,
        { email_filter: props.emailFilter, search: searchTerm.value || undefined, ...params },
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

const allSelected = computed(() => props.followers.data.length > 0 && props.followers.data.every((f) => selected.value.has(f.id)));

function toggleAll() {
    if (allSelected.value) {
        selected.value = new Set();
    } else {
        selected.value = new Set(props.followers.data.map((f) => f.id));
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

function scheduleReload() {
    if (reloadTimer) {
        clearTimeout(reloadTimer);
    }
    reloadTimer = setTimeout(() => {
        router.reload({
            only: ['followers', 'counts', 'contactStats', 'dailyLimit', 'pendingCount'],
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

        const res = await fetch(`/competitor-followers/${props.audience.id}/check-email/${audienceListId}`, {
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

async function enrichFollower(follower: Follower) {
    if (isFollowerEnriching(follower)) {
        return;
    }

    if (!canStartEnrich()) {
        setFlash(
            `You have ${inFlightEnrichments.value} enrichment${inFlightEnrichments.value === 1 ? '' : 's'} running. Max ${MAX_CONCURRENT_ENRICHMENTS} at a time — wait for one to finish.`,
            true,
        );
        return;
    }

    enrichingIds.value = new Set(enrichingIds.value).add(follower.id);

    try {
        const res = await fetch(`/competitor-followers/${props.audience.id}/fetch-email`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify({ audience_list_id: follower.id }),
        });
        const data = await res.json();

        if (!res.ok) {
            setFlash(data.message || 'Failed to enrich.', true);
            removeEnrichingId(follower.id);
            return;
        }

        setFlash(data.message);
        scheduleReload();
        void refreshDailyEnrichmentQuota();

        if (!data.email) {
            await pollEnrichmentResult(follower.id);
            scheduleReload();
        }
    } catch {
        setFlash('Network error.', true);
        removeEnrichingId(follower.id);
    } finally {
        removeEnrichingId(follower.id);
    }
}

const canBulkEnrich = computed(() => (props.contactStats?.fetchable ?? 0) > 0 && canStartEnrich());

async function enrichNextBatch() {
    if (!canBulkEnrich.value || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const res = await fetch(`/competitor-followers/${props.audience.id}/fetch-email-batch`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify({ auto_batch: true }),
        });
        const data = await res.json();

        if (res.ok) {
            setFlash(data.message);
            scheduleReload();
            void refreshDailyEnrichmentQuota();
        } else {
            setFlash(data.message || 'Failed to enrich followers.', true);
        }
    } catch {
        setFlash('Network error.', true);
    } finally {
        busy.value = false;
    }
}

let poll: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    poll = setInterval(() => {
        if (hasPending.value) {
            scheduleReload();
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

function followerContacts(follower: Follower): LeadContacts {
    return {
        ...follower.contacts,
        email_fetch_status: follower.contacts.email_fetch_status ?? follower.email_fetch_status,
    };
}

function distanceLabel(d: string | null): string {
    if (!d) return '';
    const map: Record<string, string> = { DISTANCE_1: '1st', DISTANCE_2: '2nd', DISTANCE_3: '3rd' };
    return map[d] ?? d;
}
</script>

<template>
    <Head :title="audience.audience_name || 'Competitor Followers'" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Link href="/competitor-followers" class="rounded-lg border border-border p-2 hover:bg-muted">
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div>
                    <LinkedInPageHeading :title="audience.audience_name || 'Competitor Followers'" show-badge>
                        <template #subtitle>
                            {{ followers.total.toLocaleString() }} followers ·
                            <span class="text-blue-600">Competitor audience</span>
                        </template>
                    </LinkedInPageHeading>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="success" size="toolbar" as-child>
                    <a :href="`/competitor-followers/${audience.id}/export`">
                        <Download class="h-4 w-4" /> Export CSV
                    </a>
                </Button>
                <AppToolbarButton variant="info" @click="router.reload({ only: ['followers', 'counts', 'contactStats', 'dailyLimit', 'pendingCount'] })">
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
                        {{ contactStats.total.toLocaleString() }} followers
                    </p>
                </div>
                <div v-if="inFlightEnrichments > 0" class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1.5 text-xs font-medium">
                    <Loader2 class="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                    Enriching
                    <span class="rounded-full bg-muted px-2 py-0.5 tabular-nums text-foreground">
                        {{ inFlightEnrichments }}/{{ MAX_CONCURRENT_ENRICHMENTS }}
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
            <div class="flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1">
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
                <input v-model="searchTerm" type="text" placeholder="Search followers…" class="w-full bg-transparent text-sm outline-none" @keyup.enter="runSearch" />
            </div>
        </div>

        <!-- Bulk bar -->
        <div v-if="selected.size > 0" class="flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm">
            <span class="font-medium">{{ selected.size }} selected</span>
            <button type="button" class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selected = new Set()">Clear</button>
        </div>

        <!-- Empty -->
        <div v-if="followers.data.length === 0" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <Mail class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No followers match this view</p>
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
                            <th class="w-16 px-3 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="follower in followers.data"
                            :key="follower.id"
                            class="group border-b border-border/70 transition-colors last:border-b-0 hover:bg-muted/20"
                            :class="selected.has(follower.id) ? 'bg-muted/30 ring-1 ring-inset ring-primary/15' : ''"
                        >
                            <td class="relative px-3 py-4">
                                <span v-if="selected.has(follower.id)" class="absolute inset-y-0 left-0 w-0.5 bg-primary" />
                                <button type="button" @click="toggle(follower.id)">
                                    <AppSelectionCheckbox :checked="selected.has(follower.id)" />
                                </button>
                            </td>
                            <td class="px-4 py-4">
                                <div class="min-w-[180px]">
                                    <div class="flex items-center gap-2">
                                        <a
                                            v-if="follower.profile_url"
                                            :href="follower.profile_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="font-semibold text-foreground hover:underline"
                                        >
                                            {{ follower.name }}
                                        </a>
                                        <p v-else class="font-semibold text-foreground">{{ follower.name }}</p>
                                        <span v-if="follower.network_distance" class="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">{{ distanceLabel(follower.network_distance) }}</span>
                                    </div>
                                    <p v-if="follower.headline" class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{{ follower.headline }}</p>
                                    <p v-else-if="follower.location" class="mt-0.5 text-xs text-muted-foreground">{{ follower.location }}</p>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div v-if="follower.company_domain || follower.company_name" class="flex min-w-[140px] items-center gap-2.5">
                                    <img
                                        v-if="follower.company_logo_url"
                                        :src="follower.company_logo_url"
                                        :alt="follower.company_name || follower.company_domain || 'Company'"
                                        class="h-7 w-7 shrink-0 rounded-md border border-border bg-white object-contain p-0.5"
                                        loading="lazy"
                                    />
                                    <div v-else class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-[10px] font-semibold uppercase text-muted-foreground">
                                        {{ (follower.company_name || follower.company_domain || '?').slice(0, 1) }}
                                    </div>
                                    <span class="truncate text-sm text-foreground" :title="follower.company_domain || follower.company_name || ''">
                                        {{ follower.company_domain || follower.company_name }}
                                    </span>
                                </div>
                                <span v-else class="text-sm text-muted-foreground/50">—</span>
                            </td>
                            <td class="px-4 py-4">
                                <LeadEnrichmentField
                                    type="phone"
                                    :value="follower.contacts.phone"
                                    :fetch-status="isFollowerEnriching(follower) ? 'processing' : follower.contacts.phone_fetch_status"
                                    :fetch-attempted="follower.contacts.phone_fetch_attempted === true"
                                    :fetching="isFollowerEnriching(follower)"
                                />
                            </td>
                            <td class="px-4 py-4">
                                <LeadEnrichmentField
                                    type="email"
                                    :value="follower.email"
                                    :fetch-status="isFollowerEnriching(follower) ? 'processing' : follower.email_fetch_status"
                                    :fetch-attempted="!!follower.email_fetch_attempted_at"
                                    :fetching="isFollowerEnriching(follower)"
                                    :can-fetch="true"
                                    :fetch-disabled="!canStartEnrich(follower.id) || !dailyLimit.can_scrape"
                                    @fetch="enrichFollower(follower)"
                                />
                            </td>
                            <td class="px-4 py-4">
                                <LeadContactTags :contacts="followerContacts(follower)" :show-email="false" />
                            </td>
                            <td class="px-3 py-4">
                                <div class="flex items-center justify-end opacity-0 transition-opacity group-hover:opacity-100">
                                    <a
                                        v-if="follower.profile_url"
                                        :href="follower.profile_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                        title="View profile"
                                    >
                                        <ExternalLink class="h-4 w-4" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ListPagination v-if="followers.data.length" :paginator="followers" label="followers" />
        </div>
    </div>
</template>
