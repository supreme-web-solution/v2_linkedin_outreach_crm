<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import { ArrowLeft, Download, ExternalLink, Search, Trash2 } from '@lucide/vue';
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
    email: string | null;
    phone: string | null;
    profile_url: string | null;
    instagram_handle: string | null;
    telegram_handle: string | null;
    twitter_handle: string | null;
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
}>();

const searchTerm = ref(props.search ?? '');
const selected = ref<Set<number>>(new Set());
const busy = ref(false);
const flash = ref('');
const flashError = ref('');

const allSelected = computed(() => props.leads.data.length > 0 && props.leads.data.every((l) => selected.value.has(l.id)));

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
        const res = await fetch(`/leads/${props.listId}/export?src=csv`, { headers: { Accept: 'application/json' } });
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
        a.download = `${props.listName.replace(/[^a-z0-9]+/gi, '_')}_contacts.csv`;
        a.click();
        URL.revokeObjectURL(url);
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
                <AppToolbarButton variant="danger" @click="deleteList"><Trash2 class="h-4 w-4" /> Delete list</AppToolbarButton>
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
            <AppToolbarButton v-if="selected.size" variant="danger" @click="deleteSelected">
                <Trash2 class="h-4 w-4" /> Delete {{ selected.size }}
            </AppToolbarButton>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40 text-left text-xs uppercase text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            <AppSelectionCheckbox :checked="allSelected" @change="toggleAll" />
                        </th>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Phone</th>
                        <th class="px-4 py-3 font-medium">LinkedIn</th>
                        <th class="px-4 py-3 font-medium">Social</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="lead in leads.data" :key="lead.id" class="hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <AppSelectionCheckbox :checked="selected.has(lead.id)" @change="toggle(lead.id)" />
                        </td>
                        <td class="px-4 py-3 font-medium">{{ lead.full_name || '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ lead.email || '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ lead.phone || '—' }}</td>
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
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            <span v-if="lead.instagram_handle">IG @{{ lead.instagram_handle }}</span>
                            <span v-if="lead.telegram_handle" class="ml-2">TG @{{ lead.telegram_handle }}</span>
                            <span v-if="lead.twitter_handle" class="ml-2">X @{{ lead.twitter_handle }}</span>
                            <span v-if="!lead.instagram_handle && !lead.telegram_handle && !lead.twitter_handle">—</span>
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
