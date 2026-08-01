<script setup lang="ts">
import { Loader2, Sparkles } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    disabled?: boolean;
    loading?: boolean;
    /** How many leads still need enrich in this list. */
    remaining?: number;
    /** How many this click will actually queue (after daily/concurrency caps). */
    queueNow?: number;
    batchSize?: number;
}>();

defineEmits<{
    click: [];
}>();

const batch = computed(() => Math.max(1, props.batchSize ?? 25));

const labelCount = computed(() => {
    if (props.queueNow !== undefined) {
        return Math.max(0, props.queueNow);
    }
    if (props.remaining !== undefined && props.remaining > 0) {
        return Math.min(batch.value, props.remaining);
    }
    return batch.value;
});
</script>

<template>
    <button
        type="button"
        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-gradient-to-b from-blue-500 to-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 transition-colors hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="disabled || loading"
        @click="$emit('click')"
    >
        <Loader2 v-if="loading" class="h-3.5 w-3.5 animate-spin" />
        <Sparkles v-else class="h-3.5 w-3.5" />
        Enrich
        <span v-if="labelCount > 0" class="font-normal opacity-90">
            ({{ labelCount.toLocaleString() }} next)
        </span>
    </button>
</template>
