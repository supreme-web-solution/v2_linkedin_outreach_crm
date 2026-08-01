<script setup lang="ts">
import { CheckCircle2, Loader2, Mail, Phone, Sparkles } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    type: 'email' | 'phone';
    value: string | null;
    fetchStatus: string | null;
    fetchAttempted?: boolean;
    fetching?: boolean;
    canFetch?: boolean;
    fetchDisabled?: boolean;
}>();

const emit = defineEmits<{
    fetch: [];
}>();

const isSearching = computed(() =>
    props.fetching || ['pending', 'processing'].includes(props.fetchStatus ?? ''),
);

const searchingLabel = computed(() => {
    if (props.fetching || props.fetchStatus === 'processing') {
        return 'Searching';
    }
    if (props.fetchStatus === 'pending') {
        return 'Queued';
    }
    return 'Searching';
});

const isNotFound = computed(() => {
    if (props.value) {
        return false;
    }

    if (props.type === 'email') {
        return props.fetchStatus === 'completed' || props.fetchAttempted === true;
    }

    return props.fetchStatus === 'completed' && props.fetchAttempted === true;
});

const showFetch = computed(() =>
    props.type === 'email' && props.canFetch && !props.value && !isSearching.value && !isNotFound.value,
);
</script>

<template>
    <div class="min-w-[140px]">
        <div v-if="value" class="inline-flex max-w-full items-center gap-2">
            <component :is="type === 'email' ? Mail : Phone" class="h-4 w-4 shrink-0 text-muted-foreground" />
            <span class="truncate text-sm text-foreground" :title="value">{{ value }}</span>
            <CheckCircle2 class="h-4 w-4 shrink-0 text-emerald-600" />
        </div>
        <div v-else-if="isSearching" class="inline-flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 class="h-4 w-4 animate-spin" />
            {{ searchingLabel }}
        </div>
        <span v-else-if="isNotFound" class="text-sm text-muted-foreground">Not found</span>
        <button
            v-else-if="showFetch"
            type="button"
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-gradient-to-b from-blue-500 to-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 transition-colors hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="fetchDisabled || fetching"
            @click="emit('fetch')"
        >
            <Loader2 v-if="fetching" class="h-3.5 w-3.5 animate-spin" />
            <Sparkles v-else class="h-3.5 w-3.5" />
            Enrich
        </button>
        <span v-else class="text-sm text-muted-foreground/50">—</span>
    </div>
</template>
