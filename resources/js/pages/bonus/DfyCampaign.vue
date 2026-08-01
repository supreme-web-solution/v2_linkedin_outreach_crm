<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Megaphone } from '@lucide/vue';
import BonusPageShell from '@/components/bonus/BonusPageShell.vue';
import BonusResourceList from '@/components/bonus/BonusResourceList.vue';

defineProps<{ links: Array<{ label: string; url: string }> }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'DFY Campaign' }] },
});
</script>

<template>
    <Head title="DFY Campaign" />

    <BonusPageShell
        title="DFY Profit Campaigns"
        subtitle="Copy-and-paste affiliate campaigns — open a doc, follow the steps, and launch."
        badge="Bonus unlocked"
        :icon="Megaphone"
    >
        <div class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-foreground">Campaign playbooks</h2>
                <span class="text-xs text-muted-foreground">{{ links.length }} campaigns</span>
            </div>
            <BonusResourceList :items="links" empty-text="No campaign links configured yet." />
        </div>
    </BonusPageShell>
</template>
