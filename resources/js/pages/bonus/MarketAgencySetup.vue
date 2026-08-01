<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Building2, LifeBuoy } from '@lucide/vue';
import BonusPageShell from '@/components/bonus/BonusPageShell.vue';
import BonusResourceList from '@/components/bonus/BonusResourceList.vue';

defineProps<{ resources: Array<{ label: string; url: string }> }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'DFY Agency Setup' }] },
});
</script>

<template>
    <Head title="DFY Agency Setup" />

    <BonusPageShell
        title="DFY Agency Setup"
        subtitle="Done-for-you LinkedIn marketing agency assets — scripts, proposals, and brand kit files ready to use."
        badge="Bonus unlocked"
        :icon="Building2"
    >
        <div class="space-y-5">
            <div class="rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                Share your preferred website URL and cPanel login through our
                <a
                    href="https://supremewebcustomercare.freshdesk.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-primary underline-offset-2 hover:underline"
                >
                    Support Desk
                </a>
                so we can finish setup on your side.
            </div>

            <div>
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-foreground">Marketing kit resources</h2>
                    <span class="text-xs text-muted-foreground">{{ resources.length }} files</span>
                </div>
                <BonusResourceList :items="resources" />
            </div>

            <a
                href="https://supremewebcustomercare.freshdesk.com"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-2 rounded-xl border border-border bg-background px-4 py-2.5 text-sm font-medium text-foreground shadow-sm hover:bg-muted"
            >
                <LifeBuoy class="h-4 w-4 text-blue-600" />
                Open support desk
            </a>
        </div>
    </BonusPageShell>
</template>
