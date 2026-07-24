<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Database, Layers, Pencil, Search, Trash2, Users2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ClientPagination from '@/components/crm/ClientPagination.vue';
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
    src: 'aud' | 'sn';
    created_at: string | null;
}

const props = defineProps<{
    lists: LeadList[];
    stats: { total_lists: number; audience_lists: number; sn_lists: number; total_leads: number };
}>();

const sourceFilter = ref<'all' | 'aud' | 'sn'>('all');

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

watch(sourceFilter, () => {
    page.value = 1;
});

const renameForm = useForm({ list_name: '', src: 'aud' as 'aud' | 'sn' });
const renaming = ref<LeadList | null>(null);

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

function fmtDate(iso: string | null): string {
    return iso ? iso.slice(0, 10) : '—';
}
</script>

<template>
    <Head title="Leads" />

    <div class="flex flex-col gap-5 p-4">
        <div>
            <h1 class="text-xl font-semibold text-foreground">Leads</h1>
            <p class="text-sm text-muted-foreground">
                Audiences, Sales Navigator lists, and extension imports share one pipeline — SN imports from the Chrome extension appear here automatically.
            </p>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
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
                <div class="rounded-lg bg-green-500/10 p-2 text-green-500"><Users2 class="h-5 w-5" /></div>
                <div>
                    <p class="text-xs text-muted-foreground">Total leads</p>
                    <p class="text-xl font-semibold">{{ stats.total_leads.toLocaleString() }}</p>
                </div>
            </div>
        </div>

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

        <div v-if="total === 0" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <Layers class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No lead lists yet</p>
            <p class="text-sm text-muted-foreground">Harvest audiences from Competitor Active Followers or import Sales Navigator leads via the extension.</p>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40 text-left text-xs uppercase text-muted-foreground">
                    <tr>
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
                            <Link :href="`/leads/${encodeURIComponent(list.list_hash)}?src=${list.src}`" class="font-medium text-foreground hover:text-primary hover:underline">
                                {{ list.list_name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="list.src === 'aud' ? 'bg-blue-500/10 text-blue-600' : 'bg-amber-500/10 text-amber-600'"
                            >{{ list.source }}</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ list.total_leads.toLocaleString() }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ fmtDate(list.created_at) }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
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
