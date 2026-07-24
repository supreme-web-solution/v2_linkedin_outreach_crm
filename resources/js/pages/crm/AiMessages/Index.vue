<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ListPagination from '@/components/crm/ListPagination.vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'AI Messages', href: '/ai-messages' },
        ],
    },
});

interface AiContentRow {
    id: number;
    title: string;
    ai_type: string;
    contents: string | null;
    word_counts: number;
    created_at: string;
}

const props = defineProps<{
    aicontents: {
        data: AiContentRow[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        from?: number | null;
        to?: number | null;
    };
    filters: { title: string | null };
}>();

const search = ref(props.filters.title ?? '');

function runSearch() {
    router.get('/ai-messages', { title: search.value || undefined }, { preserveState: true, replace: true });
}

function destroy(id: number) {
    if (!confirm('Delete this message?')) return;
    router.delete(`/ai-messages/${id}`, { preserveScroll: true });
}

const typeLabels: Record<string, string> = {
    first_cold_email: 'Cold email',
    linkedin_connection_message: 'Connection message',
    personalized_ice_breaker: 'Ice-breaker',
    linkedin_post: 'LinkedIn post',
    book_call_message: 'Book-a-call',
};
</script>

<template>
    <Head title="AI Messages" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-foreground">AI Messages</h1>
                <p class="text-sm text-muted-foreground">{{ aicontents.total.toLocaleString() }} saved messages.</p>
            </div>
            <Link href="/ai-messages/new" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700">
                <Plus class="h-4 w-4" /> New message
            </Link>
        </div>

        <ListSearchBar v-model="search" placeholder="Search by title…" @search="runSearch" />

        <div v-if="aicontents.data.length === 0" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <FileText class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No saved messages yet</p>
            <p class="text-sm text-muted-foreground">Generate cold emails, connection messages and ice-breakers with AI.</p>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="item in aicontents.data" :key="item.id" class="flex flex-col rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <h3 class="font-medium text-foreground">{{ item.title }}</h3>
                    <span class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">{{ typeLabels[item.ai_type] || item.ai_type }}</span>
                </div>
                <p class="line-clamp-4 flex-1 whitespace-pre-line text-sm text-muted-foreground">{{ item.contents }}</p>
                <div class="mt-3 flex items-center justify-between border-t border-border pt-3 text-xs text-muted-foreground">
                    <span>{{ item.word_counts }} words · {{ item.created_at.slice(0, 10) }}</span>
                    <div class="flex items-center gap-1">
                        <Link :href="`/ai-messages/${item.id}/edit`" class="rounded p-1.5 hover:bg-muted hover:text-foreground"><Pencil class="h-4 w-4" /></Link>
                        <button type="button" class="rounded p-1.5 hover:bg-muted hover:text-red-500" @click="destroy(item.id)"><Trash2 class="h-4 w-4" /></button>
                    </div>
                </div>
            </div>
        </div>

        <ListPagination v-if="aicontents.data.length" :paginator="aicontents" label="messages" />
    </div>
</template>
