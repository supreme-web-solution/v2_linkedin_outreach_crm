<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import { Eye, FileSpreadsheet, Layers, Link2, Pencil, Plus, Search, Trash2, Upload, Users2, X } from '@lucide/vue';
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
import { brandIconSrc } from '@/lib/brandIcons';

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
    channel?: string | null;
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
        instagram_lists: number;
        total_leads: number;
        linkedin_leads: number;
        imported_leads: number;
    };
}>();

type LeadTab = 'all' | 'linkedin' | 'instagram' | 'imported';

const activeTab = ref<LeadTab>('all');
const importModalOpen = ref(false);
const profileModalOpen = ref(false);
const igSearchModalOpen = ref(false);
const profileUrl = ref('');
const profileListName = ref('');
const profileBusy = ref(false);
const profileError = ref('');
const igQuery = ref('');
const igLimit = ref(25);
const igListName = ref('');
const igBusy = ref(false);
const igError = ref('');

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function searchInstagram() {
    igError.value = '';
    const query = igQuery.value.trim();
    if (!query) {
        igError.value = 'Enter a keyword (e.g. coffee, nasa).';
        return;
    }
    const limit = Math.min(100, Math.max(1, Number(igLimit.value) || 25));
    igBusy.value = true;
    try {
        const res = await fetch('/leads/search-instagram', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify({
                query,
                limit,
                list_name: igListName.value.trim() || null,
            }),
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            igError.value = data.message || 'Instagram search failed.';
            return;
        }
        igSearchModalOpen.value = false;
        igQuery.value = '';
        igListName.value = '';
        igLimit.value = 25;
        activeTab.value = 'instagram';
        if (data.redirect) {
            window.location.href = data.redirect;
            return;
        }
        router.reload({ only: ['lists', 'importLists', 'stats'] });
    } catch (e: any) {
        igError.value = e?.message || 'Instagram search failed.';
    } finally {
        igBusy.value = false;
    }
}

async function importProfile() {
    profileError.value = '';
    const url = profileUrl.value.trim();
    if (!url) {
        profileError.value = 'Paste a LinkedIn or Instagram profile URL.';
        return;
    }
    profileBusy.value = true;
    try {
        const res = await fetch('/leads/import-profile', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify({
                profile_url: url,
                list_name: profileListName.value.trim() || null,
            }),
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            profileError.value = data.message || 'Import failed.';
            return;
        }
        profileModalOpen.value = false;
        profileUrl.value = '';
        profileListName.value = '';
        if (data.redirect) {
            window.location.href = data.redirect;
            return;
        }
        router.reload({ only: ['lists', 'importLists', 'stats'] });
    } catch (e: any) {
        profileError.value = e?.message || 'Import failed.';
    } finally {
        profileBusy.value = false;
    }
}

onMounted(() => {
    const tab = new URLSearchParams(window.location.search).get('tab');
    if (tab === 'linkedin' || tab === 'instagram' || tab === 'imported' || tab === 'all') {
        activeTab.value = tab;
    }
});

function isInstagramList(list: LeadList): boolean {
    return list.channel === 'instagram'
        || list.source === 'Instagram'
        || /^IG:/i.test(list.list_name);
}

function isSpreadsheetImport(list: LeadList): boolean {
    return list.src === 'csv' && !isInstagramList(list);
}

function isLinkedInList(list: LeadList): boolean {
    return list.src === 'aud' || list.src === 'sn';
}

function displaySource(list: LeadList): string {
    if (isInstagramList(list)) return 'Instagram';
    if (isSpreadsheetImport(list)) return 'Imported';
    if (isLinkedInList(list)) return 'LinkedIn';
    return list.source;
}

function sortListsLatestFirst(lists: LeadList[]): LeadList[] {
    return [...lists].sort((a, b) => {
        const aTime = a.created_at ? Date.parse(a.created_at) : 0;
        const bTime = b.created_at ? Date.parse(b.created_at) : 0;
        return bTime - aTime;
    });
}

const instagramLists = computed(() => props.importLists.filter(isInstagramList));
const spreadsheetLists = computed(() => props.importLists.filter(isSpreadsheetImport));

const tabLists = computed(() => {
    switch (activeTab.value) {
        case 'linkedin':
            return props.lists;
        case 'instagram':
            return instagramLists.value;
        case 'imported':
            return spreadsheetLists.value;
        default:
            return sortListsLatestFirst([...props.lists, ...props.importLists]);
    }
});

const tabCounts = computed(() => ({
    all: props.lists.length + props.importLists.length,
    linkedin: props.lists.length,
    instagram: instagramLists.value.length,
    imported: spreadsheetLists.value.length,
}));

const {
    search,
    page,
    paginated,
    totalPages,
    total,
} = useClientList(tabLists, {
    perPage: 10,
    searchKeys: (l) => [l.list_name, l.source, l.list_hash],
});

const renameForm = useForm({ list_name: '', src: 'aud' as 'aud' | 'sn' | 'csv' });
const renaming = ref<LeadList | null>(null);
const selectedLists = ref<Set<string>>(new Set());

watch(activeTab, (tab) => {
    const url = new URL(window.location.href);
    if (tab === 'all') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', tab);
    }
    window.history.replaceState({}, '', url.toString());
    selectedLists.value = new Set();
});

function listKey(list: LeadList): string {
    return `${list.src}:${list.list_hash}`;
}

const allListsSelected = computed(() => {
    const lists = paginated.value;
    return lists.length > 0 && lists.every((l) => selectedLists.value.has(listKey(l)));
});

function toggleListSelection(list: LeadList) {
    const key = listKey(list);
    const set = selectedLists.value;
    if (set.has(key)) set.delete(key);
    else set.add(key);
    selectedLists.value = new Set(set);
}

function toggleAllLists() {
    const set = selectedLists.value;
    if (allListsSelected.value) {
        selectedLists.value = new Set();
    } else {
        selectedLists.value = new Set(paginated.value.map((l) => listKey(l)));
    }
}

function deleteSelectedLists() {
    const set = selectedLists.value;
    if (set.size === 0) return;
    if (!confirm(`Delete ${set.size} selected list(s) and all their contacts? This cannot be undone.`)) return;

    const lists = Array.from(set).map((key) => {
        const [src, ...hashParts] = key.split(':');
        return { src, list_hash: hashParts.join(':') };
    });

    router.delete('/leads/lists/bulk', {
        data: { lists },
        preserveScroll: true,
        onSuccess: () => {
            selectedLists.value = new Set();
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

function sourceBadgeClass(list: LeadList): string {
    if (isInstagramList(list)) {
        return 'bg-pink-500/10 text-pink-700 dark:text-pink-300';
    }
    if (isLinkedInList(list)) {
        return 'bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }
    if (isSpreadsheetImport(list)) {
        return 'bg-blue-500/10 text-blue-600';
    }

    return 'bg-muted text-muted-foreground';
}

const emptyState = computed(() => {
    switch (activeTab.value) {
        case 'linkedin':
            return {
                icon: Layers,
                title: 'No LinkedIn lists yet',
                description: 'Harvest audiences from Competitor Active Followers or import leads via the extension.',
            };
        case 'instagram':
            return {
                icon: null,
                title: 'No Instagram lists yet',
                description: 'Use Find Instagram leads to search by keyword, or add a profile URL.',
            };
        case 'imported':
            return {
                icon: FileSpreadsheet,
                title: 'No imported lists yet',
                description: 'Upload a spreadsheet with contacts for WhatsApp, email, or social outreach.',
            };
        default:
            return {
                icon: Layers,
                title: 'No lead lists yet',
                description: 'Find LinkedIn audiences, search Instagram, or import a spreadsheet to get started.',
            };
    }
});

const audienceListCount = computed(() => props.stats.audience_lists + props.stats.sn_lists);
const igIcon = brandIconSrc('instagram');
const liIcon = brandIconSrc('linkedin');
</script>

<template>
    <Head title="Leads" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <LinkedInPageHeading title="Leads" show-badge>
                <template #subtitle>
                    LinkedIn audiences, Instagram keyword search, and spreadsheet imports.
                </template>
            </LinkedInPageHeading>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <Button class="gap-2" @click="igSearchModalOpen = true">
                    <img :src="igIcon" alt="" class="h-4 w-4" />
                    Find Instagram leads
                </Button>
                <Button variant="outline" class="gap-2" @click="profileModalOpen = true">
                    <Link2 class="h-4 w-4" />
                    Add from profile URL
                </Button>
            </div>
        </div>

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
                    <p class="text-xl font-semibold">{{ audienceListCount.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-pink-500/10 p-2 text-pink-600"><Users2 class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Instagram lists</p>
                    <p class="text-xl font-semibold">{{ stats.instagram_lists.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
                <div class="rounded-lg bg-blue-500/10 p-2 text-blue-600"><FileSpreadsheet class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Imported lists</p>
                    <p class="text-xl font-semibold">{{ stats.import_lists.toLocaleString() }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-4">
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
                :class="activeTab === 'all' ? 'border-b-2 border-primary text-foreground' : 'text-muted-foreground hover:text-foreground'"
                @click="activeTab = 'all'"
            >
                <span class="inline-flex items-center gap-1.5">
                    <Layers class="h-3.5 w-3.5" />
                    All
                </span>
                <span class="ml-1.5 rounded-full bg-muted px-2 py-0.5 text-xs">{{ tabCounts.all }}</span>
            </button>
            <button
                type="button"
                class="rounded-t-lg px-4 py-2 text-sm font-medium transition-colors"
                :class="activeTab === 'linkedin' ? 'border-b-2 border-sky-600 text-foreground' : 'text-muted-foreground hover:text-foreground'"
                @click="activeTab = 'linkedin'"
            >
                <span class="inline-flex items-center gap-1.5">
                    <img :src="liIcon" alt="" class="h-3.5 w-3.5" />
                    LinkedIn
                </span>
                <span class="ml-1.5 rounded-full bg-muted px-2 py-0.5 text-xs">{{ tabCounts.linkedin }}</span>
            </button>
            <button
                type="button"
                class="rounded-t-lg px-4 py-2 text-sm font-medium transition-colors"
                :class="activeTab === 'instagram' ? 'border-b-2 border-pink-600 text-foreground' : 'text-muted-foreground hover:text-foreground'"
                @click="activeTab = 'instagram'"
            >
                <span class="inline-flex items-center gap-1.5">
                    <img :src="igIcon" alt="" class="h-3.5 w-3.5" />
                    Instagram
                </span>
                <span class="ml-1.5 rounded-full bg-muted px-2 py-0.5 text-xs">{{ tabCounts.instagram }}</span>
            </button>
            <button
                type="button"
                class="rounded-t-lg px-4 py-2 text-sm font-medium transition-colors"
                :class="activeTab === 'imported' ? 'border-b-2 border-blue-600 text-foreground' : 'text-muted-foreground hover:text-foreground'"
                @click="activeTab = 'imported'"
            >
                <span class="inline-flex items-center gap-1.5">
                    <FileSpreadsheet class="h-3.5 w-3.5" />
                    Imported
                </span>
                <span class="ml-1.5 rounded-full bg-muted px-2 py-0.5 text-xs">{{ tabCounts.imported }}</span>
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex min-w-[200px] flex-1 max-w-md items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
                <Search class="h-4 w-4 text-muted-foreground" />
                <input v-model="search" type="search" placeholder="Search lists…" class="w-full bg-transparent text-sm outline-none" />
            </div>
            <Button v-if="activeTab === 'imported'" class="gap-2" @click="importModalOpen = true">
                <Upload class="h-4 w-4" />
                Import spreadsheet
            </Button>
        </div>

        <div v-if="selectedLists.size > 0" class="flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm">
            <span class="font-medium">{{ selectedLists.size }} list(s) selected</span>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md bg-gradient-to-b from-red-500 to-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm"
                @click="deleteSelectedLists"
            >
                <Trash2 class="h-3.5 w-3.5" /> Delete lists
            </button>
            <button type="button" class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selectedLists = new Set()">Clear</button>
        </div>

        <div v-if="total === 0" class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-border bg-muted/20 p-12 text-center">
            <img v-if="activeTab === 'instagram'" :src="igIcon" alt="" class="h-10 w-10 opacity-60" />
            <component :is="emptyState.icon" v-else-if="emptyState.icon" class="h-10 w-10 text-muted-foreground/40" />
            <Layers v-else class="h-10 w-10 text-muted-foreground/40" />
            <div>
                <p class="font-medium">{{ emptyState.title }}</p>
                <p class="mt-1 text-sm text-muted-foreground">{{ emptyState.description }}</p>
            </div>
            <Button v-if="activeTab === 'imported'" class="gap-2" @click="importModalOpen = true">
                <Plus class="h-4 w-4" />
                Import spreadsheet
            </Button>
            <Button v-else-if="activeTab === 'instagram'" class="gap-2" @click="igSearchModalOpen = true">
                <img :src="igIcon" alt="" class="h-4 w-4" />
                Find Instagram leads
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
                        <th class="px-4 py-3 text-right font-medium">{{ activeTab === 'imported' || activeTab === 'instagram' ? 'Contacts' : 'Leads' }}</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="list in paginated" :key="list.src + list.list_hash" class="hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <button type="button" class="inline-flex" @click="toggleListSelection(list)">
                                <AppSelectionCheckbox :checked="selectedLists.has(listKey(list))" />
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <Link :href="listHref(list)" class="inline-flex items-center gap-2 font-medium text-foreground hover:text-blue-600 hover:underline">
                                <img
                                    v-if="isInstagramList(list)"
                                    :src="igIcon"
                                    alt=""
                                    class="h-4 w-4 shrink-0"
                                    title="Instagram"
                                />
                                <img
                                    v-else-if="isLinkedInList(list)"
                                    :src="liIcon"
                                    alt=""
                                    class="h-4 w-4 shrink-0"
                                    title="LinkedIn"
                                />
                                <FileSpreadsheet
                                    v-else-if="isSpreadsheetImport(list)"
                                    class="h-4 w-4 shrink-0 text-blue-600"
                                    title="Imported"
                                />
                                {{ list.list_name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="sourceBadgeClass(list)"
                            >
                                <img
                                    v-if="isInstagramList(list)"
                                    :src="igIcon"
                                    alt=""
                                    class="h-3 w-3"
                                />
                                <img
                                    v-else-if="isLinkedInList(list)"
                                    :src="liIcon"
                                    alt=""
                                    class="h-3 w-3"
                                />
                                <FileSpreadsheet
                                    v-else-if="isSpreadsheetImport(list)"
                                    class="h-3 w-3"
                                />
                                {{ displaySource(list) }}
                            </span>
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

    <Dialog v-model:open="igSearchModalOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="inline-flex items-center gap-2">
                    <img :src="igIcon" alt="" class="h-5 w-5" />
                    Find Instagram leads
                </DialogTitle>
                <DialogDescription>
                    Mindcase Search mode — keyword to find Instagram accounts (e.g. coffee, nasa). Max 100 results. Instagram handle is filled; other channels stay empty.
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-3">
                <input
                    v-model="igQuery"
                    type="text"
                    placeholder="Keyword e.g. coffee, nasa, fitness Lagos"
                    class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
                    @keydown.enter.prevent="searchInstagram"
                />
                <div class="flex items-center gap-2">
                    <label class="shrink-0 text-sm text-muted-foreground">Max results</label>
                    <input
                        v-model.number="igLimit"
                        type="number"
                        min="1"
                        max="100"
                        class="w-24 rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
                    />
                    <span class="text-xs text-muted-foreground">max 100</span>
                </div>
                <input
                    v-model="igListName"
                    type="text"
                    placeholder="Optional list name"
                    class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
                />
                <p v-if="igError" class="text-xs text-red-500">{{ igError }}</p>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" type="button" @click="igSearchModalOpen = false">Cancel</Button>
                    <Button type="button" :disabled="igBusy" @click="searchInstagram">
                        {{ igBusy ? 'Searching…' : 'Search & save' }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="profileModalOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Add from profile URL</DialogTitle>
                <DialogDescription>
                    Exact person only. LinkedIn URL fills LinkedIn fields; Instagram URL/@handle fills Instagram. Prefer keyword search when you don’t know the profiles yet.
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-3">
                <input
                    v-model="profileUrl"
                    type="url"
                    placeholder="https://www.linkedin.com/in/… or https://www.instagram.com/…"
                    class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
                    @keydown.enter.prevent="importProfile"
                />
                <input
                    v-model="profileListName"
                    type="text"
                    placeholder="Optional list name"
                    class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
                />
                <p v-if="profileError" class="text-xs text-red-500">{{ profileError }}</p>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" type="button" @click="profileModalOpen = false">Cancel</Button>
                    <Button type="button" :disabled="profileBusy" @click="importProfile">
                        {{ profileBusy ? 'Saving…' : 'Save lead list' }}
                    </Button>
                </div>
            </div>
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
