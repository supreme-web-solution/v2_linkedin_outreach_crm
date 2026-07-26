<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Bot, Info, Loader2, Megaphone, MessageCircle, MessagesSquare, Paperclip, Pause, Search, Send, Sparkles, X } from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';
import ListPagination from '@/components/crm/ListPagination.vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

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

type ConversationsPaginator = {
    data: ConversationItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    platform: string;
    platformLabel: string;
    platformColor: string;
    connected: boolean;
    conversations: ConversationsPaginator;
    selected: { id: number; prospect_name: string | null; has_channel: boolean } | null;
    messages: MessageItem[];
    outreachContext: OutreachContext | null;
    aiConfigured: boolean;
    supportsAttachments: boolean;
    filters: { search: string | null; campaign: number | null; id: number | null };
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const search = ref(props.filters?.search ?? '');
const messageScroll = ref<HTMLElement | null>(null);
const sending = ref(false);
const polling = ref(false);

type MessageAttachment = {
    id: string;
    filename: string;
    mimetype: string;
    type: string;
    unavailable: boolean;
    url: string | null;
};

type MessageReaction = {
    value: string;
    is_sender: boolean;
};

type MessageItem = {
    id: number;
    provider_message_id?: string | null;
    direction: string;
    body: string;
    at: string | null;
    source: string | null;
    attachments?: MessageAttachment[];
    reactions?: MessageReaction[];
};

const localMessages = ref<MessageItem[]>([...props.messages]);
const localConversations = ref<ConversationsPaginator>({ ...props.conversations, data: [...props.conversations.data] });
const localOutreachContext = ref<OutreachContext | null>(props.outreachContext);

let pollTimer: ReturnType<typeof setInterval> | null = null;
let searchTimer: ReturnType<typeof setTimeout> | null = null;
let searchReady = false;

watch(
    () => props.messages,
    (messages) => {
        localMessages.value = [...messages];
    },
);

watch(
    () => props.conversations,
    (conversations) => {
        localConversations.value = { ...conversations, data: [...conversations.data] };
    },
    { deep: true },
);

watch(
    () => props.filters?.search,
    (value) => {
        const next = value ?? '';
        if (next !== search.value) {
            search.value = next;
        }
    },
);

watch(
    () => props.outreachContext,
    (ctx) => {
        localOutreachContext.value = ctx;
    },
);

const sendForm = useForm<{ body: string; attachment: File | null }>({ body: '', attachment: null });
const attachmentInputRef = ref<HTMLInputElement | null>(null);
const selectedAttachmentName = ref<string | null>(null);
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
    () => (Boolean(sendForm.body.trim()) || Boolean(sendForm.attachment)) && props.selected?.has_channel && !sending.value,
);

function isImageAttachment(att: MessageAttachment) {
    return att.type === 'img' || att.mimetype.startsWith('image/');
}

function isVideoAttachment(att: MessageAttachment) {
    return att.type === 'video' || att.mimetype.startsWith('video/');
}

function openAttachmentPicker() {
    attachmentInputRef.value?.click();
}

function onAttachmentSelected(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    sendForm.attachment = file;
    selectedAttachmentName.value = file?.name ?? null;
}

function clearAttachment() {
    sendForm.attachment = null;
    selectedAttachmentName.value = null;
    if (attachmentInputRef.value) {
        attachmentInputRef.value.value = '';
    }
}

function formatTime(at: string | null) {
    if (!at) return '';
    try {
        return new Date(at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch {
        return at.slice(0, 16);
    }
}

const avatarPalettes = [
    { bg: '#dcfce7', text: '#15803d', ring: '#bbf7d0' },
    { bg: '#dbeafe', text: '#1d4ed8', ring: '#bfdbfe' },
    { bg: '#fce7f3', text: '#be185d', ring: '#fbcfe8' },
    { bg: '#ffedd5', text: '#c2410c', ring: '#fed7aa' },
    { bg: '#ede9fe', text: '#6d28d9', ring: '#ddd6fe' },
    { bg: '#ccfbf1', text: '#0f766e', ring: '#99f6e4' },
    { bg: '#fef9c3', text: '#a16207', ring: '#fef08a' },
    { bg: '#e0e7ff', text: '#4338ca', ring: '#c7d2fe' },
] as const;

function contactInitials(name: string | null | undefined): string {
    const parts = (name ?? 'Unknown').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) {
        return '?';
    }
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase();
}

function avatarPalette(name: string | null | undefined) {
    const hash = [...(name ?? '?')].reduce((acc, char) => acc + char.charCodeAt(0), 0);

    return avatarPalettes[hash % avatarPalettes.length];
}

const hasSearchQuery = computed(() => search.value.trim().length > 0);
const isListEmpty = computed(() => localConversations.value.data.length === 0);

function applyFilters(page = 1) {
    const params: Record<string, string | number | undefined> = {
        search: search.value.trim() || undefined,
        campaign: props.filters?.campaign ?? undefined,
        page: page > 1 ? page : undefined,
    };

    const opts = { preserveState: true, replace: true, preserveScroll: true };

    if (props.selected?.id) {
        router.get(`/inbox/${props.platform}/${props.selected.id}`, params, opts);
    } else {
        router.get(`/inbox/${props.platform}`, params, opts);
    }
}

function openThread(id: number) {
    router.get(`/inbox/${props.platform}/${id}`, {
        search: search.value.trim() || undefined,
        page: props.conversations.current_page > 1 ? props.conversations.current_page : undefined,
    }, { preserveState: false });
}

function backToThreadList() {
    router.get(`/inbox/${props.platform}`, {
        search: search.value.trim() || undefined,
        page: props.conversations.current_page > 1 ? props.conversations.current_page : undefined,
    }, { preserveState: false });
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
    searchReady = true;
});

watch(search, () => {
    if (!searchReady) {
        return;
    }
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
    searchTimer = setTimeout(() => applyFilters(1), 300);
});

onUnmounted(() => {
    stopPolling();
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
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
        const params = new URLSearchParams();
        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }
        if (props.conversations.current_page > 1) {
            params.set('page', String(props.conversations.current_page));
        }
        const query = params.toString();
        const url = `/inbox/${props.platform}/${props.selected.id}/poll${query ? `?${query}` : ''}`;

        const response = await fetch(url, {
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
            conversations: ConversationsPaginator;
            outreachContext: OutreachContext | null;
        };

        localMessages.value = data.messages ?? localMessages.value;
        if (data.conversations) {
            localConversations.value = data.conversations;
        }
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
        forceFormData: true,
        onSuccess: () => {
            sendForm.reset('body');
            clearAttachment();
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
                    <OutreachChannelIcon :channel="platform" :size="24" class="h-6 w-6" />
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
            <!-- Threads list (hidden on mobile when a thread is open) -->
            <div
                class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm lg:col-span-2"
                :class="selected ? 'hidden lg:flex' : 'flex'"
            >
                <div class="shrink-0 border-b border-border bg-muted/20 px-3 py-3">
                    <ListSearchBar v-model="search" placeholder="Search conversations…" hide-button />
                    <p class="mt-2 text-[11px] text-muted-foreground">
                        {{ localConversations.total }} conversation{{ localConversations.total === 1 ? '' : 's' }}
                    </p>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto bg-gradient-to-b from-muted/10 to-background">
                    <button
                        v-for="conv in localConversations.data"
                        :key="conv.id"
                        type="button"
                        class="group flex w-full items-start gap-3 border-b border-border/50 px-3 py-3 text-left transition-colors hover:bg-muted/40"
                        :class="selected?.id === conv.id ? 'bg-primary/5 ring-1 ring-inset ring-primary/10' : ''"
                        :style="selected?.id === conv.id ? { borderLeft: `3px solid ${platformColor}` } : { borderLeft: '3px solid transparent' }"
                        @click="openThread(conv.id)"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-semibold ring-2 ring-background shadow-sm"
                            :style="{
                                backgroundColor: avatarPalette(conv.prospect_name).bg,
                                color: avatarPalette(conv.prospect_name).text,
                                boxShadow: `0 0 0 2px ${avatarPalette(conv.prospect_name).ring}`,
                            }"
                        >
                            {{ contactInitials(conv.prospect_name) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate font-semibold text-foreground">{{ conv.prospect_name ?? 'Unknown contact' }}</p>
                                <span class="shrink-0 text-[10px] text-muted-foreground">{{ formatTime(conv.last_message_at) }}</span>
                            </div>
                            <p v-if="conv.outreach_campaign_name" class="mt-0.5 truncate text-[10px] font-medium" :style="{ color: platformColor }">
                                {{ conv.outreach_campaign_name }}
                            </p>
                            <p v-if="conv.last_message_preview" class="mt-1 line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                                {{ conv.last_message_preview }}
                            </p>
                            <p v-else class="mt-1 text-xs italic text-muted-foreground/70">No messages yet</p>
                        </div>
                    </button>

                    <div v-if="isListEmpty" class="flex h-full min-h-[16rem] flex-col items-center justify-center px-6 py-10 text-center">
                        <div
                            class="mb-4 flex h-16 w-16 items-center justify-center rounded-full"
                            :style="{ backgroundColor: `${platformColor}18`, color: platformColor }"
                        >
                            <Search v-if="hasSearchQuery" class="h-7 w-7" />
                            <MessagesSquare v-else class="h-7 w-7" />
                        </div>
                        <p class="text-sm font-semibold text-foreground">
                            {{ hasSearchQuery ? 'No matches found' : `No ${platformLabel} conversations yet` }}
                        </p>
                        <p class="mt-2 max-w-[16rem] text-xs leading-relaxed text-muted-foreground">
                            <template v-if="hasSearchQuery">
                                Try a different name or clear the search to see all threads.
                            </template>
                            <template v-else-if="!connected">
                                Connect {{ platformLabel }} in Integrations, then replies from your outreach will appear here.
                            </template>
                            <template v-else>
                                When leads reply on {{ platformLabel }}, their conversations will show up in this list.
                            </template>
                        </p>
                    </div>
                </div>
                <ListPagination
                    v-if="localConversations.last_page > 1"
                    :paginator="localConversations"
                    label="conversations"
                    class="shrink-0 text-xs"
                />
            </div>

            <!-- Chat + sidebar (full width on mobile when a thread is open) -->
            <div
                class="flex min-h-0 flex-col gap-3 lg:col-span-3 lg:flex-row"
                :class="selected ? 'flex' : 'hidden lg:flex'"
            >
                <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <template v-if="selected">
                        <div class="shrink-0 border-b border-border px-4 py-3">
                            <div class="flex items-center gap-3">
                                <button
                                    type="button"
                                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground hover:bg-muted/50 lg:hidden"
                                    aria-label="Back to conversations"
                                    @click="backToThreadList"
                                >
                                    <ArrowLeft class="h-4 w-4" />
                                </button>
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold shadow-sm"
                                    :style="{
                                        backgroundColor: avatarPalette(selected.prospect_name).bg,
                                        color: avatarPalette(selected.prospect_name).text,
                                    }"
                                >
                                    {{ contactInitials(selected.prospect_name) }}
                                </div>
                                <div class="min-w-0">
                                    <h2 class="truncate font-semibold">{{ selected.prospect_name ?? 'Unknown contact' }}</h2>
                                    <p class="text-xs text-muted-foreground">{{ platformLabel }} conversation</p>
                                </div>
                            </div>
                        </div>
                        <div ref="messageScroll" class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-gradient-to-b from-muted/10 to-background p-4">
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
                                    <p v-if="msg.body && msg.body !== '[Attachment]'" class="whitespace-pre-wrap break-words">{{ msg.body }}</p>
                                    <div v-if="msg.attachments?.length" class="mt-2 space-y-2">
                                        <template v-for="att in msg.attachments" :key="att.id">
                                            <a
                                                v-if="att.url && !att.unavailable"
                                                :href="att.url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="block rounded-lg border border-white/20 bg-black/10 px-2 py-2 text-xs underline-offset-2 hover:underline"
                                            >
                                                <img
                                                    v-if="isImageAttachment(att)"
                                                    :src="att.url"
                                                    :alt="att.filename"
                                                    class="mb-1 max-h-48 w-full rounded-md object-cover"
                                                />
                                                <video
                                                    v-else-if="isVideoAttachment(att)"
                                                    :src="att.url"
                                                    controls
                                                    class="mb-1 max-h-48 w-full rounded-md"
                                                />
                                                <span>{{ att.filename }}</span>
                                                <span class="block text-[10px] opacity-70">Tap to open / download</span>
                                            </a>
                                            <p v-else class="text-xs opacity-70">{{ att.filename }} (unavailable)</p>
                                        </template>
                                    </div>
                                    <div v-if="msg.reactions?.length" class="mt-2 flex flex-wrap gap-1">
                                        <span
                                            v-for="(reaction, idx) in msg.reactions"
                                            :key="`${reaction.value}-${idx}`"
                                            class="rounded-full bg-black/10 px-2 py-0.5 text-sm"
                                            :title="reaction.is_sender ? 'You reacted' : 'Reaction'"
                                        >{{ reaction.value }}</span>
                                    </div>
                                    <p class="mt-1 text-[10px] opacity-70">{{ formatTime(msg.at) }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="shrink-0 border-t border-border p-3">
                            <div v-if="selectedAttachmentName" class="mb-2 flex items-center justify-between rounded-lg border border-border bg-muted/30 px-3 py-2 text-xs">
                                <span class="truncate">{{ selectedAttachmentName }}</span>
                                <button type="button" class="text-muted-foreground hover:text-foreground" @click="clearAttachment">
                                    <X class="h-4 w-4" />
                                </button>
                            </div>
                            <form class="flex items-end gap-2" @submit.prevent="sendMessage">
                                <input
                                    v-if="supportsAttachments"
                                    ref="attachmentInputRef"
                                    type="file"
                                    class="hidden"
                                    accept="image/*,video/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"
                                    @change="onAttachmentSelected"
                                />
                                <button
                                    v-if="supportsAttachments"
                                    type="button"
                                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-border text-muted-foreground hover:bg-muted/50"
                                    :disabled="!selected.has_channel || sending"
                                    @click="openAttachmentPicker"
                                >
                                    <Paperclip class="h-4 w-4" />
                                </button>
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
                    <div v-else class="flex flex-1 flex-col items-center justify-center bg-gradient-to-b from-muted/10 to-background px-6 py-12 text-center">
                        <div
                            class="mb-4 flex h-16 w-16 items-center justify-center rounded-full"
                            :style="{ backgroundColor: `${platformColor}18`, color: platformColor }"
                        >
                            <MessageCircle class="h-8 w-8" />
                        </div>
                        <p class="text-sm font-semibold text-foreground">Select a conversation</p>
                        <p class="mt-2 max-w-xs text-xs leading-relaxed text-muted-foreground">
                            Choose a contact from the list to read and reply on {{ platformLabel }}.
                        </p>
                    </div>
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
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/20 px-2.5 py-2">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <Sparkles class="h-3.5 w-3.5 shrink-0 text-violet-600" />
                                    <span class="text-xs font-medium">AI auto-reply</span>
                                    <Tooltip :delay-duration="200">
                                        <TooltipTrigger as-child>
                                            <button type="button" class="rounded p-0.5 text-muted-foreground hover:text-foreground" aria-label="About AI auto-reply">
                                                <Info class="h-3.5 w-3.5" />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent side="top" class="max-w-[14rem] text-xs leading-relaxed">
                                            When enabled, inbound replies on {{ platformLabel }} get an AI reply using this campaign context.
                                        </TooltipContent>
                                    </Tooltip>
                                </div>
                                <Switch v-model="settingsForm.auto_reply_enabled" class="shrink-0" />
                            </div>
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/20 px-2.5 py-2">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <Pause class="h-3.5 w-3.5 shrink-0 text-amber-600" />
                                    <span class="text-xs font-medium">Pause on reply</span>
                                    <Tooltip :delay-duration="200">
                                        <TooltipTrigger as-child>
                                            <button type="button" class="rounded p-0.5 text-muted-foreground hover:text-foreground" aria-label="About pause on reply">
                                                <Info class="h-3.5 w-3.5" />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent side="top" class="max-w-[14rem] text-xs leading-relaxed">
                                            Stops this lead's sequence when they reply on {{ platformLabel }}. Other leads keep running.
                                        </TooltipContent>
                                    </Tooltip>
                                </div>
                                <Switch v-model="settingsForm.pause_on_reply" class="shrink-0" />
                            </div>
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
                    Campaign context is missing for this thread. Open it from
                    <Link href="/outreach" class="mx-1 text-primary hover:underline">Multi-Channel Outreach</Link>
                    to configure AI and pause settings.
                </div>
            </div>
        </div>
    </div>
</template>
