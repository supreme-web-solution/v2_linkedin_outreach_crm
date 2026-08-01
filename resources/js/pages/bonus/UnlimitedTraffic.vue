<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { PlayCircle, Radar } from '@lucide/vue';
import BonusPageShell from '@/components/bonus/BonusPageShell.vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Unlimited Traffic' }] },
});
</script>

<template>
    <Head title="Unlimited Traffic" />

    <BonusPageShell
        title="Unlimited Traffic"
        subtitle="Get unlimited buyer traffic for your offers. Watch the walkthrough, then send your Facebook pixel as instructed."
        badge="Bonus unlocked"
        :icon="Radar"
    >
        <div class="space-y-5">
            <div class="rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                Follow the video step by step, then submit your Facebook pixel so traffic can be routed to your offers.
            </div>

            <div>
                <h2 class="text-sm font-semibold text-foreground">Setup walkthrough</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Short video covering how Unlimited Traffic works and what to send next.
                </p>
                <a
                    href="https://m.youtube.com/watch?v=jSBKCbIS_sg"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700"
                >
                    <PlayCircle class="h-4 w-4" />
                    Watch setup video
                </a>
            </div>
        </div>
    </BonusPageShell>
</template>
