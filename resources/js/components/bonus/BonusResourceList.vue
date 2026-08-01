<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';

defineProps<{
    items: Array<{ label: string; url: string }>;
    emptyText?: string;
}>();
</script>

<template>
    <div v-if="items.length" class="grid gap-2">
        <a
            v-for="item in items"
            :key="item.url"
            :href="item.url"
            target="_blank"
            rel="noopener noreferrer"
            class="group flex items-center gap-3 rounded-xl border border-border bg-background px-3.5 py-3 text-sm transition hover:border-blue-300 hover:bg-blue-50/50 hover:shadow-sm"
        >
            <span class="min-w-0 flex-1 font-medium text-foreground group-hover:text-blue-700">
                {{ item.label }}
            </span>
            <ExternalLink class="h-3.5 w-3.5 shrink-0 text-muted-foreground transition group-hover:text-blue-600" />
        </a>
    </div>
    <p v-else class="text-sm text-muted-foreground">{{ emptyText ?? 'No resources available yet.' }}</p>
</template>
