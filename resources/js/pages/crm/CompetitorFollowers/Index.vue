<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Loader2, Search, Trash2, TrendingUp, Users2 } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import ListPagination from '@/components/crm/ListPagination.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Competitor Active Followers', href: '/competitor-followers' },
        ],
    },
});

interface AudienceRow {
    id: number;
    audience_name: string | null;
    followers_count: number;
    fetch_status: string | null;
    fetch_progress: string | null;
    company_url: string | null;
    last_error: string | null;
    last_error_type: string | null;
}

const props = defineProps<{
    audiences: {
        data: AudienceRow[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    hasLinkedInSession: boolean;
    filters: { search: string | null };
}>();

const search = ref(props.filters?.search ?? '');

function applySearch() {
    router.get('/competitor-followers', {
        search: search.value || undefined,
    }, { preserveState: true, replace: true });
}

const form = useForm({ company_url: '' });

function submit() {
    form.post('/competitor-followers/fetch', {
        preserveScroll: true,
        onSuccess: () => form.reset('company_url'),
    });
}

function csrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function destroy(id: number) {
    if (!confirm('Delete this audience and all its followers? This cannot be undone.')) return;
    await fetch(`/competitor-followers/${id}/delete`, {
        method: 'DELETE',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ delete_audience: 1 }),
    });
    router.reload({ only: ['audiences'] });
}

const statuses = ref<Record<number, { status: string | null; progress: string | null; followers_count: number }>>({});
let timer: ReturnType<typeof setInterval> | null = null;

function isRunning(row: AudienceRow): boolean {
    const s = statuses.value[row.id]?.status ?? row.fetch_status;
    return s === 'pending' || s === 'processing';
}

function statusOf(row: AudienceRow): string | null {
    return statuses.value[row.id]?.status ?? row.fetch_status;
}

function progressOf(row: AudienceRow): string | null {
    return statuses.value[row.id]?.progress ?? row.fetch_progress;
}

function countOf(row: AudienceRow): number {
    return statuses.value[row.id]?.followers_count ?? row.followers_count;
}

async function pollRunning() {
    const running = props.audiences.data.filter((r) => isRunning(r));
    if (running.length === 0) return;

    let anyCompleted = false;
    await Promise.all(
        running.map(async (row) => {
            try {
                const res = await fetch(`/competitor-followers/${row.id}/status`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                statuses.value[row.id] = {
                    status: data.status,
                    progress: data.progress,
                    followers_count: data.followers_count ?? row.followers_count,
                };
                if (data.status === 'completed' || data.status === 'failed') anyCompleted = true;
            } catch {
                /* ignore transient poll errors */
            }
        }),
    );
    if (anyCompleted) router.reload({ only: ['audiences'] });
}

onMounted(() => {
    timer = setInterval(pollRunning, 4000);
});
onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <Head title="Competitor Active Followers" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold text-foreground">Competitor Active Followers</h1>
            <p class="text-sm text-muted-foreground">
                Pull people who like or comment on a competitor's LinkedIn posts into a targeted audience (via Unipile).
            </p>
        </div>

        <!-- Session warning -->
        <div
            v-if="!hasLinkedInSession"
            class="flex items-start gap-3 rounded-xl border border-yellow-500/40 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400"
        >
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
            <div>
                <strong>LinkedIn (Unipile) required.</strong>
                Connect your account on Integrations before building audiences.
                <Link href="/integrations" class="font-semibold underline">Go to Integrations →</Link>
            </div>
        </div>

        <!-- Fetch form -->
        <form
            class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 shadow-sm sm:flex-row sm:items-end"
            @submit.prevent="submit"
        >
            <div class="flex-1">
                <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    LinkedIn company URL
                </label>
                <input
                    v-model="form.company_url"
                    type="url"
                    required
                    placeholder="https://www.linkedin.com/company/microsoft/"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
                />
                <p v-if="form.errors.company_url" class="mt-1 text-xs text-red-500">{{ form.errors.company_url }}</p>
            </div>
            <button
                type="submit"
                :disabled="form.processing || !hasLinkedInSession"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90 disabled:opacity-50"
            >
                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
                <TrendingUp v-else class="h-4 w-4" />
                Fetch followers
            </button>
        </form>

        <div class="flex max-w-md items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
            <Search class="h-4 w-4 text-muted-foreground" />
            <input
                v-model="search"
                type="search"
                placeholder="Search audiences…"
                class="w-full bg-transparent text-sm outline-none"
                @keydown.enter="applySearch"
            />
            <button type="button" class="text-xs font-medium text-primary hover:underline" @click="applySearch">Search</button>
        </div>

        <!-- Empty -->
        <div
            v-if="audiences.data.length === 0"
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center"
        >
            <Users2 class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No competitor audiences yet</p>
            <p class="text-sm text-muted-foreground">Paste a competitor's company URL above to build your first audience.</p>
        </div>

        <!-- List -->
        <div v-else class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Audience</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Followers</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in audiences.data" :key="row.id" class="transition hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <div class="font-medium text-foreground">{{ row.audience_name || 'Untitled' }}</div>
                            <div v-if="row.company_url" class="truncate text-xs text-muted-foreground">{{ row.company_url }}</div>
                        </td>
                        <td class="px-4 py-3 font-semibold">{{ countOf(row).toLocaleString() }}</td>
                        <td class="px-4 py-3">
                            <span
                                v-if="isRunning(row)"
                                class="inline-flex items-center gap-1.5 rounded-full bg-blue-500/10 px-2 py-0.5 text-xs font-medium text-blue-600"
                            >
                                <Loader2 class="h-3 w-3 animate-spin" />
                                {{ progressOf(row) || 'Working…' }}
                            </span>
                            <span
                                v-else-if="statusOf(row) === 'completed'"
                                class="rounded-full bg-green-500/10 px-2 py-0.5 text-xs font-medium text-green-600"
                            >
                                Completed
                            </span>
                            <span
                                v-else-if="statusOf(row) === 'failed'"
                                class="rounded-full bg-red-500/10 px-2 py-0.5 text-xs font-medium text-red-600"
                                :title="row.last_error || ''"
                            >
                                Failed
                            </span>
                            <span v-else class="text-xs text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Link
                                    :href="`/competitor-followers/${row.id}`"
                                    class="rounded border border-border px-3 py-1 text-xs font-medium hover:bg-muted"
                                >
                                    View
                                </Link>
                                <button
                                    type="button"
                                    class="rounded border border-border p-1.5 text-muted-foreground transition hover:border-red-400 hover:text-red-500"
                                    @click="destroy(row.id)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <ListPagination :paginator="audiences" label="audiences" />
        </div>
    </div>
</template>
