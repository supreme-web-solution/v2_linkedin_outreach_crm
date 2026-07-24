<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const isImpersonating = computed(() => Boolean(page.props.isImpersonating));
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="flex min-h-0 flex-1 flex-col overflow-hidden">
            <div v-if="isImpersonating" class="shrink-0 border-b border-yellow-500/40 bg-yellow-500/10 px-4 py-2 text-center text-sm text-yellow-800 dark:text-yellow-300">
                You are impersonating a user.
                <Link href="/admin/stop-impersonating" method="post" as="button" class="ml-2 font-medium underline">Return to admin</Link>
            </div>
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
                <slot />
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
