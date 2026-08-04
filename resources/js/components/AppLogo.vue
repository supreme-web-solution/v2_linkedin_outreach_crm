<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const appName = computed(() => (page.props.name as string) ?? 'Socifusion');
</script>

<template>
    <div
        class="flex aspect-square size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg shadow-sm"
    >
        <img src="/images/brand/app-logo.png" alt="" class="h-full w-full object-cover" />
    </div>
    <div class="grid min-w-0 flex-1 text-left leading-tight">
        <span class="truncate text-sm font-semibold text-sidebar-foreground">{{
            appName
        }}</span>
        <span class="truncate text-xs text-muted-foreground">Multi-channel CRM</span>
    </div>
</template>
