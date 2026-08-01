<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import { Database, Eye, FileSpreadsheet, Layers, Pencil, Plus, Search, Trash2, Upload, Users2, X } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import ClientPagination from '@/components/crm/ClientPagination.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import OutreachImportListPanel from '@/components/outreach/OutreachImportListPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClientList } from '@/composables/useClientList';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Leads', href: '/leads' },
        ],
    },
});

interface LeadList {
    id: number;
    list_name: string;
    list_hash: string;
    total_leads: number;
    source: string;
    src: 'aud' | 'sn' | 'csv';
    created_at: string | null;
}

const props = defineProps<{
    lists: LeadList[];
    importLists: LeadList[];
    stats: {
        total_lists: number;
        audience_lists: number;
        sn_lists: number;
        import_lists: number;
        total_leads: number;
        linkedin_leads: number;
        imported_leads: number;
    };
}>();

const activeTab = ref<'linkedin' | 'imported'>('linkedin');
const importModalOpen = ref(false);
const sourceFilter = ref<'all' | 'aud' | 'sn'>('all');

onMounted(() => {
    if (new URLSearchParams(window.location.search).get('tab') === 'imported') {
        activeTab.value = 'imported';
    }
});

const {
    search,
    page,
    paginated,
    totalPages,
    total,
} = useClientList(computed(() => props.lists), {
    perPage: 10,
    searchKeys: (l) => [l.list_name, l.source, l.list_hash],
    filterFn: (l) => sourceFilter.value === 'all' || l.src === sourceFilter.value,
});

const {
    search: importSearch,
    page: importPage,
    paginated: importPaginated,
    totalPages: importTotalPages,
    total: importTotal,
} = useClientList(computed(() => props.importLists), {
    perPage: 10,
    searchKeys: (l) => [l.list_name, l.source, l.list_hash],
});

watch(sourceFilter, () => {
    page.value = 1;
});

const renameForm = useForm({ list_name: '', src: 'aud' as 'aud' | 'sn' | 'csv' });
const renaming = ref<LeadList | null>(null);
const selectedLinkedinLists = ref<Set<string>>(new Set());
const selectedImportLists = ref<Set<string>>(new Set());

watch(activeTab, (tab) => {
    const url = new URL(window.location.href);
    if (tab === 'imported') {
        url.searchParams.set('tab', 'imported');
    } else {
        url.searchParams.delete('tab');
    }
    window.history.replaceState({}, '', url.toString());
    selectedLinkedinLists.value = new Set();
    selectedImportLists.value = new Set();
});

function listKey(list: LeadList): string {
    return `${list.src}:${list.list_hash}`;
}

const currentLists = computed(() => (activeTab.value === 'linkedin' ? paginated.value : importPaginated.value));
const selectedLists = computed(() => (activeTab.value === 'linkedin' ? selectedLinkedinLists : selectedImportLists));

const allListsSelected = computed(() => {
    const lists = currentLists.value;
    const selected = selectedLists.value;
    return lists.length > 0 && lists.every((l) => selected.value.has(listKey(l)));
});

function toggleListSelection(list: LeadList) {
    const key = listKey(list);
    const set = selectedLists.value;
    if (set.value.has(key)) set.value.delete(key);
    else set.value.add(key);
    set.value = new Set(set.value);
}

function toggleAllLists() {
    const set = selectedLists.value;
    if (allListsSelected.value) {
        set.value = new Set();
    } else {
        set.value = new Set(currentLists.value.map((l) => listKey(l)));
    }
}

function deleteSelectedLists() {
    const set = selectedLists.value;
    if (set.value.size === 0) return;
    if (!confirm(`Delete ${set.value.size} selected list(s) and all their contacts? This cannot be undone.`)) return;

    const lists = Array.from(set.value).map((key) => {
        const [src, ...hashParts] = key.split(':');
        return { src, list_hash: hashParts.join(':') };
    });

    router.delete('/leads/lists/bulk', {
        data: { lists },
        preserveScroll: true,
        onSuccess: () => {
            set.value = new Set();
        },
    });
}

function openRename(list: LeadList) {
    renaming.value = list;
    renameForm.list_name = list.list_name;
    renameForm.src = list.src;
}

function submitRename() {
    if (!renaming.value) return;
    renameForm.put(`/leads/lists/${renaming.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            renaming.value = null;
        },
    });
}

function destroy(list: LeadList) {
    if (!confirm(`Delete "${list.list_name}" and all its leads? This cannot be undone.`)) return;
    router.delete(`/leads/lists/${encodeURIComponent(list.list_hash)}?src=${list.src}`, { preserveScroll: true });
}

function onListImported() {
    importModalOpen.value = false;
    activeTab.value = 'imported';
    router.reload({ only: ['importLists', 'stats'] });
}

function fmtDate(iso: string | null): string {
    return iso ? iso.slice(0, 10) : '—';
}

function listHref(list: LeadList): string {
    return `/leads/${encodeURIComponent(list.list_hash)}?src=${list.src}`;
}

function sourceBadgeClass(src: LeadList['src']): string {
    if (src === 'aud') return 'bg-blue-500/10 text-blue-600';
    if (src === 'sn') return 'bg-amber-500/10 text-amber-600';
    return 'bg-blue-500/10 text-blue-600';
}
</script>

<template>
    <Head title="Leads" />

    <div class="flex flex-col gap-5 p-4">
        <LinkedInPageHeading title="Leads" show-badge>
            <template #subtitle>
                LinkedIn audiences, Sales Navigator lists, and spreadsheet imports — use imported lists for WhatsApp, email, and multi-channel outreach.
            </template>
        </LinkedInPageHeading>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-primary/10 p-2 text-primary"><Layers class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Total lists</p>
                    <p class="text-xl font-semibold">{{ stats.total_lists.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-blue-500/10 p-2 text-blue-500"><Users2 class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Audience lists</p>
                    <p class="text-xl font-semibold">{{ stats.audience_lists.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-amber-500/10 p-2 text-amber-500"><Database class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Sales Navigator</p>
                    <p class="text-xl font-semibold">{{ stats.sn_lists.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-blue-500/10 p-2 text-blue-600"><FileSpreadsheet class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Imported lists</p>
                    <p class="text-xl font-semibold">{{ stats.import_lists.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 sm:col-span-2 lg:col-span-1">
                <div class="rounded-lg bg-green-500/10 p-2 text-green-500"><Users2 class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Total contacts</p>
                    <p class="text-xl font-semibold">{{ stats.total_leads.toLocaleString() }}</p>
                    <p class="text-[10px] text-muted-foreground">
                        {{ stats.linkedin_leads.toLocaleString() }} LinkedIn · {{ stats.imported_leads.toLocaleString() }} imported
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-b border-border pb-1">
            <button
                type="button"
                class="rounded-t-lg px-4 py-2 text-sm font-medium transition-colors"
                :class="activeTab === 'linkedin' ? 'border-b-2 border-primary text-foreground' : 'text-muted-foreground hover:text-foreground'"
                @click="activeTab = 'linkedin'"
            >
                LinkedIn lists
                <span class="ml-1.5 rounded-full bg-muted px-2 py-0.5 text-xs">{{ lists.length }}</span>
            </button>
            <button
                type="button"
                class="rounded-t-lg px-4 py-2 text-sm font-medium transition-colors"
                :class="activeTab === 'imported' ? 'border-b-2 border-blue-600 text-foreground' : 'text-muted-foreground hover:text-foreground'"
                @click="activeTab = 'imported'"
            >
                Imported lists
                <span class="ml-1.5 rounded-full bg-muted px-2 py-0.5 text-xs">{{ importLists.length }}</span>
            </button>
        </div>

        <!-- LinkedIn lists tab -->
        <template v-if="activeTab === 'linkedin'">
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex min-w-[200px] flex-1 max-w-md items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
                    <Search class="h-4 w-4 text-muted-foreground" />
                    <input v-model="search" type="search" placeholder="Search lists…" class="w-full bg-transparent text-sm outline-none" />
                </div>
                <select v-model="sourceFilter" class="rounded-lg border border-border bg-card px-3 py-2 text-sm">
                    <option value="all">All sources</option>
                    <option value="aud">Audience</option>
                    <option value="sn">Sales Navigator</option>
                </select>
            </div>

            <div v-if="selectedLinkedinLists.size > 0" class="flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm">
                <span class="font-medium">{{ selectedLinkedinLists.size }} list(s) selected</span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-gradient-to-b from-red-500 to-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm"
                    @click="deleteSelectedLists"
                >
                    <Trash2 class="h-3.5 w-3.5" /> Delete lists
                </button>
                <button type="button" class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selectedLinkedinLists = new Set()">Clear</button>
            </div>

            <div v-if="total === 0" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
                <Layers class="h-10 w-10 text-muted-foreground/40" />
                <p class="font-medium">No LinkedIn lists yet</p>
                <p class="text-sm text-muted-foreground">Harvest audiences from Competitor Active Followers or import Sales Navigator leads via the extension.</p>
            </div>

            <div v-else class="overflow-hidden rounded-xl border border-border bg-card">
                <table class="w-full text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">
                                <button type="button" class="inline-flex" @click="toggleAllLists">
                                    <AppSelectionCheckbox :checked="allListsSelected" />
                                </button>
                            </th>
                            <th class="px-4 py-3 font-medium">List name</th>
                            <th class="px-4 py-3 font-medium">Source</th>
                            <th class="px-4 py-3 text-right font-medium">Leads</th>
                            <th class="px-4 py-3 font-medium">Created</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="list in paginated" :key="list.src + list.id" class="hover:bg-muted/30">
                            <td class="px-4 py-3">
                                <button type="button" class="inline-flex" @click="toggleListSelection(list)">
                                    <AppSelectionCheckbox :checked="selectedLinkedinLists.has(listKey(list))" />
                                </button>
                            </td>
                            <td class="px-4 py-3">
                                <Link :href="listHref(list)" class="font-medium text-foreground hover:text-blue-600 hover:underline">
                                    {{ list.list_name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="sourceBadgeClass(list.src)">{{ list.source }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ list.total_leads.toLocaleString() }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ fmtDate(list.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <Link :href="listHref(list)" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-blue-600" title="View"><Eye class="h-4 w-4" /></Link>
                                    <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Rename" @click="openRename(list)"><Pencil class="h-4 w-4" /></button>
                                    <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-red-500" title="Delete" @click="destroy(list)"><Trash2 class="h-4 w-4" /></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <ClientPagination v-model:page="page" :total-pages="totalPages" :total="total" :per-page="10" label="lists" />
            </div>
        </template>

        <!-- Imported lists tab -->
        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex min-w-[200px] flex-1 max-w-md items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
                    <Search class="h-4 w-4 text-muted-foreground" />
                    <input v-model="importSearch" type="search" placeholder="Search imported lists…" class="w-full bg-transparent text-sm outline-none" />
                </div>
                <Button class="gap-2" @click="importModalOpen = true">
                    <Upload class="h-4 w-4" />
                    Import spreadsheet
                </Button>
            </div>

            <div v-if="selectedImportLists.size > 0" class="flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm">
                <span class="font-medium">{{ selectedImportLists.size }} list(s) selected</span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-gradient-to-b from-red-500 to-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm"
                    @click="deleteSelectedLists"
                >
                    <Trash2 class="h-3.5 w-3.5" /> Delete lists
                </button>
                <button type="button" class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selectedImportLists = new Set()">Clear</button>
            </div>

            <div v-if="importTotal === 0" class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-border bg-muted/20 p-12 text-center">
                <FileSpreadsheet class="h-10 w-10 text-blue-500/60" />
                <div>
                    <p class="font-medium">No imported lists yet</p>
                    <p class="mt-1 text-sm text-muted-foreground">Upload a spreadsheet with contacts for WhatsApp, email, or social outreach.</p>
                </div>
                <Button class="gap-2" @click="importModalOpen = true">
                    <Plus class="h-4 w-4" />
                    Import spreadsheet
                </Button>
            </div>

            <div v-else class="overflow-hidden rounded-xl border border-border bg-card">
                <table class="w-full text-sm">
                    <thead class="border-b border-border bg-muted/40 text-left text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">
                                <button type="button" class="inline-flex" @click="toggleAllLists">
                                    <AppSelectionCheckbox :checked="allListsSelected" />
                                </button>
                            </th>
                            <th class="px-4 py-3 font-medium">List name</th>
                            <th class="px-4 py-3 font-medium">Source</th>
                            <th class="px-4 py-3 text-right font-medium">Contacts</th>
                            <th class="px-4 py-3 font-medium">Created</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="list in importPaginated" :key="list.list_hash" class="hover:bg-muted/30">
                            <td class="px-4 py-3">
                                <button type="button" class="inline-flex" @click="toggleListSelection(list)">
                                    <AppSelectionCheckbox :checked="selectedImportLists.has(listKey(list))" />
                                </button>
                            </td>
                            <td class="px-4 py-3">
                                <Link :href="listHref(list)" class="font-medium text-foreground hover:text-blue-600 hover:underline">
                                    {{ list.list_name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-blue-500/10 px-2 py-0.5 text-xs font-medium text-blue-600">{{ list.source }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ list.total_leads.toLocaleString() }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ fmtDate(list.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <Link :href="listHref(list)" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-blue-600" title="View"><Eye class="h-4 w-4" /></Link>
                                    <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Rename" @click="openRename(list)"><Pencil class="h-4 w-4" /></button>
                                    <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-red-500" title="Delete" @click="destroy(list)"><Trash2 class="h-4 w-4" /></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <ClientPagination v-model:page="importPage" :total-pages="importTotalPages" :total="importTotal" :per-page="10" label="lists" />
            </div>
        </template>
    </div>

    <Dialog v-model:open="importModalOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Import contact list</DialogTitle>
                <DialogDescription>
                    Drop a CSV or Excel file — WhatsApp, email, Instagram, Telegram, or X handles.
                </DialogDescription>
            </DialogHeader>
            <OutreachImportListPanel in-modal @imported="onListImported" />
        </DialogContent>
    </Dialog>

    <!-- Rename modal -->
    <div v-if="renaming" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="renaming = null">
        <div class="w-full max-w-md rounded-xl border border-border bg-card p-5 shadow-xl">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold">Rename list</h2>
                <button type="button" class="rounded p-1 hover:bg-muted" @click="renaming = null"><X class="h-4 w-4" /></button>
            </div>
            <form @submit.prevent="submitRename">
                <input v-model="renameForm.list_name" type="text" required class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary" />
                <p v-if="renameForm.errors.list_name" class="mt-1 text-xs text-red-500">{{ renameForm.errors.list_name }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted" @click="renaming = null">Cancel</button>
                    <button type="submit" :disabled="renameForm.processing" class="rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-60">Save</button>
                </div>
            </form>
        </div>
    </div>
</template>
