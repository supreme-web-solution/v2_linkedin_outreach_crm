<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Flag, Layers, Megaphone, Pause, Pencil, Play, Plus, Trash2, TrendingUp, Users } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import ListPagination from '@/components/crm/ListPagination.vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';
import LinkedInDisconnectBanner from '@/components/campaign/LinkedInDisconnectBanner.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import ChannelLimitsNoticeModal from '@/components/crm/ChannelLimitsNoticeModal.vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Campaigns', href: '/campaigns' }] },
});

const props = defineProps<{
    campaigns: {
        data: Array<{
            id: number;
            name: string;
            sequence_type: string;
            status: string;
            created_at: string;
            campaign_leads_count: number;
            campaign_lists_count: number;
            accept_rate: number;
        }>;
        total: number;
        current_page: number;
        last_page: number;
    };
    stats: {
        total_campaigns: number;
        running_campaigns: number;
        completed_campaigns: number;
        total_leads: number;
    };
    hasOrg: boolean;
    filters: { search: string | null; status: string | null };
}>();

const search = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? 'all');

function applyFilters() {
    router.get('/campaigns', {
        search: search.value || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
    }, { preserveState: true, replace: true });
}

const statusColor = (status: string) => {
    if (status === 'active' || status === 'running') return 'bg-green-500/10 text-green-600 border-green-200';
    if (status === 'paused' || status === 'stopped') return 'bg-yellow-500/10 text-yellow-700 border-yellow-200';
    if (status === 'draft') return 'bg-slate-500/10 text-slate-600 border-slate-200';
    if (status === 'completed') return 'bg-blue-500/10 text-blue-700 border-blue-200';
    return 'bg-muted text-muted-foreground border-border';
};

const typeLabel: Record<string, string> = {
    lead_gen: 'Lead Generation',
    endorse: 'Endorse Skills',
    profile_views: 'Profile Views',
    custom: 'Custom',
};

const typeBadge: Record<string, string> = {
    lead_gen: 'bg-blue-50 text-blue-700 border-blue-200',
    endorse: 'bg-yellow-50 text-yellow-700 border-yellow-200',
    profile_views: 'bg-purple-50 text-purple-700 border-purple-200',
    custom: 'bg-slate-50 text-slate-600 border-slate-200',
};

let poll: ReturnType<typeof setInterval> | null = null;
const actionId = ref<number | null>(null);

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

async function refreshStatuses() {
    try {
        const res = await fetch('/campaigns/status-updates', { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });
        if (res.ok) router.reload({ only: ['campaigns', 'stats'] });
    } catch {
        /* ignore */
    }
}

onMounted(() => {
    poll = setInterval(refreshStatuses, 15000);
});
onBeforeUnmount(() => {
    if (poll) clearInterval(poll);
});

function isRunningStatus(status: string) {
    return status === 'active' || status === 'running';
}

function pauseCampaign(c: { id: number; name: string }) {
    if (!confirm(`Pause "${c.name}"?`)) return;
    actionId.value = c.id;
    router.put(`/campaigns/${c.id}`, { status: 'paused' }, {
        preserveScroll: true,
        onFinish: () => { actionId.value = null; },
    });
}

function playCampaign(c: { id: number; name: string; campaign_lists_count: number }) {
    actionId.value = c.id;
    if (c.campaign_lists_count > 0) {
        router.post(`/campaigns/${c.id}/activate`, {}, {
            preserveScroll: true,
            onFinish: () => { actionId.value = null; },
        });
    } else {
        router.put(`/campaigns/${c.id}`, { status: 'active' }, {
            preserveScroll: true,
            onFinish: () => { actionId.value = null; },
        });
    }
}

function deleteCampaign(c: { id: number; name: string }) {
    if (!confirm(`Delete "${c.name}"? This cannot be undone.`)) return;
    actionId.value = c.id;
    router.delete(`/campaigns/${c.id}`, {
        preserveScroll: true,
        onFinish: () => { actionId.value = null; },
    });
}
</script>

<template>
    <Head title="Campaigns" />

    <div class="flex flex-col gap-5 p-4">
        <ChannelLimitsNoticeModal
            storage-key="sf:notice:channel-limits:campaigns"
            variant="campaigns"
        />
        <LinkedInDisconnectBanner />

        <div class="flex items-center justify-between">
            <div>
                <LinkedInPageHeading title="Campaigns" show-badge>
                    <template #subtitle>
                        {{ campaigns.total }} total campaign{{ campaigns.total !== 1 ? 's' : '' }}.
                    </template>
                </LinkedInPageHeading>
            </div>
            <Link href="/campaigns/create" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 shadow-sm">
                <Plus class="h-4 w-4" /> New Campaign
            </Link>
        </div>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300">
            Link your workspace through the extension to manage campaigns.
        </div>

        <div v-else class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-primary/10 p-2 text-primary"><Layers class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Total campaigns</p>
                    <p class="text-xl font-semibold">{{ stats.total_campaigns.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-green-500/10 p-2 text-green-600"><Play class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Running</p>
                    <p class="text-xl font-semibold">{{ stats.running_campaigns.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-blue-500/10 p-2 text-blue-600"><Flag class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Completed</p>
                    <p class="text-xl font-semibold">{{ stats.completed_campaigns.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-amber-500/10 p-2 text-amber-600"><Users class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Total leads</p>
                    <p class="text-xl font-semibold">{{ stats.total_leads.toLocaleString() }}</p>
                </div>
            </div>
        </div>

        <ListSearchBar v-if="hasOrg" v-model="search" placeholder="Search campaigns…" @search="applyFilters">
            <template #filters>
                <select
                    v-model="statusFilter"
                    class="rounded-lg border border-border bg-card px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="all">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="running">Running</option>
                    <option value="active">Active</option>
                    <option value="paused">Paused</option>
                    <option value="completed">Completed</option>
                </select>
            </template>
        </ListSearchBar>

        <div v-if="hasOrg && campaigns.data.length === 0" class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-border p-14 text-center">
            <Megaphone class="h-10 w-10 text-muted-foreground/40" />
            <div>
                <p class="font-semibold">No campaigns yet</p>
                <p class="text-sm text-muted-foreground mt-1">Create a campaign, attach lead lists, build your sequence, then launch from the extension.</p>
            </div>
            <Link href="/campaigns/create" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700">
                <Plus class="h-4 w-4" /> Create Campaign
            </Link>
        </div>

        <div v-else-if="hasOrg" class="flex flex-col gap-3">
            <div v-for="c in campaigns.data" :key="c.id" class="flex flex-col sm:flex-row sm:items-center gap-3 rounded-xl border border-border bg-card p-4 hover:shadow-sm transition-shadow">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                    <Megaphone class="h-5 w-5 text-muted-foreground" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-semibold text-sm truncate">{{ c.name }}</span>
                        <span class="rounded-full border px-2 py-0.5 text-[10px] font-medium capitalize" :class="typeBadge[c.sequence_type] ?? 'bg-muted text-muted-foreground border-border'">
                            {{ typeLabel[c.sequence_type] ?? c.sequence_type }}
                        </span>
                        <span class="rounded-full border px-2 py-0.5 text-[10px] font-medium capitalize" :class="statusColor(c.status)">
                            {{ c.status }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3 mt-1 text-xs text-muted-foreground flex-wrap">
                        <span class="flex items-center gap-1"><Users class="h-3 w-3" /> {{ c.campaign_leads_count }} leads</span>
                        <span>{{ c.campaign_lists_count }} list{{ c.campaign_lists_count !== 1 ? 's' : '' }}</span>
                        <span class="flex items-center gap-1"><TrendingUp class="h-3 w-3" /> {{ c.accept_rate }}% accept rate</span>
                        <span>Created {{ c.created_at?.slice(0, 10) }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-1.5 shrink-0 flex-wrap justify-end">
                    <button
                        v-if="isRunningStatus(c.status)"
                        type="button"
                        :disabled="actionId === c.id"
                        @click="pauseCampaign(c)"
                        class="inline-flex items-center gap-1 rounded-lg border border-yellow-300 bg-yellow-50 px-2.5 py-1.5 text-xs font-medium text-yellow-700 hover:bg-yellow-100 disabled:opacity-50"
                        title="Pause campaign">
                        <Pause class="h-3 w-3" /> Pause
                    </button>
                    <button
                        v-else-if="c.status !== 'completed'"
                        type="button"
                        :disabled="actionId === c.id"
                        @click="playCampaign(c)"
                        class="inline-flex items-center gap-1 rounded-lg border border-green-300 bg-green-50 px-2.5 py-1.5 text-xs font-medium text-green-700 hover:bg-green-100 disabled:opacity-50"
                        title="Run campaign">
                        <Play class="h-3 w-3" /> Run
                    </button>
                    <Link :href="`/campaigns/${c.id}/edit`" class="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted transition-colors">
                        <Pencil class="h-3 w-3" /> Edit
                    </Link>
                    <Link :href="`/campaigns/${c.id}`" class="inline-flex items-center gap-1 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 transition-colors">
                        View
                    </Link>
                    <button
                        type="button"
                        :disabled="actionId === c.id"
                        @click="deleteCampaign(c)"
                        class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100 disabled:opacity-50"
                        title="Delete campaign">
                        <Trash2 class="h-3 w-3" />
                    </button>
                </div>
            </div>
        </div>

        <ListPagination v-if="hasOrg && campaigns.data.length" :paginator="campaigns" label="campaigns" />
    </div>
</template>
