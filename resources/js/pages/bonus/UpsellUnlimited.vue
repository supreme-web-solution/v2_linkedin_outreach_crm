<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Upsell Unlimited' }] },
});
</script>

<template>
    <Head title="Upsell Unlimited" />
    <div class="mx-auto max-w-2xl rounded-xl border border-border bg-card p-6 shadow-sm">
        <h1 class="mb-4 text-lg font-semibold">Upsell Unlimited</h1>
        <div class="space-y-3 text-sm text-muted-foreground">
            <p>Thanks for picking up LinkDominator Unlimited edition.</p>
            <p>Your account has been unlocked with unlimited features.</p>
            <p>Email marketing app for importing leads:</p>
            <p>
                <a href="https://www.dropbox.com/s/3zpnyd1rjtuxzh3/mailpoet.3.69.1.zip?dl=0" target="_blank" rel="noopener" class="text-primary underline">
                    Download Email Marketing Application
                </a>
            </p>
        </div>
    </div>
</template>
