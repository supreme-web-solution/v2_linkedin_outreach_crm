<script setup lang="ts">
import { Search } from '@lucide/vue';

withDefaults(defineProps<{
    placeholder?: string;
    modelValue: string;
    hideButton?: boolean;
}>(), {
    hideButton: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
    search: [];
}>();

function onInput(e: Event) {
    emit('update:modelValue', (e.target as HTMLInputElement).value);
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter') emit('search');
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex min-w-[200px] flex-1 max-w-md items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
            <Search class="h-4 w-4 shrink-0 text-muted-foreground" />
            <input
                :value="modelValue"
                type="search"
                :placeholder="placeholder ?? 'Search…'"
                class="w-full bg-transparent text-sm outline-none"
                @input="onInput"
                @keydown="onKeydown"
            />
        </div>
        <slot name="filters" />
        <button
            v-if="!hideButton"
            type="button"
            class="rounded-lg border border-border bg-card px-3 py-2 text-sm hover:bg-muted"
            @click="emit('search')"
        >
            Search
        </button>
    </div>
</template>
