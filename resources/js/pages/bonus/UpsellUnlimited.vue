<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, Sparkles } from '@lucide/vue';
import BonusPageShell from '@/components/bonus/BonusPageShell.vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Upsell Unlimited' }] },
});
</script>

<template>
    <Head title="Upsell Unlimited" />

    <BonusPageShell
        title="Upsell Unlimited"
        subtitle="Your unlimited edition is unlocked. Download the email marketing add-on below to import and nurture leads."
        badge="Bonus unlocked"
        :icon="Sparkles"
    >
        <div class="space-y-5">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 py-3 text-sm text-emerald-900">
                Account features for LinkDominator Unlimited are active on this workspace.
            </div>

            <div>
                <h2 class="text-sm font-semibold text-foreground">Email marketing app</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Import leads and run email follow-ups with the included MailPoet package.
                </p>
                <a
                    href="https://www.dropbox.com/s/3zpnyd1rjtuxzh3/mailpoet.3.69.1.zip?dl=0"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700"
                >
                    <Download class="h-4 w-4" />
                    Download email marketing app
                </a>
            </div>
        </div>
    </BonusPageShell>
</template>
