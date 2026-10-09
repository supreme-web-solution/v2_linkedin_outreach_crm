<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink, Vault } from '@lucide/vue';
import BonusPageShell from '@/components/bonus/BonusPageShell.vue';

defineProps<{ signupUrl: string }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Affiliate Campaign Vault' }] },
});
</script>

<template>
    <Head title="Affiliate Campaign Vault" />

    <BonusPageShell
        title="Affiliate Campaign Vault"
        subtitle="Ready-to-run affiliate campaigns. Create your Affilimachine account to get started."
        badge="Unlocked"
        :icon="Vault"
    >
        <div class="space-y-5">
            <div class="rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                Sign up for Affilimachine using the link below, then log in to access your affiliate campaigns.
            </div>

            <div>
                <h2 class="text-sm font-semibold text-foreground">Get started with Affilimachine</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Register your account to unlock the campaigns included with your purchase.
                </p>
                <a
                    :href="signupUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700"
                >
                    <ExternalLink class="h-4 w-4" />
                    Sign up to Affilimachine
                </a>
            </div>
        </div>
    </BonusPageShell>
</template>
