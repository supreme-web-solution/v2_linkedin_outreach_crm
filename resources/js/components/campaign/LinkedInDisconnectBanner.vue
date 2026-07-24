<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { AlertCircle, ExternalLink } from '@lucide/vue';
import { computed } from 'vue';

type LinkedInConnection = {
    connected: boolean;
    status: string;
    live_status: string;
    message: string;
    reconnect_url: string;
};

const props = defineProps<{
    campaignPauseMessage?: string | null;
}>();

const page = usePage();

const connection = computed(() => page.props.linkedinConnection as LinkedInConnection | null | undefined);

const show = computed(() => connection.value && !connection.value.connected);

const title = computed(() => {
    if (connection.value?.status === 'disconnected' || connection.value?.live_status === 'disconnected') {
        return 'LinkedIn disconnected';
    }

    return 'LinkedIn not connected';
});

const message = computed(() => {
    if (props.campaignPauseMessage) {
        return props.campaignPauseMessage;
    }

    return connection.value?.message ?? 'Connect LinkedIn to run campaigns.';
});

const flashError = computed(() => (page.props.flash as { error?: string } | undefined)?.error);
</script>

<template>
    <div v-if="show || flashError" class="flex flex-col gap-3">
        <div
            v-if="flashError"
            class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-200"
            role="alert"
        >
            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
            <p>{{ flashError }}</p>
        </div>

        <div
            v-if="show"
            class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-100 sm:flex-row sm:items-center sm:justify-between"
            role="alert"
        >
            <div class="flex items-start gap-3">
                <AlertCircle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                <div>
                    <p class="font-semibold">{{ title }}</p>
                    <p class="mt-1 text-amber-900/90 dark:text-amber-100/90">{{ message }}</p>
                    <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-200/80">
                        Campaigns are paused until you reconnect. Open Integrations to sign in again.
                    </p>
                </div>
            </div>
            <Link
                :href="connection?.reconnect_url ?? '/integrations'"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700"
            >
                Reconnect LinkedIn
                <ExternalLink class="h-4 w-4" />
            </Link>
        </div>
    </div>
</template>
