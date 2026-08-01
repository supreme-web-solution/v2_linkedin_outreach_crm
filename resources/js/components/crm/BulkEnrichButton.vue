<script setup lang="ts">
import { Loader2, Sparkles } from '@lucide/vue';

defineProps<{
    disabled?: boolean;
    loading?: boolean;
    remaining?: number;
}>();

defineEmits<{
    click: [];
}>();
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
        <span v-if="remaining !== undefined && remaining > 0" class="font-normal opacity-90">
            ({{ Math.min(25, remaining).toLocaleString() }} next)
        </span>
        <span v-else class="font-normal opacity-80">(up to 25)</span>
    </button>
</template>
