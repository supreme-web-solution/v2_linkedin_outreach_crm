<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

defineProps<{
    icon: Component;
    class?: string;
}>();
</script>

<template>
    <span :class="cn('nav-sketch-icon inline-flex items-center justify-center', $props.class)">
        <component :is="icon" />
    </span>
</template>
