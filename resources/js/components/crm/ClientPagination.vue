<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    page: number;
    totalPages: number;
    total: number;
    perPage: number;
    label?: string;
}>();

const emit = defineEmits<{
    'update:page': [page: number];
}>();

const pages = computed(() => {
    const items: Array<number | 'ellipsis'> = [];
    const total = props.totalPages;
    const current = props.page;

    if (total <= 7) {
        for (let i = 1; i <= total; i++) {
            items.push(i);
        }
        return items;
    }

    items.push(1);

    if (current > 3) {
        items.push('ellipsis');
    }

    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);

    for (let i = start; i <= end; i++) {
        items.push(i);
    }

    if (current < total - 2) {
        items.push('ellipsis');
    }

    items.push(total);

    return items;
});

function go(next: number) {
    emit('update:page', Math.min(Math.max(1, next), props.totalPages));
}

function navClass(enabled: boolean): string {
    return [
        'inline-flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm font-medium transition-colors',
        enabled
            ? 'text-foreground hover:bg-muted'
            : 'pointer-events-none text-muted-foreground/40',
    ].join(' ');
}

function pageClass(active: boolean): string {
    if (active) {
        return 'inline-flex h-8 min-w-8 items-center justify-center rounded-md bg-gradient-to-b from-blue-500 to-blue-600 px-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15';
    }

    return 'inline-flex h-8 min-w-8 items-center justify-center rounded-md border border-border bg-background px-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted';
}
</script>

<template>
    <div
        v-if="totalPages > 1"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-muted/20 px-4 py-3 text-sm"
    >
        <span class="text-muted-foreground">
            Page {{ page }} of {{ totalPages }}
            · {{ total.toLocaleString() }} {{ label ?? 'items' }}
        </span>

        <nav class="inline-flex items-center gap-1 rounded-lg border border-border bg-card p-1 shadow-sm" aria-label="Pagination">
            <button type="button" :disabled="page <= 1" :class="navClass(page > 1)" aria-label="Previous page" @click="go(page - 1)">
                <ChevronLeft class="h-4 w-4" />
            </button>

            <template v-for="(item, index) in pages" :key="`${item}-${index}`">
                <span
                    v-if="item === 'ellipsis'"
                    class="inline-flex h-8 min-w-8 items-center justify-center px-1 text-sm text-muted-foreground"
                >
                    …
                </span>
                <button
                    v-else
                    type="button"
                    :class="pageClass(item === page)"
                    :aria-current="item === page ? 'page' : undefined"
                    @click="go(item)"
                >
                    {{ item }}
                </button>
            </template>

            <button type="button" :disabled="page >= totalPages" :class="navClass(page < totalPages)" aria-label="Next page" @click="go(page + 1)">
                <ChevronRight class="h-4 w-4" />
            </button>
        </nav>
    </div>
</template>
