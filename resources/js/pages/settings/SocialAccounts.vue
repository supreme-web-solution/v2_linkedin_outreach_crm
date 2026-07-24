<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, Link2, Megaphone, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Social Accounts', href: '/settings/social-accounts' }],
    },
});

interface UnipileAccount {
    id: number;
    status: string;
    connection_method: string;
    email: string | null;
    unipile_account_id: string | null;
    connected_at: string | null;
    last_synced_at: string | null;
}

const props = defineProps<{
    unipileAccounts: UnipileAccount[];
    connected: boolean;
    connectionError: boolean;
}>();

function disconnectUnipile(id: number) {
    if (!confirm('Disconnect this LinkedIn account from Unipile?')) return;
    router.delete(`/settings/social-accounts/unipile/${id}`, { preserveScroll: true });
}

const activeUnipile = () => props.unipileAccounts.filter((a) => a.status === 'active');
</script>

<template>
    <Head title="Social Accounts" />

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Social Accounts"
            description="Connect LinkedIn via Unipile for messaging, search import, and email enrichment."
        />

        <div v-if="connected" class="flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle2 class="h-4 w-4 shrink-0" />
            LinkedIn connected successfully via Unipile.
        </div>
        <div v-if="connectionError" class="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle class="h-4 w-4 shrink-0" />
            Connection failed. Try the cookie method on the Integrations page or use the browser extension.
        </div>

        <section class="space-y-4">
            <div class="flex items-center gap-2">
                <Megaphone class="h-5 w-5 text-[#0077b5]" />
                <h2 class="text-base font-semibold">LinkedIn (Unipile)</h2>
            </div>
            <p class="text-sm text-muted-foreground">
                Powers campaigns, Call Manager, lead search, and profile email lookup.
                <a href="/integrations" class="text-primary hover:underline">Connect on Integrations →</a>
            </p>

            <div v-if="activeUnipile().length" class="grid gap-3 sm:grid-cols-2">
                <div v-for="account in activeUnipile()" :key="account.id" class="flex items-start justify-between rounded-xl border border-border bg-card p-4">
                    <div class="flex gap-3">
                        <div class="rounded-lg bg-[#0077b5]/10 p-2 text-[#0077b5]"><Link2 class="h-5 w-5" /></div>
                        <div>
                            <p class="font-medium">{{ account.email ?? 'LinkedIn account' }}</p>
                            <p class="text-xs capitalize text-muted-foreground">{{ account.connection_method }} · {{ account.last_synced_at ?? 'Connected' }}</p>
                            <p v-if="account.unipile_account_id" class="mt-1 font-mono text-[10px] text-muted-foreground">{{ account.unipile_account_id }}</p>
                        </div>
                    </div>
                    <button type="button" class="rounded border border-border p-1.5 text-muted-foreground hover:border-red-400 hover:text-red-500" @click="disconnectUnipile(account.id)">
                        <Trash2 class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <div v-else class="rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
                No LinkedIn account connected yet.
                <a href="/integrations" class="ml-1 text-primary hover:underline">Connect on Integrations →</a>
            </div>
        </section>
    </div>
</template>
