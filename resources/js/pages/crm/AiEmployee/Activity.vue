<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, History, Loader2, Undo2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import ListPagination from '@/components/crm/ListPagination.vue';
import {
    formatChatDateDivider,
    formatChatMessageTime,
} from '@/lib/chatTimeline';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'AI Employee', href: '/ai-employee' },
            { title: 'Activity', href: '/ai-employee/activity' },
        ],
    },
});

type ActionHistoryItem = {
    id: number;
    tool: string;
    label: string;
    status: string;
    summary: string;
    can_undo: boolean;
    undo_hint: string;
    undone_at?: string | null;
    created_at?: string | null;
    error?: string | null;
};

type PageLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    employee_name: string;
    actions: {
        data: ActionHistoryItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from?: number | null;
        to?: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
        links?: PageLink[];
    };
}>();

const undoingId = ref<number | null>(null);
const flash = ref<string | null>(null);

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
    );
}

function statusClass(item: ActionHistoryItem): string {
    if (item.undone_at) {
        return 'bg-slate-100 text-slate-700 dark:bg-slate-900 dark:text-slate-200';
    }
    if (item.status === 'success') {
        return 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';
    }
    return 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200';
}

async function undoAction(item: ActionHistoryItem) {
    if (!item.can_undo) return;
    undoingId.value = item.id;
    flash.value = null;
    try {
        const res = await fetch(`/ai-employee/actions/${item.id}/undo`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message ?? 'Undo failed');
        flash.value = data.message ?? 'Undone.';
        router.reload({ only: ['actions'], preserveScroll: true });
    } catch (e) {
        flash.value = e instanceof Error ? e.message : 'Could not undo that action.';
    } finally {
        undoingId.value = null;
    }
}
</script>

<template>
    <Head title="Soci Activity" />

    <div class="mx-auto flex max-w-3xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link
                    href="/ai-employee"
                    class="text-muted-foreground mb-2 inline-flex items-center gap-1 text-xs hover:text-foreground"
                >
                    <ArrowLeft class="size-3.5" />
                    Back to Command Center
                </Link>
                <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                    <History class="size-6" />
                    Activity
                </h1>
                <p class="text-muted-foreground mt-1 text-sm">
                    Everything {{ employee_name }} prepared or executed. Undo pause, activate, nurture, or cancel a scheduled post when available.
                </p>
            </div>
        </div>

        <p
            v-if="flash"
            class="rounded-lg border bg-muted/40 px-3 py-2 text-sm"
        >
            {{ flash }}
        </p>

        <div class="overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-card">
            <div v-if="actions.data.length === 0" class="p-10 text-center">
                <p class="text-muted-foreground text-sm">No execute actions yet.</p>
                <Button class="mt-4" size="sm" variant="secondary" as-child>
                    <Link href="/ai-employee">Ask {{ employee_name }}</Link>
                </Button>
            </div>

            <ul v-else class="divide-y">
                <li
                    v-for="item in actions.data"
                    :key="item.id"
                    class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-sm">{{ item.label }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase"
                                :class="statusClass(item)"
                            >
                                {{ item.undone_at ? 'Undone' : item.status }}
                            </span>
                        </div>
                        <p class="text-muted-foreground text-sm">{{ item.summary }}</p>
                        <p class="text-muted-foreground text-[11px]">
                            #{{ item.id }}
                            <span v-if="item.created_at">
                                · {{ formatChatDateDivider(item.created_at) }}
                                · {{ formatChatMessageTime(item.created_at) }}
                            </span>
                        </p>
                    </div>
                    <div class="shrink-0">
                        <Button
                            v-if="item.can_undo"
                            size="sm"
                            variant="outline"
                            :disabled="undoingId === item.id"
                            @click="undoAction(item)"
                        >
                            <Loader2 v-if="undoingId === item.id" class="mr-1 size-3.5 animate-spin" />
                            <Undo2 v-else class="mr-1 size-3.5" />
                            {{ item.undo_hint }}
                        </Button>
                        <p v-else class="text-muted-foreground max-w-[12rem] text-right text-[11px]">
                            {{ item.undo_hint }}
                        </p>
                    </div>
                </li>
            </ul>

            <ListPagination :paginator="actions" label="actions" />
        </div>
    </div>
</template>
