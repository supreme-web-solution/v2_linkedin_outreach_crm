<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ resources: Array<{ label: string; url: string }> }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'DFY Agency Setup' }] },
});
</script>

<template>
    <Head title="DFY Agency Setup" />
    <div class="mx-auto max-w-2xl rounded-xl border border-border bg-card p-6 shadow-sm">
        <h1 class="mb-4 text-lg font-semibold">DFY LinkedIn Marketing Agency Setup</h1>
        <div class="space-y-3 text-sm text-muted-foreground">
            <p>Thanks for grabbing the DFY Agency setup.</p>
            <p>
                Provide your preferred website URL and cPanel login via our
                <a href="https://supremewebcustomercare.freshdesk.com" target="_blank" rel="noopener" class="text-primary underline">Support Desk</a>.
            </p>
            <p class="font-medium text-foreground">Marketing kit resources</p>
            <ul class="list-inside list-disc space-y-1">
                <li v-for="item in resources" :key="item.url">
                    <a :href="item.url" target="_blank" rel="noopener" class="text-primary underline">{{ item.label }}</a>
                </li>
            </ul>
        </div>
    </div>
</template>
