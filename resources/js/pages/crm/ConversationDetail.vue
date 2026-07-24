<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertCircle, Calendar, Send } from '@lucide/vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import { computed } from 'vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Conversations', href: '/conversations' }, { title: 'Thread' }] },
});

const props = defineProps<{
    conversation: {
        id: number;
        provider: string;
        provider_chat_id: string | null;
        prospect_name: string | null;
        prospect_headline: string | null;
        status: string;
        last_message_at: string | null;
        has_chat_link: boolean;
    };
    messages: Array<{
        id: number;
        direction: string;
        body: string;
        sent_at: string | null;
        received_at: string | null;
        created_at: string;
        status: string | null;
    }>;
    hasUnipile: boolean;
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const form = useForm({ body: '' });

function send() {
    form.post(`/conversations/${props.conversation.id}/send`, {
        preserveScroll: true,
        onSuccess: () => form.reset('body'),
    });
}

function trackCall() {
    router.post(`/conversations/${props.conversation.id}/track-call`);
}
</script>

<template>
    <Head title="Conversation" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-center gap-3">
            <Link href="/conversations" class="text-sm text-muted-foreground hover:text-foreground">← Conversations</Link>
            <span class="text-muted-foreground">/</span>
            <span class="text-sm font-medium">{{ conversation.prospect_name || `Thread #${conversation.id}` }}</span>
            <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary capitalize">{{ conversation.provider }}</span>
        </div>

        <p v-if="flashSuccess" class="rounded-lg bg-green-100 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ flashSuccess }}</p>
        <p v-if="flashError" class="rounded-lg bg-red-100 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ flashError }}</p>

        <div v-if="!hasUnipile" class="flex items-center gap-2 rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-4 py-3 text-sm text-yellow-800 dark:text-yellow-300">
            <AlertCircle class="h-4 w-4 shrink-0" />
            Connect LinkedIn via <Link href="/integrations" class="underline">Integrations</Link> to send replies.
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <AppToolbarButton variant="violet" @click="trackCall">
                <Calendar class="h-4 w-4" /> Track in Call Manager
            </AppToolbarButton>
        </div>

        <div class="rounded-xl border border-border bg-card p-3 text-xs text-muted-foreground">
            Chat ID: <span class="font-mono">{{ conversation.provider_chat_id || '—' }}</span>
        </div>

        <div class="flex min-h-[320px] flex-col gap-2 rounded-xl border border-border bg-card p-4">
            <div v-if="messages.length === 0" class="flex flex-1 items-center justify-center text-sm text-muted-foreground">
                No messages yet.
            </div>
            <div v-for="msg in messages" :key="msg.id" class="flex" :class="msg.direction === 'outbound' ? 'justify-end' : 'justify-start'">
                <div
                    class="max-w-[80%] rounded-2xl px-4 py-2.5 text-sm shadow-sm"
                    :class="msg.direction === 'outbound' ? 'rounded-br-sm bg-primary text-primary-foreground' : 'rounded-bl-sm bg-muted'"
                >
                    <p class="whitespace-pre-wrap">{{ msg.body }}</p>
                    <p class="mt-1 text-[10px] opacity-70">
                        {{ (msg.sent_at || msg.received_at || msg.created_at)?.slice(0, 16) }}
                        <span v-if="msg.status === 'queued'"> · sending…</span>
                    </p>
                </div>
            </div>
        </div>

        <form class="flex gap-2" @submit.prevent="send">
            <textarea
                v-model="form.body"
                rows="2"
                required
                placeholder="Type a reply…"
                class="min-h-[44px] flex-1 resize-y rounded-xl border border-border bg-background px-3 py-2 text-sm"
                :disabled="!hasUnipile || form.processing"
            />
            <button
                type="submit"
                class="inline-flex items-center gap-2 self-end rounded-xl bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
                :disabled="!hasUnipile || form.processing || !form.body.trim()"
            >
                <Send class="h-4 w-4" /> Send
            </button>
        </form>
    </div>
</template>
