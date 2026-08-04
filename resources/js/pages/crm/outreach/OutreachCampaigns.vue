<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Layers, Megaphone, Copy, Pause, Play, Plus, Search, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import ChannelLimitsNoticeModal from '@/components/crm/ChannelLimitsNoticeModal.vue';
import type { ConnectedChannel } from '@/components/outreach/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Multi-Channel Outreach', href: '/outreach' }] },
});

const props = defineProps<{
    campaigns: {
        data: Array<{
            id: number;
            name: string;
            template_type: string;
            status: string;
            created_at: string;
            outreach_leads_count: number;
            outreach_lists_count: number;
        }>;
        total: number;
    };
    hasOrg: boolean;
    connectedChannels: ConnectedChannel[];
    filters: { search: string | null };
}>();

const actionId = ref<number | null>(null);
const search = ref(props.filters.search ?? '');

function applySearch() {
    router.get('/outreach', { search: search.value.trim() || undefined }, { preserveState: true, replace: true });
}

const statusColor = (s: string) => {
    if (s === 'active' || s === 'running') return 'bg-green-500/10 text-green-700 border-green-200';
    if (s === 'paused') return 'bg-yellow-500/10 text-yellow-700 border-yellow-200';
    if (s === 'draft') return 'bg-slate-500/10 text-slate-600 border-slate-200';
    if (s === 'completed') return 'bg-blue-500/10 text-blue-700 border-blue-200';
    return 'bg-muted text-muted-foreground border-border';
};

const connectedCount = () => props.connectedChannels.filter((c) => c.connected).length;

function pauseCampaign(c: { id: number; name: string }) {
    if (!confirm(`Pause "${c.name}"?`)) return;
    actionId.value = c.id;
    router.put(`/outreach/${c.id}`, { status: 'paused' }, { preserveScroll: true, onFinish: () => { actionId.value = null; } });
}

function launchCampaign(c: { id: number }) {
    actionId.value = c.id;
    router.post(`/outreach/${c.id}/activate`, {}, { preserveScroll: true, onFinish: () => { actionId.value = null; } });
}

function deleteCampaign(c: { id: number; name: string }) {
    if (!confirm(`Delete "${c.name}"?`)) return;
    actionId.value = c.id;
    router.delete(`/outreach/${c.id}`, { preserveScroll: true, onFinish: () => { actionId.value = null; } });
}

function duplicateCampaign(c: { id: number }) {
    actionId.value = c.id;
    router.post(`/outreach/${c.id}/duplicate`, {}, { preserveScroll: true, onFinish: () => { actionId.value = null; } });
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
            <Link href="/outreach/create" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700">
                <Plus class="h-4 w-4" /> New outreach
            </Link>
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
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex min-w-[200px] flex-1 max-w-md items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
                    <Search class="h-4 w-4 shrink-0 text-muted-foreground" />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search campaigns…"
                        class="w-full bg-transparent text-sm outline-none"
                        @keydown.enter="applySearch"
                    />
                </div>
                <button type="button" class="rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium hover:bg-muted" @click="applySearch">
                    Search
                </button>
            </div>

            <div v-if="campaigns.data.length === 0 && filters.search" class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center">
                <Search class="h-10 w-10 text-muted-foreground/40" />
                <p class="font-medium">No campaigns match "{{ filters.search }}"</p>
                <button type="button" class="text-sm text-primary hover:underline" @click="search = ''; applySearch()">Clear search</button>
            </div>

            <div v-else-if="campaigns.data.length === 0" class="flex flex-col items-center gap-4 rounded-xl border border-dashed p-10 text-center sm:p-14">
                <Layers class="h-10 w-10 text-muted-foreground/40" />
                <div>
                    <p class="font-medium">No outreach campaigns yet</p>
                    <p class="text-sm text-muted-foreground">Build a multichannel sequence and launch when channels are connected.</p>
                </div>
                <Link href="/outreach/create" class="rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground">Create outreach</Link>
            </div>

            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="c in campaigns.data" :key="c.id" class="rounded-xl border border-border bg-card p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <Link :href="`/outreach/${c.id}`" class="font-semibold hover:text-primary">{{ c.name }}</Link>
                        <span class="rounded-full border px-2 py-0.5 text-[10px] font-medium capitalize" :class="statusColor(c.status)">{{ c.status }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">{{ c.outreach_leads_count }} leads · {{ c.outreach_lists_count }} lists</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            v-if="c.status === 'running' || c.status === 'active'"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-border bg-white px-2 py-1 text-xs font-medium shadow-sm hover:bg-muted"
                            @click="pauseCampaign(c)"
                        >
                            <Pause class="h-3 w-3" /> Pause
                        </button>
                        <button
                            v-else-if="c.status !== 'completed'"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-green-200 bg-green-50 px-2 py-1 text-xs text-green-700"
                            @click="launchCampaign(c)"
                        >
                            <Play class="h-3 w-3" /> Launch
                        </button>
                        <Link :href="`/outreach/${c.id}/edit`" class="inline-flex items-center gap-1 rounded-lg border border-border bg-white px-2 py-1 text-xs font-medium shadow-sm hover:bg-muted">Edit</Link>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-border bg-white px-2 py-1 text-xs font-medium shadow-sm hover:bg-muted" @click="duplicateCampaign(c)">
                            <Copy class="h-3 w-3" /> Copy
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-white px-2 py-1 text-xs font-medium text-red-600 shadow-sm hover:bg-red-50" @click="deleteCampaign(c)">
                            <Trash2 class="h-3 w-3" />
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
