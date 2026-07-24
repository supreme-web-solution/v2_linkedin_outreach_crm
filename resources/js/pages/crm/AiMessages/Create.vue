<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import Form from './Form.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Outreach Templates', href: '/ai-messages' },
            { title: 'New', href: '/ai-messages/new' },
        ],
    },
});
</script>

<template>
    <Head title="New AI Message" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex items-center gap-3">
            <Link href="/ai-messages" class="rounded-lg border border-border p-2 hover:bg-muted"><ArrowLeft class="h-4 w-4" /></Link>
            <h1 class="text-xl font-semibold text-foreground">New AI Message</h1>
        </div>
        <Form />
    </div>
</template>
