<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Coaching Program' }] },
});
</script>

<template>
    <Head title="Coaching Program" />
    <div class="mx-auto max-w-2xl rounded-xl border border-border bg-card p-6 shadow-sm">
        <h1 class="mb-4 text-lg font-semibold">8 Week Coaching Program</h1>
        <p class="text-sm text-muted-foreground">Thanks for picking up this upsell. Your coaching program access is active.</p>
    </div>
</template>
