<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, Search } from '@lucide/vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import { computed, ref } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Alex errors', href: '/admin/ai-errors' },
        ],
    },
});

interface ErrorRow {
    id: number;
    source: string;
    channel: string | null;
    exception_class: string | null;
    message: string;
    user_message: string | null;
    context: Record<string, unknown> | null;
    trace: string | null;
    organization_id: number | null;
    conversation_id: number | null;
    user: { id: number; name: string; email: string } | null;
    created_at: string | null;
}

const props = defineProps<{
    logs: {
        data: ErrorRow[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { source: string; channel: string; q: string };
}>();

const searchQ = ref(props.filters.q);
const source = ref(props.filters.source);
const channel = ref(props.filters.channel);
const expandedId = ref<number | null>(null);

const hasLogs = computed(() => props.logs.total > 0);

function runSearch() {
    router.get(
        '/admin/ai-errors',
        {
            q: searchQ.value || undefined,
            source: source.value || undefined,
            channel: channel.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function toggle(id: number) {
    expandedId.value = expandedId.value === id ? null : id;
}

function formatWhen(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}
</script>

<template>
    <Head title="Alex error logs" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Alex error logs</h1>
                <p class="text-muted-foreground mt-1 text-sm">
                    Platform admin only — what was happening when Alex hit an error.
                </p>
            </div>
            <div class="text-muted-foreground text-sm">{{ logs.total }} total</div>
        </div>

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="runSearch">
            <label class="flex min-w-[180px] flex-1 flex-col gap-1 text-xs">
                <span class="text-muted-foreground">Search</span>
                <input
                    v-model="searchQ"
                    type="search"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    placeholder="Message, exception, user prompt…"
                />
            </label>
            <label class="flex w-40 flex-col gap-1 text-xs">
                <span class="text-muted-foreground">Source</span>
                <input
                    v-model="source"
                    type="text"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    placeholder="any (e.g. agent_turn, launch:…)"
                />
            </label>
            <label class="flex w-32 flex-col gap-1 text-xs">
                <span class="text-muted-foreground">Channel</span>
                <input
                    v-model="channel"
                    type="text"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    placeholder="any (web, whatsapp)"
                />
            </label>
            <AppToolbarButton type="submit" class="h-9">
                <Search class="size-4" />
                Filter
            </AppToolbarButton>
        </form>

        <div
            v-if="!hasLogs"
            class="border-border text-muted-foreground flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-sm"
        >
            <AlertTriangle class="size-8 opacity-40" />
            <span>No Alex errors logged yet.</span>
            <span
                v-if="filters.source || filters.channel || filters.q"
                class="text-xs opacity-80"
            >
                Clear Source/Channel filters — launch failures use source like
                <code class="text-foreground">launch:…</code>, not only
                <code class="text-foreground">agent_turn</code>.
            </span>
        </div>

        <div v-else class="border-border overflow-hidden rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/40 text-muted-foreground text-xs uppercase">
                    <tr>
                        <th class="px-3 py-2 font-medium">When</th>
                        <th class="px-3 py-2 font-medium">User</th>
                        <th class="px-3 py-2 font-medium">Source</th>
                        <th class="px-3 py-2 font-medium">Error</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in logs.data" :key="row.id">
                        <tr
                            class="border-border hover:bg-muted/30 cursor-pointer border-t"
                            @click="toggle(row.id)"
                        >
                            <td class="text-muted-foreground whitespace-nowrap px-3 py-2 align-top text-xs">
                                {{ formatWhen(row.created_at) }}
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div class="font-medium">{{ row.user?.name ?? '—' }}</div>
                                <div class="text-muted-foreground text-xs">{{ row.user?.email }}</div>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div>{{ row.source }}</div>
                                <div class="text-muted-foreground text-xs">
                                    {{ row.channel ?? '—' }}
                                    <span v-if="row.conversation_id"> · conv #{{ row.conversation_id }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div class="line-clamp-2">{{ row.message }}</div>
                                <div
                                    v-if="row.user_message"
                                    class="text-muted-foreground mt-1 line-clamp-1 text-xs"
                                >
                                    Prompt: {{ row.user_message }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="expandedId === row.id" class="border-border bg-muted/20 border-t">
                            <td colspan="4" class="space-y-3 px-3 py-3 text-xs">
                                <div>
                                    <div class="text-muted-foreground mb-1 font-medium">Exception</div>
                                    <code>{{ row.exception_class }}</code>
                                </div>
                                <div v-if="row.user_message">
                                    <div class="text-muted-foreground mb-1 font-medium">User message</div>
                                    <pre class="bg-background overflow-x-auto rounded border p-2 whitespace-pre-wrap">{{ row.user_message }}</pre>
                                </div>
                                <div v-if="row.context">
                                    <div class="text-muted-foreground mb-1 font-medium">Context</div>
                                    <pre class="bg-background overflow-x-auto rounded border p-2">{{ JSON.stringify(row.context, null, 2) }}</pre>
                                </div>
                                <div v-if="row.trace">
                                    <div class="text-muted-foreground mb-1 font-medium">Trace</div>
                                    <pre class="bg-background max-h-64 overflow-auto rounded border p-2">{{ row.trace }}</pre>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div v-if="logs.last_page > 1" class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground">Page {{ logs.current_page }} / {{ logs.last_page }}</span>
            <div class="flex gap-2">
                <AppToolbarButton
                    v-if="logs.prev_page_url"
                    type="button"
                    @click="router.get(logs.prev_page_url!, {}, { preserveState: true })"
                >
                    Previous
                </AppToolbarButton>
                <AppToolbarButton
                    v-if="logs.next_page_url"
                    type="button"
                    @click="router.get(logs.next_page_url!, {}, { preserveState: true })"
                >
                    Next
                </AppToolbarButton>
            </div>
        </div>
    </div>
</template>
