<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Bot, Loader2, Megaphone, Send } from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Unified Inbox', href: '/inbox' },
            { title: 'Platform' },
        ],
    },
});

type ConversationItem = {
    id: number;
    prospect_name: string | null;
    last_message_preview: string | null;
    last_message_at: string | null;
    outreach_campaign_id: number | null;
    outreach_campaign_name: string | null;
};

type OutreachContext = {
    campaign: { id: number; name: string; status: string; href: string };
    lead: { id: number; full_name: string | null; status: string; phone: string | null; email: string | null } | null;
    progress: { paused_reason: string | null; paused_channel: string | null; channel_replied: boolean } | null;
    campaign_outbound_count: number;
    channel_settings: { ai_context: string; auto_reply_enabled: boolean; pause_on_reply: boolean };
    settings_update_url: string;
};

const props = defineProps<{
    platform: string;
    platformLabel: string;
    platformColor: string;
    connected: boolean;
    conversations: ConversationItem[];
    selected: { id: number; prospect_name: string | null; has_channel: boolean } | null;
    messages: Array<{ id: number; direction: string; body: string; at: string | null; source: string | null }>;
    outreachContext: OutreachContext | null;
    aiConfigured: boolean;
    filters: { search: string | null; campaign: number | null; id: number | null };
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const search = ref(props.filters?.search ?? '');
const messageScroll = ref<HTMLElement | null>(null);
const sending = ref(false);
const polling = ref(false);

type MessageItem = { id: number; direction: string; body: string; at: string | null; source: string | null };

const localMessages = ref<MessageItem[]>([...props.messages]);
const localConversations = ref<ConversationItem[]>([...props.conversations]);
const localOutreachContext = ref<OutreachContext | null>(props.outreachContext);

let pollTimer: ReturnType<typeof setInterval> | null = null;

watch(
    () => props.messages,
    (messages) => {
        localMessages.value = [...messages];
    },
);

watch(
    () => props.conversations,
    (conversations) => {
        localConversations.value = [...conversations];
    },
);

watch(
    () => props.outreachContext,
    (ctx) => {
        localOutreachContext.value = ctx;
    },
);

const sendForm = useForm({ body: '' });
const settingsForm = useForm({
    ai_context: props.outreachContext?.channel_settings?.ai_context ?? '',
    auto_reply_enabled: props.outreachContext?.channel_settings?.auto_reply_enabled ?? false,
    pause_on_reply: props.outreachContext?.channel_settings?.pause_on_reply ?? true,
});

watch(
    () => localOutreachContext.value,
    (ctx) => {
        if (!ctx) return;
        settingsForm.ai_context = ctx.channel_settings.ai_context;
        settingsForm.auto_reply_enabled = ctx.channel_settings.auto_reply_enabled;
        settingsForm.pause_on_reply = ctx.channel_settings.pause_on_reply;
    },
);

const canSend = computed(
    () => Boolean(sendForm.body.trim()) && props.selected?.has_channel && !sending.value,
);

function formatTime(at: string | null) {
    if (!at) return '';
    try {
        return new Date(at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch {
        return at.slice(0, 16);
    }
}

function applyFilters() {
    router.get(`/inbox/${props.platform}`, {
        search: search.value || undefined,
        campaign: props.filters?.campaign ?? undefined,
        id: props.selected?.id ?? undefined,
    }, { preserveState: true, replace: true });
}

function openThread(id: number) {
    router.get(`/inbox/${props.platform}/${id}`, {}, { preserveState: false });
}

function scrollToBottom() {
    nextTick(() => {
        const el = messageScroll.value;
        if (el) el.scrollTop = el.scrollHeight;
    });
}

onMounted(() => {
    scrollToBottom();
    startPolling();
});

onUnmounted(() => {
    stopPolling();
});

watch(() => props.selected?.id, () => {
    stopPolling();
    startPolling();
});

watch(() => localMessages.value.length, (next, prev) => {
    if (next > prev) {
        scrollToBottom();
    }
});

async function pollThread() {
    if (typeof window === 'undefined' || !props.selected || sending.value || polling.value) {
        return;
    }

    if (document.visibilityState === 'hidden') {
        return;
    }

    polling.value = true;

    try {
        const response = await fetch(`/inbox/${props.platform}/${props.selected.id}/poll`, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json() as {
            messages: MessageItem[];
            conversations: ConversationItem[];
            outreachContext: OutreachContext | null;
        };

        localMessages.value = data.messages ?? localMessages.value;
        localConversations.value = data.conversations ?? localConversations.value;
        localOutreachContext.value = data.outreachContext ?? localOutreachContext.value;
    } catch {
        // ignore transient network errors
    } finally {
        polling.value = false;
    }
}

function startPolling() {
    if (typeof window === 'undefined' || !props.selected?.id) {
        return;
    }

    stopPolling();
    void pollThread();
    pollTimer = window.setInterval(() => {
        void pollThread();
    }, 4000);
}

function stopPolling() {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function sendMessage() {
    if (!canSend.value || !props.selected) return;
    sending.value = true;
    sendForm.post(`/inbox/${props.platform}/${props.selected.id}/send`, {
        preserveScroll: true,
        onSuccess: () => {
            sendForm.reset('body');
            void pollThread();
        },
        onFinish: () => { sending.value = false; },
    });
}

function saveChannelSettings() {
    if (!localOutreachContext.value) return;
    settingsForm.put(localOutreachContext.value.settings_update_url, { preserveScroll: true });
}

function onComposerKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}
</script>

<template>
    <Head :title="`${platformLabel} Inbox`" />

    <div class="flex h-[calc(100dvh-7rem)] min-h-[32rem] flex-col gap-3 overflow-hidden p-4">
        <div class="flex shrink-0 flex-wrap items-center justify-between gap-3">
            <div>
                <Link href="/inbox" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="h-4 w-4" /> All platforms
                </Link>
                <div class="mt-1 flex items-center gap-2">
                    <span class="inline-block h-3 w-3 rounded-full" :style="{ backgroundColor: platformColor }" />
                    <h1 class="text-xl font-semibold">{{ platformLabel }}</h1>
                </div>
            </div>
            <p v-if="!connected" class="text-sm text-amber-600">
                Connect {{ platformLabel }} in <Link href="/integrations" class="underline">Integrations</Link>
            </p>
        </div>

        <div v-if="flashSuccess" class="shrink-0 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ flashSuccess }}</div>
        <div v-if="flashError" class="shrink-0 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ flashError }}</div>

        <div class="grid min-h-0 flex-1 gap-4 lg:grid-cols-5">
            <!-- Threads list -->
            <div class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm lg:col-span-2">
                <div class="shrink-0 border-b border-border p-3">
                    <ListSearchBar v-model="search" placeholder="Search…" @search="applyFilters" />
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <button
                        v-for="conv in localConversations"
                        :key="conv.id"
                        type="button"
                        class="flex w-full flex-col gap-0.5 border-b border-border/60 px-3 py-3 text-left hover:bg-muted/40"
                        :class="selected?.id === conv.id ? 'bg-primary/5' : ''"
                        @click="openThread(conv.id)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <p class="truncate font-medium">{{ conv.prospect_name ?? 'Unknown' }}</p>
                            <span class="shrink-0 text-[10px] text-muted-foreground">{{ formatTime(conv.last_message_at) }}</span>
                        </div>
                        <p v-if="conv.outreach_campaign_name" class="truncate text-[10px] text-primary">{{ conv.outreach_campaign_name }}</p>
                        <p v-if="conv.last_message_preview" class="truncate text-xs text-muted-foreground">{{ conv.last_message_preview }}</p>
                    </button>
                    <p v-if="localConversations.length === 0" class="p-6 text-center text-sm text-muted-foreground">No {{ platformLabel }} conversations yet.</p>
                </div>
            </div>

            <!-- Chat + outreach sidebar -->
            <div class="flex min-h-0 flex-col gap-3 lg:col-span-3 lg:flex-row">
                <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <template v-if="selected">
                        <div class="shrink-0 border-b border-border px-4 py-3">
                            <h2 class="font-semibold">{{ selected.prospect_name ?? 'Unknown contact' }}</h2>
                        </div>
                        <div ref="messageScroll" class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                            <div
                                v-for="msg in localMessages"
                                :key="msg.id"
                                class="flex"
                                :class="msg.direction === 'outbound' ? 'justify-end' : 'justify-start'"
                            >
                                <div
                                    class="max-w-[85%] rounded-2xl px-3 py-2 text-sm"
                                    :class="msg.direction === 'outbound' ? 'rounded-br-md bg-primary text-primary-foreground' : 'rounded-bl-md border border-border bg-muted/30'"
                                >
                                    <p v-if="msg.source === 'outreach_campaign'" class="mb-1 text-[10px] uppercase opacity-70">Outreach sent</p>
                                    <p class="whitespace-pre-wrap break-words">{{ msg.body }}</p>
                                    <p class="mt-1 text-[10px] opacity-70">{{ formatTime(msg.at) }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="shrink-0 border-t border-border p-3">
                            <form class="flex items-end gap-2" @submit.prevent="sendMessage">
                                <textarea
                                    v-model="sendForm.body"
                                    rows="2"
                                    placeholder="Type a reply…"
                                    class="min-h-[2.75rem] flex-1 resize-none rounded-xl border border-border bg-background px-3 py-2 text-sm"
                                    :disabled="!selected.has_channel || sending"
                                    @keydown="onComposerKeydown"
                                />
                                <button type="submit" class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white disabled:opacity-50" :disabled="!canSend">
                                    <Loader2 v-if="sending" class="h-4 w-4 animate-spin" />
                                    <Send v-else class="h-4 w-4" />
                                </button>
                            </form>
                        </div>
                    </template>
                    <div v-else class="flex flex-1 items-center justify-center text-sm text-muted-foreground">Select a conversation</div>
                </div>

                <!-- AI + compact outreach sidebar -->
                <div v-if="localOutreachContext" class="flex w-full shrink-0 flex-col gap-3 lg:w-72 lg:self-start">
                    <div class="shrink-0 rounded-xl border border-border bg-card p-3 shadow-sm">
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <Bot class="h-3.5 w-3.5" /> {{ platformLabel }} AI
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">Same on every channel: your brief, summary of older messages, and the last 5 in the chat.</p>
                        <form class="mt-3 space-y-2" @submit.prevent="saveChannelSettings">
                            <textarea
                                v-model="settingsForm.ai_context"
                                rows="4"
                                class="w-full rounded-lg border border-border bg-background px-2 py-1.5 text-xs"
                                placeholder="Campaign goal, offer, tone, objections to handle — AI also uses the live conversation."
                            />
                            <label class="flex items-center gap-2 text-xs">
                                <input v-model="settingsForm.auto_reply_enabled" type="checkbox" class="rounded" />
                                AI auto-reply when this lead messages
                            </label>
                            <label class="flex items-start gap-2 text-xs">
                                <input v-model="settingsForm.pause_on_reply" type="checkbox" class="mt-0.5 rounded" />
                                <span>
                                    Pause <strong>this lead's</strong> sequence when they reply on {{ platformLabel }}
                                    <span class="mt-0.5 block text-[10px] text-muted-foreground">Other leads in the campaign keep running.</span>
                                </span>
                            </label>
                            <button type="submit" class="w-full rounded-lg bg-gradient-to-r from-blue-600 to-indigo-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50" :disabled="settingsForm.processing">
                                Save for this campaign
                            </button>
                            <p v-if="!aiConfigured" class="text-[10px] text-amber-600">OPENAI_API_KEY required for AI replies.</p>
                        </form>
                    </div>

                    <div class="shrink-0 rounded-xl border border-border bg-card p-3 text-sm shadow-sm">
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <Megaphone class="h-3.5 w-3.5" /> Outreach
                        </div>
                        <Link :href="localOutreachContext.campaign.href" class="mt-2 block font-medium text-primary hover:underline">
                            {{ localOutreachContext.campaign.name }}
                        </Link>
                        <p v-if="localOutreachContext.lead" class="mt-1 text-xs text-muted-foreground capitalize">
                            {{ localOutreachContext.lead.full_name }} · {{ localOutreachContext.lead.status }}
                        </p>
                        <p v-if="localOutreachContext.campaign_outbound_count > 0" class="mt-2 text-xs text-muted-foreground">
                            {{ localOutreachContext.campaign_outbound_count }} campaign message{{ localOutreachContext.campaign_outbound_count === 1 ? '' : 's' }} sent — view full thread in chat
                        </p>
                        <p v-if="localOutreachContext.progress?.channel_replied" class="mt-2 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-800">
                            Replied — sequence paused on {{ platformLabel }}
                        </p>
                    </div>
                </div>

                <div v-else-if="selected" class="flex w-full items-start rounded-xl border border-dashed border-border p-4 text-xs text-muted-foreground lg:w-72">
                    This thread is not linked to an outreach campaign. AI and pause settings are configured per campaign on the outreach detail page.
                </div>
            </div>
        </div>
    </div>
</template>
