<script setup lang="ts">
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

function go(next: number) {
    emit('update:page', Math.min(Math.max(1, next), props.totalPages));
}
</script>

<template>
    <div
        v-if="totalPages > 1"
        class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground"
    >
        <span>
            Page {{ page }} of {{ totalPages }}
            · {{ total.toLocaleString() }} {{ label ?? 'items' }}
        </span>
        <div class="flex items-center gap-1">
            <button
                type="button"
                :disabled="page <= 1"
                class="rounded-lg border border-border px-3 py-1.5 hover:bg-muted disabled:opacity-40"
                @click="go(page - 1)"
            >
                Prev
            </button>
            <button
                type="button"
                :disabled="page >= totalPages"
                class="rounded-lg border border-border px-3 py-1.5 hover:bg-muted disabled:opacity-40"
                @click="go(page + 1)"
            >
                Next
            </button>
        </div>
    </div>
</template>
