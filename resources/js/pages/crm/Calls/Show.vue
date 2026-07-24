<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Bell, Calendar, Loader2, Send, Sparkles, X } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Call Manager', href: '/calls' },
            { title: 'Detail' },
        ],
    },
});

const props = defineProps<{
    call: {
        id: number;
        prospect_name: string | null;
        prospect_headline: string | null;
        connection_id: string | null;
        conversation_id: number | null;
        status: string;
        pipeline_stage: string;
        pending_message: string | null;
        scheduled_send_at: string | null;
        scheduled_call_at: string | null;
        has_conversation: boolean;
        ready_to_send: boolean;
        reminders: Array<{ id: number; message: string; send_at: string; status: string }>;
    };
    messages: Array<{ id: number; direction: string; body: string; at: string | null }>;
    latestAnalysis: Record<string, unknown> | null;
    settings: { calendar_url: string };
    hasUnipile: boolean;
    aiConfigured: boolean;
    conversations: Array<{ id: number; provider_chat_id: string; last_message_at: string | null }>;
}>();

const linkForm = useForm({
    conversation_id: props.call.conversation_id ? String(props.call.conversation_id) : '',
});

function linkConversation() {
    if (!linkForm.conversation_id) return;
    linkForm.post(`/calls/${props.call.id}/link-conversation`, { preserveScroll: true });
}

const sending = ref(false);
const analyzing = ref(false);
const messageScroll = ref<HTMLElement | null>(null);

const editForm = useForm({
    pending_message: props.call.pending_message ?? '',
    scheduled_call_at: props.call.scheduled_call_at ? props.call.scheduled_call_at.slice(0, 16) : '',
    prospect_name: props.call.prospect_name ?? '',
});

const canSend = computed(
    () => Boolean(editForm.pending_message?.trim()) && props.call.has_conversation && props.hasUnipile && !sending.value,
);

const aiSource = computed(() => (props.latestAnalysis?.source as string | undefined) ?? null);

const intentLabel = computed(() => {
    const intent = props.latestAnalysis?.current_intent as string | undefined;
    if (!intent || intent === 'unknown') return null;
    return intent.replace(/_/g, ' ');
});

watch(
    () => props.call.pending_message,
    (value) => {
        editForm.pending_message = value ?? '';
    },
);

watch(
    () => props.call.scheduled_call_at,
    (value) => {
        editForm.scheduled_call_at = value ? value.slice(0, 16) : '';
    },
);

function scrollToBottom() {
    nextTick(() => {
        const el = messageScroll.value;
        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    });
}

onMounted(scrollToBottom);
watch(() => props.messages.length, scrollToBottom);

function formatTime(at: string | null) {
    if (!at) return '';
    try {
        return new Date(at).toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return at.slice(0, 16);
    }
}

function sendNow() {
    if (!canSend.value) return;
    sending.value = true;
    router.post(`/calls/${props.call.id}/send`, { pending_message: editForm.pending_message }, {
        preserveScroll: true,
        onFinish: () => { sending.value = false; },
    });
}

function runAnalyze() {
    analyzing.value = true;
    router.post(`/calls/${props.call.id}/analyze`, {}, {
        preserveScroll: true,
        onFinish: () => { analyzing.value = false; },
    });
}

function dismissSuggestion() {
    editForm.pending_message = '';
    router.post(`/calls/${props.call.id}/dismiss`, {}, { preserveScroll: true });
}

function saveEdits() {
    editForm.put(`/calls/${props.call.id}`, { preserveScroll: true });
}

function setStatus(status: string) {
    router.put(`/calls/${props.call.id}`, { status }, { preserveScroll: true });
}

function onComposerKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendNow();
    }
}

const stageLabel: Record<string, string> = {
    engaged: 'Engaged',
    scheduling: 'Scheduling',
    booked: 'Booked',
    completed: 'Completed',
    lost: 'Lost',
};
</script>

<template>
    <Head :title="call.prospect_name ?? 'Call detail'" />

    <div class="flex h-[calc(100dvh-7rem)] min-h-[32rem] flex-col gap-3 overflow-hidden p-4">
        <div class="flex shrink-0 flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 flex-col gap-1">
                <Link href="/calls" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="h-4 w-4" /> Back to pipeline
                </Link>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="truncate text-xl font-semibold">
                        {{ call.prospect_name || call.connection_id || `Call #${call.id}` }}
                    </h1>
                    <span class="rounded-full border px-2 py-0.5 text-xs font-medium capitalize">
                        {{ stageLabel[call.pipeline_stage] ?? call.status }}
                    </span>
                </div>
                <p v-if="call.prospect_headline" class="truncate text-sm text-muted-foreground">{{ call.prospect_headline }}</p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <button type="button" class="rounded-lg border border-border px-3 py-1.5 text-sm hover:bg-muted/50" @click="setStatus('completed')">
                    Mark completed
                </button>
                <button type="button" class="rounded-lg border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" @click="setStatus('lost')">
                    Mark lost
                </button>
            </div>
        </div>

        <div class="grid min-h-0 flex-1 gap-4 lg:grid-cols-5">
            <!-- Chat column -->
            <div class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm lg:col-span-3">
                <div class="shrink-0 border-b border-border px-4 py-2.5">
                    <h2 class="text-sm font-semibold">LinkedIn conversation</h2>
                    <p v-if="!call.has_conversation" class="mt-0.5 text-xs text-yellow-600">
                        Link a conversation to send and receive messages.
                    </p>
                </div>

                <div v-if="!call.has_conversation" class="shrink-0 border-b border-border px-4 py-3">
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="linkConversation">
                        <label class="grid min-w-[200px] flex-1 gap-1 text-sm">
                            <span class="font-medium">Link conversation</span>
                            <select v-model="linkForm.conversation_id" class="rounded-lg border border-border bg-background px-3 py-2 text-sm">
                                <option value="">Select a thread…</option>
                                <option v-for="c in conversations" :key="c.id" :value="String(c.id)">
                                    Chat {{ c.provider_chat_id?.slice(0, 20) }}…
                                </option>
                            </select>
                        </label>
                        <button
                            type="submit"
                            class="rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-3 py-2 text-sm font-medium text-primary-foreground"
                            :disabled="!linkForm.conversation_id || linkForm.processing"
                        >
                            Link
                        </button>
                    </form>
                </div>

                <!-- Scrollable messages -->
                <div ref="messageScroll" class="min-h-0 flex-1 overflow-y-auto bg-muted/20 px-4 py-3">
                    <div v-if="!messages.length" class="flex h-full min-h-[12rem] items-center justify-center text-sm text-muted-foreground">
                        No messages yet — launch outreach or wait for a reply.
                    </div>
                    <div v-else class="flex flex-col gap-2">
                        <div
                            v-for="m in messages"
                            :key="m.id"
                            class="flex"
                            :class="m.direction === 'inbound' ? 'justify-start' : 'justify-end'"
                        >
                            <div
                                class="max-w-[min(85%,28rem)] rounded-2xl px-3 py-2 text-sm shadow-sm"
                                :class="m.direction === 'inbound'
                                    ? 'rounded-bl-md bg-card text-foreground ring-1 ring-border'
                                    : 'rounded-br-md bg-gradient-to-b from-blue-500 to-blue-600 text-primary-foreground'"
                            >
                                <p class="whitespace-pre-wrap break-words">{{ m.body }}</p>
                                <p
                                    class="mt-1 text-[10px]"
                                    :class="m.direction === 'inbound' ? 'text-muted-foreground' : 'text-primary-foreground/70'"
                                >
                                    {{ formatTime(m.at) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WhatsApp-style composer -->
                <div class="shrink-0 border-t border-border bg-card p-3">
                    <div
                        v-if="latestAnalysis?.next_action || intentLabel"
                        class="mb-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                    >
                        <span v-if="latestAnalysis?.next_action" class="rounded-md bg-violet-100 px-2 py-0.5 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200">
                            {{ latestAnalysis.next_action }}
                        </span>
                        <span v-if="intentLabel">Intent: {{ intentLabel }}</span>
                        <span v-if="aiSource === 'heuristic' && aiConfigured" class="text-amber-600 dark:text-amber-400">
                            OpenAI unavailable — using built-in rules
                        </span>
                        <span v-else-if="aiSource === 'heuristic'">Built-in suggestion</span>
                        <button
                            v-if="editForm.pending_message"
                            type="button"
                            class="ml-auto text-muted-foreground hover:text-foreground"
                            @click="dismissSuggestion"
                        >
                            <X class="inline h-3 w-3" /> Clear
                        </button>
                    </div>

                    <div class="flex items-end gap-2">
                        <button
                            type="button"
                            title="Generate AI suggestion"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-violet-200 bg-violet-50 text-violet-700 hover:bg-violet-100 disabled:opacity-50 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-300"
                            :disabled="analyzing || !call.has_conversation"
                            @click="runAnalyze"
                        >
                            <Loader2 v-if="analyzing" class="h-4 w-4 animate-spin" />
                            <Sparkles v-else class="h-4 w-4" />
                        </button>

                        <textarea
                            v-model="editForm.pending_message"
                            rows="1"
                            class="max-h-32 min-h-[2.5rem] flex-1 resize-none rounded-2xl border border-border bg-background px-4 py-2.5 text-sm leading-relaxed focus:outline-none focus:ring-2 focus:ring-primary/30"
                            placeholder="Type a message or tap ✨ for an AI suggestion…"
                            :disabled="!call.has_conversation"
                            @keydown="onComposerKeydown"
                        />

                        <button
                            type="button"
                            title="Send via Unipile"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-b from-blue-500 to-blue-600 text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-40"
                            :disabled="!canSend"
                            @click="sendNow"
                        >
                            <Loader2 v-if="sending" class="h-4 w-4 animate-spin" />
                            <Send v-else class="h-4 w-4" />
                        </button>
                    </div>

                    <p v-if="!hasUnipile" class="mt-2 text-xs text-yellow-600">
                        Connect LinkedIn under Integrations to send messages.
                    </p>
                    <p v-else-if="!call.has_conversation" class="mt-2 text-xs text-muted-foreground">
                        Link a conversation above before sending.
                    </p>
                    <p v-else class="mt-1 text-[10px] text-muted-foreground">
                        Enter to send · Shift+Enter for new line
                    </p>
                </div>
            </div>

            <!-- Sidebar: booking + reminders -->
            <div class="flex min-h-0 flex-col gap-4 overflow-hidden lg:col-span-2">
                <div class="shrink-0 rounded-xl border border-border bg-card shadow-sm">
                    <div class="flex items-center gap-2 border-b border-border px-4 py-3">
                        <Calendar class="h-4 w-4" />
                        <h2 class="text-sm font-semibold">Book call</h2>
                    </div>
                    <form class="space-y-3 p-4" @submit.prevent="saveEdits">
                        <label class="grid gap-1 text-sm">
                            <span class="font-medium">Prospect name</span>
                            <input v-model="editForm.prospect_name" type="text" class="rounded-lg border border-border bg-background px-3 py-2 text-sm" />
                        </label>
                        <label class="grid gap-1 text-sm">
                            <span class="font-medium">Call date & time</span>
                            <input v-model="editForm.scheduled_call_at" type="datetime-local" class="rounded-lg border border-border bg-background px-3 py-2 text-sm" />
                        </label>
                        <p v-if="settings.calendar_url" class="text-xs text-muted-foreground">
                            Calendar:
                            <a :href="settings.calendar_url" target="_blank" class="text-primary underline">{{ settings.calendar_url }}</a>
                        </p>
                        <button
                            type="submit"
                            class="w-full rounded-lg border border-border px-3 py-2 text-sm font-medium hover:bg-muted/50"
                            :disabled="editForm.processing"
                        >
                            Save & schedule reminders
                        </button>
                    </form>
                </div>

                <div v-if="call.reminders.length" class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <div class="flex shrink-0 items-center justify-between border-b border-border px-4 py-3">
                        <div class="flex items-center gap-2">
                            <Bell class="h-4 w-4" />
                            <h2 class="text-sm font-semibold">Reminders</h2>
                        </div>
                        <span class="text-xs text-muted-foreground">{{ call.reminders.length }}</span>
                    </div>
                    <ul class="min-h-0 flex-1 divide-y divide-border overflow-y-auto">
                        <li v-for="r in call.reminders" :key="r.id" class="px-4 py-2 text-sm">
                            <p class="line-clamp-2">{{ r.message }}</p>
                            <p class="text-xs text-muted-foreground">{{ formatTime(r.send_at) }} · {{ r.status }}</p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
