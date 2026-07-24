<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { MessageSquare, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import ListPagination from '@/components/crm/ListPagination.vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Conversations', href: '/conversations' }] },
});

const props = defineProps<{
    conversations: {
        data: Array<{
            id: number;
            call_id: number | null;
            provider: string;
            provider_chat_id: string | null;
            prospect_name: string | null;
            prospect_headline: string | null;
            status: string;
            last_message_at: string | null;
            created_at: string;
            messages_count: number;
            last_message_preview: string | null;
            has_chat_link: boolean;
        }>;
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        from?: number | null;
        to?: number | null;
    };
    hasUnipile: boolean;
    filters: { search: string | null; status: string | null };
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const search = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? 'all');
const selected = ref<Set<number>>(new Set());

const pageIds = computed(() => props.conversations.data.map((c) => c.id));
const allSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.has(id)));

function applyFilters() {
    selected.value = new Set();
    router.get('/conversations', {
        search: search.value || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
    }, { preserveState: true, replace: true });
}

function toggle(id: number) {
    const next = new Set(selected.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected.value = next;
}

function toggleAll() {
    const next = new Set(selected.value);
    if (allSelected.value) {
        pageIds.value.forEach((id) => next.delete(id));
    } else {
        pageIds.value.forEach((id) => next.add(id));
    }
    selected.value = next;
}

function deleteSelected() {
    if (selected.value.size === 0) return;
    if (!confirm(`Remove ${selected.value.size} conversation(s) from the CRM? LinkedIn chats are not deleted on LinkedIn.`)) return;

    router.delete('/conversations/bulk', {
        data: { ids: Array.from(selected.value) },
        preserveScroll: true,
        onSuccess: () => {
            selected.value = new Set();
        },
    });
}

function deleteConversation(id: number, name: string) {
    if (!confirm(`Remove "${name}" from the CRM? LinkedIn chat is not deleted on LinkedIn.`)) return;

    router.delete(`/conversations/${id}`, { preserveScroll: true });
}

function conversationHref(conv: (typeof props.conversations.data)[0]) {
    return conv.call_id ? `/calls/${conv.call_id}` : `/conversations/${conv.id}`;
}

function displayName(conv: (typeof props.conversations.data)[0]) {
    return conv.prospect_name || conv.provider_chat_id || `Thread #${conv.id}`;
}

function chatLabel(conv: (typeof props.conversations.data)[0]) {
    if (conv.provider_chat_id) {
        return conv.provider_chat_id.length > 24
            ? `${conv.provider_chat_id.slice(0, 24)}…`
            : conv.provider_chat_id;
    }
    return 'Pending LinkedIn link';
}

const statusColor = (s: string) => {
    if (s === 'active') return 'text-green-600';
    if (s === 'archived') return 'text-orange-500';
    if (s === 'read') return 'text-blue-500';
    return 'text-muted-foreground';
};
</script>

<template>
    <Head title="Conversations" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Conversations</h1>
                <p class="text-sm text-muted-foreground">{{ conversations.total }} Call Manager thread(s) — only prospects you outreach from the CRM appear here.</p>
            </div>
        </div>

        <p v-if="flashSuccess" class="rounded-lg bg-green-100 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ flashSuccess }}</p>
        <p v-if="flashError" class="rounded-lg bg-red-100 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ flashError }}</p>

        <div v-if="!hasUnipile" class="flex items-center gap-2 rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-4 py-3 text-sm text-yellow-800 dark:text-yellow-300">
            Connect LinkedIn via <Link href="/integrations" class="underline">Integrations</Link> to send replies from Call Manager.
        </div>

        <ListSearchBar v-model="search" placeholder="Search by name or chat ID…" @search="applyFilters">
            <template #filters>
                <select
                    v-model="statusFilter"
                    class="rounded-lg border border-border bg-card px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="all">All statuses</option>
                    <option value="active">Active</option>
                    <option value="read">Read</option>
                    <option value="archived">Archived</option>
                </select>
            </template>
        </ListSearchBar>

        <div v-if="selected.size > 0" class="flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm">
            <span class="font-medium">{{ selected.size }} selected</span>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-card px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:border-red-900/40 dark:hover:bg-red-900/20"
                @click="deleteSelected"
            >
                <Trash2 class="h-3.5 w-3.5" /> Delete selected
            </button>
            <button type="button" class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selected = new Set()">Clear</button>
        </div>

        <div v-if="conversations.data.length === 0" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <MessageSquare class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No conversations yet</p>
            <p class="max-w-md text-sm text-muted-foreground">
                Launch outreach from <Link href="/calls" class="underline">Call Manager</Link> — a thread appears here once you message a prospect.
            </p>
            <Link
                href="/calls"
                class="mt-2 inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
            >
                Open Call Manager
            </Link>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th class="w-10 px-4 py-3">
                            <button type="button" @click="toggleAll">
                                <AppSelectionCheckbox :checked="allSelected" />
                            </button>
                        </th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Prospect</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Preview</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Messages</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Last activity</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr
                        v-for="conv in conversations.data"
                        :key="conv.id"
                        class="transition hover:bg-muted/30"
                        :class="selected.has(conv.id) ? 'bg-primary/5' : ''"
                    >
                        <td class="px-4 py-3">
                            <button type="button" @click="toggle(conv.id)">
                                <AppSelectionCheckbox :checked="selected.has(conv.id)" />
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ displayName(conv) }}</p>
                            <p v-if="conv.prospect_headline" class="line-clamp-1 text-xs text-muted-foreground">{{ conv.prospect_headline }}</p>
                            <p class="mt-0.5 font-mono text-[10px] text-muted-foreground">{{ chatLabel(conv) }}</p>
                        </td>
                        <td class="max-w-xs px-4 py-3 text-xs text-muted-foreground">
                            <span v-if="conv.last_message_preview" class="line-clamp-2">{{ conv.last_message_preview }}</span>
                            <span v-else>—</span>
                        </td>
                        <td class="px-4 py-3 text-xs font-medium capitalize" :class="statusColor(conv.status)">{{ conv.status }}</td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ conv.messages_count }}</td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ conv.last_message_at?.slice(0, 16) ?? conv.created_at?.slice(0, 16) ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link :href="conversationHref(conv)" class="text-xs text-primary underline underline-offset-2">View</Link>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs text-red-600 hover:underline"
                                    @click="deleteConversation(conv.id, displayName(conv))"
                                >
                                    <Trash2 class="h-3 w-3" /> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <ListPagination :paginator="conversations" label="conversations" />
        </div>
    </div>
</template>
