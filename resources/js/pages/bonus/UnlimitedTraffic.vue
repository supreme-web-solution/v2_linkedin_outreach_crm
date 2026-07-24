<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Unlimited Traffic' }] },
});
</script>

<template>
    <Head title="Unlimited Traffic" />
    <div class="mx-auto max-w-2xl rounded-xl border border-border bg-card p-6 shadow-sm">
        <h1 class="mb-4 text-lg font-semibold">Unlimited Traffic</h1>
        <div class="space-y-3 text-sm text-muted-foreground">
            <p>Get unlimited buyer traffic for your offers.</p>
            <p>
                Watch this video and send your Facebook pixel:
                <a href="https://m.youtube.com/watch?v=jSBKCbIS_sg" target="_blank" rel="noopener" class="text-primary underline">
                    https://m.youtube.com/watch?v=jSBKCbIS_sg
                </a>
            </p>
        </div>
    </div>
</template>
