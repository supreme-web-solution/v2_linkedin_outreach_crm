<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    paginator: {
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        total?: number;
        from?: number | null;
        to?: number | null;
    };
    label?: string;
}>();
</script>

<template>
    <div
        v-if="paginator.last_page > 1 || (paginator.total ?? 0) > 0"
        class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
    >
        <span>
            <template v-if="paginator.from && paginator.to && paginator.total">
                Showing {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total.toLocaleString() }}
            </template>
            <template v-else>
                Page {{ paginator.current_page }} of {{ paginator.last_page }}
                <span v-if="paginator.total !== undefined"> · {{ paginator.total.toLocaleString() }} {{ label ?? 'items' }}</span>
            </template>
        </span>
        <div class="flex items-center gap-1">
            <Link
                v-if="paginator.prev_page_url"
                :href="paginator.prev_page_url"
                class="rounded-lg border border-border px-3 py-1.5 hover:bg-muted"
                preserve-scroll
            >
                Prev
            </Link>
            <Link
                v-if="paginator.next_page_url"
                :href="paginator.next_page_url"
                class="rounded-lg border border-border px-3 py-1.5 hover:bg-muted"
                preserve-scroll
            >
                Next
            </Link>
        </div>
    </div>
</template>
