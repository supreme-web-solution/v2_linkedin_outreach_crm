<script setup lang="ts">
import { CheckSquare, Square } from '@lucide/vue';
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    checked?: boolean;
    size?: 'sm' | 'md';
}>(), {
    checked: false,
    size: 'md',
});

const sizeClass = computed(() => (props.size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4'));
</script>

<template>
    <span class="inline-flex shrink-0 items-center justify-center" aria-hidden="true">
        <CheckSquare v-if="checked" :class="[sizeClass, 'text-primary']" />
        <Square v-else :class="[sizeClass, 'text-primary/45']" />
    </span>
</template>
