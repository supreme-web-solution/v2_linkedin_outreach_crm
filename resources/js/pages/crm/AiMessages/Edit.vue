<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import Form from './Form.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Outreach Templates', href: '/ai-messages' },
            { title: 'Edit', href: '#' },
        ],
    },
});

defineProps<{
    aicontent: {
        id: number;
        title: string;
        ai_type: string;
        language: string | null;
        idea: string | null;
        write_style: string | null;
        connection_message_type: string | null;
        connection_message_location: string | null;
        connection_message_industry: string | null;
        connection_message_jobtitle: string | null;
        contents: string | null;
        word_counts: number;
    };
}>();
</script>

<template>
    <Head title="Edit AI Message" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex items-center gap-3">
            <Link href="/ai-messages" class="rounded-lg border border-border p-2 hover:bg-muted"><ArrowLeft class="h-4 w-4" /></Link>
            <LinkedInPageHeading inline :icon-size="24" title="Edit AI Message" show-badge />
        </div>
        <Form :aicontent="aicontent" />
    </div>
</template>
