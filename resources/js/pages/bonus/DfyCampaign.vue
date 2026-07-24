<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ links: Array<{ label: string; url: string }> }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'DFY Campaign' }] },
});
</script>

<template>
    <Head title="DFY Campaign" />
    <div class="mx-auto max-w-2xl rounded-xl border border-border bg-card p-6 shadow-sm">
        <h1 class="mb-4 text-lg font-semibold">DFY Profit Generating Campaigns</h1>
        <div class="space-y-3 text-sm text-muted-foreground">
            <p>Copy-and-paste affiliate campaigns:</p>
            <ul class="list-inside list-disc space-y-1">
                <li v-for="item in links" :key="item.url">
                    <a :href="item.url" target="_blank" rel="noopener" class="text-primary underline">{{ item.label }}</a>
                </li>
            </ul>
        </div>
    </div>
</template>
