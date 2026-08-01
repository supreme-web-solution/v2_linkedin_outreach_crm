<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Bot, ExternalLink, Info, Loader2, Megaphone, MessageCircle, MessagesSquare, Paperclip, Pause, Search, Send, Sparkles, Trash2, X, AlertTriangle } from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';
import ListPagination from '@/components/crm/ListPagination.vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { formatEmailBody } from '@/lib/formatEmailBody';
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
    prospect_email?: string | null;
    email_quality?: { level: string; label: string; hint: string | null } | null;
    last_message_preview: string | null;
    last_message_at: string | null;
    outreach_campaign_id: number | null;
    outreach_campaign_name: string | null;
    messages_count?: number;
    is_unread?: boolean;
};

type OutreachContext = {
    campaign: { id: number; name: string; status: string; href: string };
    lead: { id: number; full_name: string | null; status: string; phone: string | null; email: string | null; email_quality?: { level: string; label: string; hint: string | null } | null } | null;
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
    selected: { id: number; prospect_name: string | null; prospect_email?: string | null; has_channel: boolean } | null;
    messages: MessageItem[];
    outreachContext: OutreachContext | null;
    unread_count: number;
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
const deletingConversationId = ref<number | null>(null);
const deletingMessageId = ref<number | null>(null);

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
    formatted?: { main: string; quoted: string | null; preview: string; quote_header?: string | null } | null;
    at: string | null;
    source: string | null;
    attachments?: MessageAttachment[];
    reactions?: MessageReaction[];
};

const expandedQuotes = ref<Record<number, boolean>>({});

const localMessages = ref<MessageItem[]>([...props.messages]);
const localConversations = ref<ConversationsPaginator>({ ...props.conversations, data: [...props.conversations.data] });
const localOutreachContext = ref<OutreachContext | null>(props.outreachContext);
const localUnreadCount = ref(props.unread_count ?? 0);

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
    () => props.unread_count,
    (count) => {
        localUnreadCount.value = count ?? 0;
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
    (ctx, prevCtx) => {
        if (!ctx) return;

        const threadChanged = prevCtx?.campaign?.id !== ctx.campaign?.id
            || prevCtx?.lead?.id !== ctx.lead?.id;

        if (threadChanged || !settingsForm.isDirty) {
            settingsForm.ai_context = ctx.channel_settings.ai_context;
            settingsForm.auto_reply_enabled = ctx.channel_settings.auto_reply_enabled;
            settingsForm.pause_on_reply = ctx.channel_settings.pause_on_reply;
            settingsForm.clearErrors();
            settingsForm.defaults({
                ai_context: ctx.channel_settings.ai_context,
                auto_reply_enabled: ctx.channel_settings.auto_reply_enabled,
                pause_on_reply: ctx.channel_settings.pause_on_reply,
            });
        }
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

function formatMessageTime(at: string | null) {
    if (!at) return '';
    try {
        const date = new Date(at);
        const now = new Date();
        const sameDay = date.toDateString() === now.toDateString();
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        const isYesterday = date.toDateString() === yesterday.toDateString();

        if (sameDay) {
            return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
        }
        if (isYesterday) {
            return `Yesterday ${date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
        }

        return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    } catch {
        return at.slice(11, 16);
    }
}

function messageDayKey(at: string | null): string {
    if (!at) return '';
    try {
        return new Date(at).toDateString();
    } catch {
        return at.slice(0, 10);
    }
}

function showDateDivider(index: number): boolean {
    const current = localMessages.value[index];
    if (!current?.at) return false;
    if (index === 0) return true;

    const previous = localMessages.value[index - 1];
    if (!previous?.at) return true;

    return messageDayKey(current.at) !== messageDayKey(previous.at);
}

function formatDateDivider(at: string | null) {
    if (!at) return '';
    try {
        const date = new Date(at);
        const now = new Date();
        const sameDay = date.toDateString() === now.toDateString();
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        const isYesterday = date.toDateString() === yesterday.toDateString();

        if (sameDay) return 'Today';
        if (isYesterday) return 'Yesterday';

        return date.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' });
    } catch {
        return at.slice(0, 10);
    }
}

function messageDisplay(msg: MessageItem) {
    if (props.platform === 'email') {
        return msg.formatted ?? formatEmailBody(msg.body);
    }

    return { main: msg.body, quoted: null as string | null, preview: msg.body, quote_header: null as string | null };
}

function emailQuoteHeader(msg: MessageItem): string | null {
    const header = messageDisplay(msg).quote_header;
    return header && header.trim() !== '' ? header : null;
}

function toggleQuoted(messageId: number) {
    expandedQuotes.value[messageId] = !expandedQuotes.value[messageId];
}

function emailQualityClass(level: string) {
    if (level === 'placeholder' || level === 'invalid') return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-emerald-200 bg-emerald-50 text-emerald-800';
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

function deleteConversation(conv: ConversationItem) {
    const label = conv.prospect_name ?? conv.prospect_email ?? 'this conversation';
    if (!confirm(`Remove ${label} from your inbox? Messages will be deleted from this app.`)) {
        return;
    }

    deletingConversationId.value = conv.id;
    router.delete(`/inbox/${props.platform}/${conv.id}`, {
        preserveScroll: true,
        onFinish: () => { deletingConversationId.value = null; },
    });
}

function deleteSelectedConversation() {
    if (!props.selected) {
        return;
    }

    deleteConversation({
        id: props.selected.id,
        prospect_name: props.selected.prospect_name,
        prospect_email: props.selected.prospect_email ?? null,
        last_message_preview: null,
        last_message_at: null,
        outreach_campaign_id: null,
        outreach_campaign_name: null,
    });
}

function deleteMessage(msg: MessageItem) {
    if (!props.selected) {
        return;
    }
    if (!confirm('Remove this message from the thread?')) {
        return;
    }

    deletingMessageId.value = msg.id;
    router.delete(`/inbox/${props.platform}/${props.selected.id}/messages/${msg.id}`, {
        preserveScroll: true,
        onFinish: () => { deletingMessageId.value = null; },
    });
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
            unread_count?: number;
        };

        localMessages.value = data.messages ?? localMessages.value;
        if (data.conversations) {
            localConversations.value = data.conversations;
        }
        if (typeof data.unread_count === 'number') {
            localUnreadCount.value = data.unread_count;
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
    settingsForm.put(localOutreachContext.value.settings_update_url, {
        preserveScroll: true,
        onSuccess: () => {
            settingsForm.defaults();
        },
    });
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
                    <span
                        v-if="localUnreadCount > 0"
                        class="rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-primary-foreground"
                    >
                        {{ localUnreadCount }} unread
                    </span>
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
                        <span v-if="localUnreadCount > 0" class="font-medium text-primary"> · {{ localUnreadCount }} unread</span>
                    </p>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto bg-gradient-to-b from-muted/10 to-background">
                    <div
                        v-for="conv in localConversations.data"
                        :key="conv.id"
                        role="button"
                        tabindex="0"
                        class="group flex cursor-pointer items-center gap-2 border-b border-border/50 px-2.5 py-2 text-left transition-colors hover:bg-muted/40"
                        :class="[
                            selected?.id === conv.id ? 'bg-primary/5' : '',
                            conv.is_unread && selected?.id !== conv.id ? 'bg-primary/[0.03]' : '',
                        ]"
                        :style="selected?.id === conv.id ? { borderLeft: `2px solid ${platformColor}` } : { borderLeft: '2px solid transparent' }"
                        @click="openThread(conv.id)"
                        @keydown.enter="openThread(conv.id)"
                    >
                        <div class="relative flex h-8 w-8 shrink-0 items-center justify-center">
                            <div
                                class="flex h-8 w-8 items-center justify-center rounded-full text-[11px] font-semibold"
                                :style="{
                                    backgroundColor: avatarPalette(conv.prospect_name).bg,
                                    color: avatarPalette(conv.prospect_name).text,
                                }"
                            >
                                {{ contactInitials(conv.prospect_name) }}
                            </div>
                            <span
                                v-if="conv.is_unread && selected?.id !== conv.id"
                                class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-card bg-primary"
                                aria-hidden="true"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-sm leading-tight"
                                        :class="conv.is_unread && selected?.id !== conv.id ? 'font-semibold text-foreground' : 'font-medium text-foreground'"
                                    >
                                        {{ conv.prospect_name ?? 'Unknown contact' }}
                                    </p>
                                    <p v-if="platform === 'email' && conv.prospect_email" class="truncate text-[11px] leading-tight text-muted-foreground">
                                        {{ conv.prospect_email }}
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 text-[10px]"
                                    :class="conv.is_unread && selected?.id !== conv.id ? 'font-semibold text-primary' : 'text-muted-foreground'"
                                >
                                    {{ formatTime(conv.last_message_at) }}
                                </span>
                            </div>
                            <div v-if="conv.outreach_campaign_name || (platform === 'email' && conv.email_quality && conv.email_quality.level !== 'ok')" class="mt-0.5 flex flex-wrap items-center gap-1">
                                <span
                                    v-if="conv.outreach_campaign_name"
                                    class="inline-flex max-w-full items-center gap-0.5 truncate rounded border border-border/60 bg-muted/30 px-1.5 py-0.5 text-[9px] font-medium text-muted-foreground"
                                >
                                    <Megaphone class="h-2.5 w-2.5 shrink-0" />
                                    {{ conv.outreach_campaign_name }}
                                </span>
                                <span
                                    v-if="platform === 'email' && conv.email_quality && conv.email_quality.level !== 'ok'"
                                    class="inline-flex items-center gap-0.5 rounded border px-1.5 py-0.5 text-[9px] font-medium"
                                    :class="emailQualityClass(conv.email_quality.level)"
                                    :title="conv.email_quality.hint ?? conv.email_quality.label"
                                >
                                    <AlertTriangle class="h-2.5 w-2.5 shrink-0" />
                                    Bad email
                                </span>
                            </div>
                            <p
                                v-if="conv.last_message_preview"
                                class="mt-0.5 line-clamp-1 text-[11px] leading-snug"
                                :class="conv.is_unread && selected?.id !== conv.id ? 'font-medium text-foreground/80' : 'text-muted-foreground'"
                            >
                                {{ conv.last_message_preview }}
                            </p>
                            <p v-else class="mt-0.5 text-[11px] italic text-muted-foreground/60">No messages yet</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                            <button
                                type="button"
                                class="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-primary"
                                title="Open conversation"
                                @click.stop="openThread(conv.id)"
                            >
                                <ExternalLink class="h-3.5 w-3.5" />
                            </button>
                            <button
                                type="button"
                                class="rounded-md p-1 text-muted-foreground hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                                title="Delete conversation"
                                :disabled="deletingConversationId === conv.id"
                                @click.stop="deleteConversation(conv)"
                            >
                                <Loader2 v-if="deletingConversationId === conv.id" class="h-3.5 w-3.5 animate-spin" />
                                <Trash2 v-else class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>

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
                        <div class="shrink-0 border-b border-border px-4 py-2.5">
                            <div class="flex items-center gap-3">
                                <button
                                    type="button"
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground hover:bg-muted/50 lg:hidden"
                                    aria-label="Back to conversations"
                                    @click="backToThreadList"
                                >
                                    <ArrowLeft class="h-4 w-4" />
                                </button>
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                                    :style="{
                                        backgroundColor: avatarPalette(selected.prospect_name).bg,
                                        color: avatarPalette(selected.prospect_name).text,
                                    }"
                                >
                                    {{ contactInitials(selected.prospect_name) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2 class="truncate text-sm font-semibold">{{ selected.prospect_name ?? 'Unknown contact' }}</h2>
                                    <p v-if="platform === 'email' && selected.prospect_email" class="truncate text-[11px] text-muted-foreground">
                                        {{ selected.prospect_email }}
                                    </p>
                                    <p v-else class="text-[11px] text-muted-foreground">{{ platformLabel }} conversation</p>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-muted-foreground hover:bg-red-50 hover:text-red-600"
                                    title="Delete conversation"
                                    @click="deleteSelectedConversation"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <div ref="messageScroll" class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-gradient-to-b from-muted/10 to-background p-4">
                            <template v-for="(msg, msgIndex) in localMessages" :key="msg.id">
                                <div
                                    v-if="showDateDivider(msgIndex)"
                                    class="flex justify-center py-1"
                                >
                                    <span class="rounded-full bg-muted/70 px-3 py-1 text-[11px] font-medium text-muted-foreground shadow-sm">
                                        {{ formatDateDivider(msg.at) }}
                                    </span>
                                </div>
                            <div
                                class="group/msg flex flex-col"
                                :class="msg.direction === 'outbound' ? 'items-end' : 'items-start'"
                            >
                                <div
                                    class="relative max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm"
                                    :class="msg.direction === 'outbound'
                                        ? 'rounded-br-md bg-primary text-primary-foreground'
                                        : 'rounded-bl-md border border-slate-200 bg-slate-100 text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100'"
                                >
                                    <button
                                        type="button"
                                        class="absolute -right-1 -top-1 z-10 rounded-full border border-border bg-background p-1 text-muted-foreground opacity-0 shadow-sm transition-opacity hover:text-red-600 group-hover/msg:opacity-100 disabled:opacity-50"
                                        title="Delete message"
                                        :disabled="deletingMessageId === msg.id"
                                        @click="deleteMessage(msg)"
                                    >
                                        <Loader2 v-if="deletingMessageId === msg.id" class="h-3 w-3 animate-spin" />
                                        <Trash2 v-else class="h-3 w-3" />
                                    </button>
                                    <p v-if="msg.source === 'outreach_campaign'" class="mb-1 text-[10px] uppercase opacity-70">Outreach sent</p>
                                    <template v-if="msg.body && msg.body !== '[Attachment]'">
                                        <template v-if="platform === 'email'">
                                            <p class="whitespace-pre-wrap break-words text-sm leading-relaxed">{{ messageDisplay(msg).main || messageDisplay(msg).preview }}</p>
                                            <div v-if="emailQuoteHeader(msg) || messageDisplay(msg).quoted" class="mt-2.5 space-y-1.5">
                                                <p
                                                    v-if="emailQuoteHeader(msg)"
                                                    class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-md px-2 py-1 text-[10px] font-bold leading-snug tracking-wide"
                                                    :class="msg.direction === 'outbound'
                                                        ? 'bg-primary-foreground/15 text-primary-foreground/80'
                                                        : 'bg-muted/70 text-muted-foreground'"
                                                >
                                                    <span
                                                        class="shrink-0 uppercase opacity-80"
                                                        :class="msg.direction === 'outbound' ? 'text-primary-foreground/60' : 'text-primary/70'"
                                                    >Re</span>
                                                    <span class="font-semibold normal-case">{{ emailQuoteHeader(msg) }}</span>
                                                </p>
                                                <button
                                                    v-if="messageDisplay(msg).quoted"
                                                    type="button"
                                                    class="text-[11px] font-medium underline-offset-2 hover:underline"
                                                    :class="msg.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'"
                                                    @click="toggleQuoted(msg.id)"
                                                >
                                                    {{ expandedQuotes[msg.id] ? 'Hide quoted email' : 'Show quoted email' }}
                                                </button>
                                                <p
                                                    v-if="messageDisplay(msg).quoted && expandedQuotes[msg.id]"
                                                    class="whitespace-pre-wrap break-words rounded-lg border border-black/10 bg-black/5 px-2.5 py-2 text-xs leading-relaxed opacity-90"
                                                >
                                                    {{ messageDisplay(msg).quoted }}
                                                </p>
                                            </div>
                                        </template>
                                        <p v-else class="whitespace-pre-wrap break-words leading-relaxed">{{ msg.body }}</p>
                                    </template>
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
                                    <div
                                        v-if="msg.at"
                                        class="mt-1.5 flex justify-end pt-0.5"
                                        :class="msg.direction === 'outbound'
                                            ? 'border-t border-primary-foreground/15'
                                            : 'border-t border-border/60'"
                                    >
                                        <time
                                            :datetime="msg.at"
                                            class="text-[11px] font-medium tabular-nums tracking-wide select-none"
                                            :class="msg.direction === 'outbound'
                                                ? 'text-primary-foreground/75'
                                                : 'text-muted-foreground'"
                                        >
                                            {{ formatMessageTime(msg.at) }}
                                        </time>
                                    </div>
                                </div>
                            </div>
                            </template>
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
                        <p v-if="localOutreachContext.lead?.email" class="mt-1 truncate text-xs text-muted-foreground">
                            {{ localOutreachContext.lead.email }}
                        </p>
                        <p
                            v-if="localOutreachContext.lead?.email_quality && localOutreachContext.lead.email_quality.level !== 'ok'"
                            class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-[11px] leading-relaxed text-amber-900"
                        >
                            <span class="font-medium">{{ localOutreachContext.lead.email_quality.label }}.</span>
                            {{ localOutreachContext.lead.email_quality.hint }}
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
