<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import BulkEnrichButton from '@/components/crm/BulkEnrichButton.vue';
import ImportLeadEnrichmentField from '@/components/crm/ImportLeadEnrichmentField.vue';
import LeadContactTags, { type LeadContacts } from '@/components/crm/LeadContactTags.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import { ArrowLeft, Download, ExternalLink, MessageCircle, RefreshCw, Search, Sparkles, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Leads', href: '/leads' },
        ],
    },
});

interface ImportLead {
    id: number;
    full_name: string | null;
    profile_url: string | null;
    contacts: LeadContacts;
}

const props = defineProps<{
    leads: {
        data: ImportLead[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    listId: string;
    listName: string;
    search: string;
    importEnrichmentStats: {
        total: number;
        whatsapp_verify: { with_phone: number; verified: number; needs_verify: number; can_verify: boolean };
        handle_resolve: { needs_resolve: number; can_resolve: boolean; channels: string[] };
        can_enrich: boolean;
        fetchable: number;
    } | null;
    enrichBatchSize?: number;
}>();

const ENRICH_BATCH_SIZE = computed(() => Math.max(1, props.enrichBatchSize ?? 25));

const searchTerm = ref(props.search ?? '');
const selected = ref<Set<number>>(new Set());
const busy = ref(false);
const enrichingIds = ref<Set<number>>(new Set());
const flash = ref('');
const flashError = ref('');

const allSelected = computed(() => props.leads.data.length > 0 && props.leads.data.every((l) => selected.value.has(l.id)));

const canEnrichList = computed(() => (props.importEnrichmentStats?.fetchable ?? 0) > 0);

const bulkQueueNow = computed(() => {
    const fetchable = props.importEnrichmentStats?.fetchable ?? 0;
    if (fetchable <= 0) {
        return 0;
    }

    return Math.min(ENRICH_BATCH_SIZE.value, fetchable);
});

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

function go(params: Record<string, string | undefined>) {
    router.get(
        `/leads/${props.listId}`,
        { src: 'csv', search: searchTerm.value || undefined, ...params },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function runSearch() {
    go({ search: searchTerm.value || undefined });
}

function toggle(id: number) {
    if (selected.value.has(id)) selected.value.delete(id);
    else selected.value.add(id);
    selected.value = new Set(selected.value);
}

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

async function enrichLead(lead: ImportLead) {
    if (enrichingIds.value.has(lead.id) || busy.value) {
        return;
    }

    enrichingIds.value = new Set(enrichingIds.value).add(lead.id);

    try {
        const res = await fetch(`/leads/${props.listId}/enrich?src=csv`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify({ import_lead_id: lead.id }),
        });
        const data = await res.json();
        setFlash(data.message || (res.ok ? 'Contact enriched.' : 'Enrichment failed.'), !res.ok);
        if (res.ok) {
            router.reload({ only: ['leads', 'importEnrichmentStats'], preserveScroll: true });
        }
    } catch {
        setFlash('Network error.', true);
    } finally {
        const next = new Set(enrichingIds.value);
        next.delete(lead.id);
        enrichingIds.value = next;
    }
}

async function enrichNextBatch() {
    if (!canEnrichList.value || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const res = await fetch(`/leads/${props.listId}/enrich-batch?src=csv`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify({ auto_batch: true }),
        });
        const data = await res.json();
        if (res.ok) {
            setFlash(data.message);
            router.reload({ only: ['leads', 'importEnrichmentStats'], preserveScroll: true });
        } else {
            setFlash(data.message || 'Enrichment failed.', true);
        }
    } catch {
        setFlash('Network error.', true);
    } finally {
        busy.value = false;
    }
}

function deleteSelected() {
    if (selected.value.size === 0) return;
    if (!confirm(`Delete ${selected.value.size} selected contact(s)?`)) return;
    router.delete('/leads/bulk', {
        data: { src: 'csv', ids: Array.from(selected.value) },
        preserveScroll: true,
        onSuccess: () => {
            selected.value = new Set();
        },
    });
}

function deleteLead(lead: ImportLead) {
    if (!confirm('Delete this contact?')) return;
    router.delete(`/leads/lead/${lead.id}?src=csv`, { preserveScroll: true });
}

function deleteList() {
    if (!confirm(`Delete "${props.listName}" and all its contacts? This cannot be undone.`)) return;
    router.delete(`/leads/lists/${encodeURIComponent(props.listId)}?src=csv`);
}

async function exportCsv() {
    busy.value = true;
    try {
        const a = document.createElement('a');
        a.href = `/leads/${props.listId}/export?src=csv`;
        a.download = `${props.listName.replace(/[^a-z0-9]+/gi, '_')}_contacts.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
    } catch {
        setFlash('Export failed.', true);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head :title="listName" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Link href="/leads?tab=imported" class="rounded-lg border border-border p-2 hover:bg-muted"><ArrowLeft class="h-4 w-4" /></Link>
                <div>
                    <LinkedInPageHeading :title="listName" show-badge>
                        <template #subtitle>
                            {{ leads.total.toLocaleString() }} contacts ·
                            <span class="text-violet-600">Spreadsheet import</span>
                        </template>
                    </LinkedInPageHeading>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <AppToolbarButton :disabled="busy" @click="exportCsv"><Download class="h-4 w-4" /> Export</AppToolbarButton>
                <AppToolbarButton variant="info" @click="router.reload({ only: ['leads', 'importEnrichmentStats'], preserveScroll: true })">
                    <RefreshCw class="h-4 w-4" /> Refresh
                </AppToolbarButton>
                <AppToolbarButton variant="danger" @click="deleteList"><Trash2 class="h-4 w-4" /> Delete list</AppToolbarButton>
            </div>
        </div>

        <div v-if="importEnrichmentStats" class="rounded-xl border border-border bg-card p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Contact enrichment</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Verify WhatsApp numbers and resolve social handles (Instagram, Telegram, X) — like waterfall enrichment on imported lists.
                    </p>
                </div>
                <BulkEnrichButton
                    v-if="canEnrichList || busy"
                    :loading="busy"
                    :disabled="!canEnrichList"
                    :remaining="importEnrichmentStats.fetchable"
                    :queue-now="bulkQueueNow"
                    :batch-size="ENRICH_BATCH_SIZE"
                    @click="enrichNextBatch"
                />
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-border bg-muted/20 px-3 py-2.5 text-sm">
                    <div class="flex items-center gap-2 font-medium">
                        <MessageCircle class="h-4 w-4 text-emerald-600" />
                        WhatsApp
                    </div>
                    <p class="mt-1 text-muted-foreground">
                        {{ importEnrichmentStats.whatsapp_verify.verified }} verified ·
                        {{ importEnrichmentStats.whatsapp_verify.needs_verify }} to verify
                    </p>
                </div>
                <div class="rounded-lg border border-border bg-muted/20 px-3 py-2.5 text-sm">
                    <div class="flex items-center gap-2 font-medium">
                        <Sparkles class="h-4 w-4 text-violet-600" />
                        Social handles
                    </div>
                    <p class="mt-1 text-muted-foreground">
                        {{ importEnrichmentStats.handle_resolve.needs_resolve }} handle(s) to resolve
                    </p>
                </div>
            </div>
        </div>

        <p v-if="flash" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ flash }}</p>
        <p v-if="flashError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ flashError }}</p>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex min-w-[200px] flex-1 max-w-md items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
                <Search class="h-4 w-4 text-muted-foreground" />
                <input
                    v-model="searchTerm"
                    type="search"
                    placeholder="Search contacts…"
                    class="w-full bg-transparent text-sm outline-none"
                    @keydown.enter="runSearch"
                />
            </div>
            <button type="button" class="rounded-lg border border-border bg-card px-3 py-2 text-sm hover:bg-muted" @click="runSearch">Search</button>
        </div>

        <div v-if="selected.size > 0" class="flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm">
            <span class="font-medium">{{ selected.size }} selected</span>
            <AppToolbarButton variant="danger" @click="deleteSelected">
                <Trash2 class="h-4 w-4" /> Delete {{ selected.size }}
            </AppToolbarButton>
            <button type="button" class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selected = new Set()">Clear</button>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40 text-left text-xs uppercase text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            <button type="button" class="inline-flex" @click="toggleAll">
                                <AppSelectionCheckbox :checked="allSelected" />
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">LinkedIn</th>
                        <th class="px-4 py-3 font-medium">Contacts</th>
                        <th class="px-4 py-3 font-medium">Enrich</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="lead in leads.data" :key="lead.id" class="hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <button type="button" class="inline-flex" @click="toggle(lead.id)">
                                <AppSelectionCheckbox :checked="selected.has(lead.id)" />
                            </button>
                        </td>
                        <td class="px-4 py-3 font-medium">{{ lead.full_name || '—' }}</td>
                        <td class="px-4 py-3">
                            <a
                                v-if="lead.profile_url"
                                :href="lead.profile_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-primary hover:underline"
                            >
                                Profile <ExternalLink class="h-3 w-3" />
                            </a>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <LeadContactTags :contacts="lead.contacts" :show-linkedin="!!lead.profile_url" />
                        </td>
                        <td class="px-4 py-3">
                            <ImportLeadEnrichmentField
                                :contacts="lead.contacts"
                                :fetching="enrichingIds.has(lead.id)"
                                @fetch="enrichLead(lead)"
                            />
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-red-500" title="Delete" @click="deleteLead(lead)">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="leads.last_page > 1" class="flex items-center justify-between text-sm">
            <p class="text-muted-foreground">{{ leads.total.toLocaleString() }} contacts</p>
            <div class="flex gap-2">
                <Link
                    v-if="leads.prev_page_url"
                    :href="leads.prev_page_url"
                    class="rounded-lg border border-border px-3 py-1.5 hover:bg-muted"
                    preserve-scroll
                >
                    Previous
                </Link>
                <Link
                    v-if="leads.next_page_url"
                    :href="leads.next_page_url"
                    class="rounded-lg border border-border px-3 py-1.5 hover:bg-muted"
                    preserve-scroll
                >
                    Next
                </Link>
            </div>
        </div>
    </div>
</template>
