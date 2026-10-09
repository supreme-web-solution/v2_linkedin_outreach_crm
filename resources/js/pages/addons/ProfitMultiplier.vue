<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { TrendingUp } from '@lucide/vue';
import BonusPageShell from '@/components/bonus/BonusPageShell.vue';
import BonusResourceList from '@/components/bonus/BonusResourceList.vue';

defineProps<{ links: Array<{ label: string; url: string }> }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Profit Multiplier' }] },
});
</script>

<template>
    <Head title="Profit Multiplier" />

    <BonusPageShell
        title="Profit Multiplier"
        subtitle="Open the resources below to multiply the profits from your campaigns."
        badge="Unlocked"
        :icon="TrendingUp"
    >
        <div class="space-y-4">
            <h2 class="text-sm font-semibold text-foreground">Your resources</h2>
            <BonusResourceList :items="links" empty-text="Resources will be available here shortly." />
        </div>
    </BonusPageShell>
</template>
