<script setup lang="ts">
import { CheckCircle2, Loader2, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import type { LeadContacts } from '@/components/crm/LeadContactTags.vue';

const props = defineProps<{
    contacts: LeadContacts;
    fetching?: boolean;
    canEnrich?: boolean;
}>();

const emit = defineEmits<{
    fetch: [];
}>();

const needsEnrichment = computed(() => {
    if (props.canEnrich === false) {
        return false;
    }

    const { contacts } = props;

    if (contacts.phone && !contacts.whatsapp_provider_id) {
        return true;
    }

    const handles: Array<[keyof LeadContacts, keyof LeadContacts]> = [
        ['instagram_handle', 'instagram_provider_id'],
        ['telegram_handle', 'telegram_provider_id'],
        ['twitter_handle', 'twitter_provider_id'],
    ];

    return handles.some(([handleKey, providerKey]) => {
        const handle = contacts[handleKey];
        const provider = contacts[providerKey];

        return Boolean(handle) && !provider;
    });
});

const hasEnrichedData = computed(() => {
    const { contacts } = props;

    if (contacts.whatsapp_provider_id) {
        return true;
    }

    return Boolean(
        contacts.instagram_provider_id
        || contacts.telegram_provider_id
        || contacts.twitter_provider_id,
    );
});

const pendingHint = computed(() => {
    const { contacts } = props;
    const parts: string[] = [];
    if (contacts.instagram_handle && !contacts.instagram_provider_id) parts.push('Instagram');
    if (contacts.telegram_handle && !contacts.telegram_provider_id) parts.push('Telegram');
    if (contacts.twitter_handle && !contacts.twitter_provider_id) parts.push('X');
    if (contacts.phone && !contacts.whatsapp_provider_id) parts.push('WhatsApp');
    return parts.join(', ');
});
</script>

<template>
    <div class="min-w-[100px]">
        <div v-if="fetching" class="inline-flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 class="h-4 w-4 animate-spin" />
            Enriching
        </div>
        <div v-else-if="needsEnrichment">
            <button
                type="button"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-gradient-to-b from-blue-500 to-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 transition-colors hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                :title="pendingHint ? `Still need: ${pendingHint}` : 'Enrich contact channels'"
                @click="emit('fetch')"
            >
                <Sparkles class="h-3.5 w-3.5" />
                {{ hasEnrichedData ? 'Retry' : 'Enrich' }}
            </button>
        </div>
        <div v-else-if="hasEnrichedData" class="inline-flex items-center gap-2 text-sm text-muted-foreground">
            <CheckCircle2 class="h-4 w-4 shrink-0 text-emerald-600" />
            Done
        </div>
        <span v-else class="text-sm text-muted-foreground/50">—</span>
    </div>
</template>
