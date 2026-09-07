<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AlexAvatar from '@/components/crm/AlexAvatar.vue';
import CommandCenterEmployeeSettings from '@/components/crm/CommandCenterEmployeeSettings.vue';
import { Check, Bot, ChevronDown, History, Inbox, Link2, Loader2, MessageCircle, PauseCircle, RefreshCw, Rocket, Send, Undo2, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import CommandCenterChannelLabel from '@/components/crm/CommandCenterChannelLabel.vue';
import WhatsAppCommandLinkPanel, { type WhatsAppCommandLink } from '@/components/crm/WhatsAppCommandLinkPanel.vue';
import ChatTypingIndicator from '@/components/crm/ChatTypingIndicator.vue';
import { Input } from '@/components/ui/input';
import {
    formatChatDateDivider,
    formatChatMessageTime,
    showChatDateDivider,
} from '@/lib/chatTimeline';
import { useCommandCenterChat } from '@/composables/useCommandCenterChat';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'AI Employee', href: '/ai-employee' },
        ],
    },
});

type ChatMessage = {
    id?: number;
    role: 'user' | 'assistant';
    content: string;
    channel?: string | null;
    created_at?: string | null;
};

type Approval = {
    id: number;
    tool: string;
    status: string;
    payload: Record<string, unknown>;
    card_text: string;
    funnel?: PlanFunnelStep[];
    actions?: {
        approve_label?: string;
        reject_label?: string;
        preview_label?: string;
        show_preview?: boolean;
        summary?: string;
    };
};

type PlanFunnelStep = {
    step: string;
    label: string;
    detail: string;
    status: 'ready' | 'blocked' | 'pending';
};

type AttentionItem = {
    conversation_id: number;
    priority: 'hot' | 'needs_judgment' | 'low_priority';
    prospect_name: string;
    channel: string;
    channel_label: string;
    preview: string;
    intent?: string;
    stage?: string;
    recommended_action: string;
    evidence?: string[];
    inbox_url: string;
    last_message_at?: string | null;
};

type AttentionQueue = {
    items: AttentionItem[];
    counts: Record<string, number>;
    summary: string;
    inbox_brief?: {
        headline: string;
        ai_handled_estimate: number;
        need_you: number;
        hot: number;
        needs_judgment: number;
        low_priority: number;
        meeting_ready: number;
        pricing_inquiry: number;
        pending_approvals: number;
    };
    nurture_brief?: {
        headline: string;
        total: number;
        due_this_week: number;
        overdue: number;
    };
};

type NurtureSidebarItem = {
    outreach_lead_id: number;
    prospect_name: string;
    channel_label: string | null;
    reason: string;
    follow_up_at: string | null;
    status: 'scheduled' | 'due_soon' | 'overdue';
    inbox_url: string | null;
    alex_starter: string;
};

type NurtureSidebar = {
    items: NurtureSidebarItem[];
    summary: string;
    nurture_brief: NonNullable<AttentionQueue['nurture_brief']>;
};

type ActionHistoryItem = {
    id: number;
    tool: string;
    label: string;
    status: string;
    summary: string;
    can_undo: boolean;
    undo_hint: string;
    undone_at?: string | null;
    created_at?: string | null;
    error?: string | null;
};

type IntegrationChannel = {
    key: string;
    label: string;
    connected: boolean;
    enabled: boolean;
    tier: 'primary' | 'secondary';
};

const props = defineProps<{
    settings: {
        enabled: boolean;
        kill_switch: boolean;
        autonomy_level: number;
        employee_name: string;
    };
    whatsapp: {
        linked: boolean;
        phone: string | null;
        bot_number: string | null;
        configured: boolean;
    };
    conversation_id: number;
    messages: ChatMessage[];
    has_older_messages?: boolean;
    pending_approvals: Approval[];
    attention_queue: AttentionQueue;
    action_history?: ActionHistoryItem[];
    integrations: IntegrationChannel[];
}>();

const sharedChat = useCommandCenterChat();
const {
    chat,
    draft,
    conversationId,
    sending,
    awaitingReply,
    scrollEl,
    hasOlderMessages,
    loadingOlder,
    pendingApprovals: sharedPendingApprovals,
    hydrateFromPage,
    send: sharedSend,
    loadOlderMessages,
    onChatScroll,
    scrollBottom,
    formatMessageHtml,
} = sharedChat;

hydrateFromPage({
    conversation_id: props.conversation_id,
    messages: props.messages,
    has_older_messages: props.has_older_messages,
    pending_approvals: props.pending_approvals,
    settings: props.settings,
});

const pending = ref<Approval[]>([...props.pending_approvals]);
const attention = ref<AttentionQueue>({ ...props.attention_queue });
const nurture = ref<NurtureSidebar | null>(null);
const actionHistory = ref<ActionHistoryItem[]>([...(props.action_history ?? [])]);
const draftEdits = ref<Record<number, string>>({});
const decidingId = ref<number | null>(null);
const draftingId = ref<number | null>(null);
const nextActionId = ref<number | null>(null);
const attentionBusy = ref(false);
const nurtureBusy = ref(false);
const actionHistoryBusy = ref(false);
const undoingId = ref<number | null>(null);
const linkBusy = ref(false);
const linkInfo = ref<WhatsAppCommandLink | null>(null);
const whatsappLinked = ref(props.whatsapp.linked);
const whatsappPhone = ref<string | null>(props.whatsapp.phone);
let linkPollTimer: ReturnType<typeof setInterval> | null = null;

// Sidebar panels collapse by default so Review & Launch stays in view.
const whatsappOpen = ref(!props.whatsapp.linked);
const activityOpen = ref(false);
const attentionOpen = ref(false);
const nurtureOpen = ref(false);

watch(
    sharedPendingApprovals,
    (items) => {
        if (Array.isArray(items)) {
            pending.value = items as Approval[];
        }
    },
    { deep: true },
);

const page = usePage();
const employeeSettings = ref({
    ...props.settings,
    enabled: Boolean(props.settings.enabled),
    kill_switch: Boolean(props.settings.kill_switch),
});
const isPlatformAdmin = computed(() => Boolean(page.props.isPlatformAdmin));

function autonomyLabelFor(level: number) {
    const map: Record<number, string> = {
        1: 'Copilot',
        2: 'Assisted',
        3: 'Autopilot',
        4: 'Autonomous',
    };
    return map[level] ?? 'Assisted';
}

function onEmployeeSettingsUpdated(next: typeof props.settings) {
    const previousLevel = employeeSettings.value.autonomy_level;
    employeeSettings.value = next;

    if (previousLevel !== next.autonomy_level) {
        chat.value.push({
            role: 'assistant',
            content: `Mode updated to **${autonomyLabelFor(next.autonomy_level)}**. My next reply follows ${autonomyLabelFor(next.autonomy_level)} rules — same chat thread, new behavior.`,
            channel: 'web',
            created_at: new Date().toISOString(),
        });
        void scrollBottom();
    }
}

const autonomyLabel = computed(() => {
    const map: Record<number, string> = {
        1: 'Copilot',
        2: 'Assisted',
        3: 'Autopilot',
        4: 'Autonomous',
    };
    return map[employeeSettings.value.autonomy_level] ?? 'Assisted';
});

const suggestions = [
    'Book 20 meetings with US SaaS founders this month',
    'Find my ideal customers for lead generation',
    'Let AI execute',
    'brief',
    'weekly brief',
    'attention',
    'status',
];

watch(
    pending,
    (items) => {
        for (const a of items) {
            if (
                (a.tool === 'draft_reply' || a.payload.type === 'draft_reply'
                    || a.tool === 'draft_personalized_message' || a.payload.type === 'personalized_message')
                && typeof a.payload.draft_text === 'string'
            ) {
                if (draftEdits.value[a.id] === undefined) {
                    draftEdits.value[a.id] = a.payload.draft_text;
                }
            }
        }
    },
    { immediate: true, deep: true },
);

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
    );
}

function priorityLabel(priority: AttentionItem['priority']) {
    if (priority === 'hot') return 'Hot';
    if (priority === 'low_priority') return 'Low';
    return 'Review';
}

function priorityClass(priority: AttentionItem['priority']) {
    if (priority === 'hot') return 'bg-orange-500/15 text-orange-700 dark:text-orange-300';
    if (priority === 'low_priority') return 'bg-muted text-muted-foreground';
    return 'bg-amber-500/15 text-amber-800 dark:text-amber-200';
}

function intentLabel(intent?: string) {
    if (!intent) return null;
    return intent.replace(/_/g, ' ');
}

function isDraftReply(a: Approval) {
    return a.tool === 'draft_reply' || a.payload.type === 'draft_reply';
}

function isPersonalizedMessage(a: Approval) {
    return a.tool === 'draft_personalized_message' || a.payload.type === 'personalized_message';
}

function isEditableDraft(a: Approval) {
    return isDraftReply(a) || isPersonalizedMessage(a);
}

function funnelStatusClass(status: PlanFunnelStep['status']) {
    if (status === 'ready') return 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-100';
    if (status === 'blocked') return 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100';
    return 'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-border dark:bg-muted/40 dark:text-muted-foreground';
}

function planFunnel(a: Approval): PlanFunnelStep[] {
    if (Array.isArray(a.funnel) && a.funnel.length) {
        return a.funnel;
    }
    const payloadFunnel = a.payload.funnel;
    return Array.isArray(payloadFunnel) ? (payloadFunnel as PlanFunnelStep[]) : [];
}

function isOutreachPlan(a: Approval) {
    return (
        a.tool === 'propose_strategy'
        || a.tool === 'draft_campaign_plan'
        || a.payload.type === 'strategy'
        || a.payload.type === 'campaign'
    );
}

function approveLabelFor(a: Approval): string {
    if (a.actions?.approve_label) return a.actions.approve_label;
    if (isDraftReply(a)) return 'Send';
    if (isPersonalizedMessage(a)) return 'Save';
    return 'Launch';
}

function rejectLabelFor(a: Approval): string {
    return a.actions?.reject_label || 'Reject';
}

function missingIntegrationsForPlan(a: Approval): string[] {
    const channels = String(
        a.payload.preferred_channels ?? a.payload.channels ?? 'linkedin + email',
    ).toLowerCase();
    const missing: string[] = [];

    for (const integration of props.integrations) {
        const key = integration.key;
        const mentioned =
            channels.includes(key)
            || (key === 'linkedin' && channels.includes('linked'))
            || (key === 'email' && channels.includes('mail'))
            || (key === 'instagram' && (channels.includes('insta') || channels.includes('ig')))
            || (key === 'telegram' && channels.includes('telegram'))
            || (key === 'whatsapp' && (channels.includes('whats') || channels.includes(' wa')));

        if (mentioned && !integration.connected) {
            missing.push(integration.label);
        }
    }

    return missing;
}

const primaryIntegrations = computed(() =>
    props.integrations.filter((i) => i.tier === 'primary'),
);

const secondaryIntegrations = computed(() =>
    props.integrations.filter((i) => i.tier === 'secondary'),
);

const disconnectedPrimaryIntegrations = computed(() =>
    primaryIntegrations.value.filter((integration) => !integration.connected),
);

const disconnectedSecondaryIntegrations = computed(() =>
    secondaryIntegrations.value.filter((integration) => !integration.connected),
);

async function refreshAttention() {
    attentionBusy.value = true;
    try {
        const res = await fetch('/ai-employee/attention?limit=8', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        const data = await res.json();
        if (res.ok) {
            attention.value = data;
            if ((data.nurture_brief?.total ?? 0) > 0) {
                await refreshNurture();
            } else {
                nurture.value = null;
            }
        }
    } finally {
        attentionBusy.value = false;
    }
}

async function refreshNurture() {
    nurtureBusy.value = true;
    try {
        const res = await fetch('/ai-employee/nurture?limit=5', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        const data = await res.json();
        if (res.ok && (data.nurture_brief?.total ?? 0) > 0) {
            nurture.value = data;
        } else {
            nurture.value = null;
        }
    } finally {
        nurtureBusy.value = false;
    }
}

async function refreshActionHistory() {
    actionHistoryBusy.value = true;
    try {
        const res = await fetch('/ai-employee/actions?limit=5', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        const data = await res.json();
        if (res.ok && Array.isArray(data.items)) {
            actionHistory.value = data.items;
        }
    } finally {
        actionHistoryBusy.value = false;
    }
}

async function undoAction(item: ActionHistoryItem) {
    if (!item.can_undo) return;
    undoingId.value = item.id;
    try {
        const res = await fetch(`/ai-employee/actions/${item.id}/undo`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message ?? 'Undo failed');

        if (data.item) {
            const idx = actionHistory.value.findIndex((a) => a.id === item.id);
            if (idx >= 0) {
                actionHistory.value[idx] = data.item;
            }
        } else {
            await refreshActionHistory();
        }

        chat.value.push({
            role: 'assistant',
            content: data.message ?? 'Undone.',
            channel: 'web',
            created_at: new Date().toISOString(),
        });
        await scrollBottom();
    } catch (e) {
        chat.value.push({
            role: 'assistant',
            content: e instanceof Error ? e.message : 'Could not undo that action.',
            channel: 'web',
            created_at: new Date().toISOString(),
        });
        await scrollBottom();
    } finally {
        undoingId.value = null;
    }
}

function actionTimeLabel(iso?: string | null): string {
    if (!iso) return '';
    try {
        return formatChatMessageTime(iso);
    } catch {
        return '';
    }
}

function nurtureStatusLabel(item: NurtureSidebarItem): string {
    if (item.status === 'overdue') return 'Overdue';
    if (item.status === 'due_soon') return 'Due soon';
    return 'Scheduled';
}

function askAlexNurture(starter: string) {
    void send(starter);
}

async function send(textOverride?: string) {
    const text = (textOverride ?? draft.value).trim();
    if (!text) return;

    await sharedSend(textOverride);

    if (Array.isArray(sharedPendingApprovals.value) && sharedPendingApprovals.value.length) {
        pending.value = sharedPendingApprovals.value as Approval[];
    }

    if (text.toLowerCase().includes('attention')) {
        await refreshAttention();
    }
    await refreshActionHistory();
}

async function draftInboxReply(conversationId: number) {
    draftingId.value = conversationId;
    try {
        const res = await fetch('/ai-employee/inbox/draft-reply', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ conversation_id: conversationId }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message ?? 'Draft failed');

        if (Array.isArray(data.pending_approvals)) {
            pending.value = data.pending_approvals;
        }
        if (data.card) {
            chat.value.push({
                role: 'assistant',
                content: data.card,
                channel: 'web',
            });
        }
    } catch (e) {
        chat.value.push({
            role: 'assistant',
            content: e instanceof Error ? e.message : 'Could not draft reply.',
            channel: 'web',
        });
    } finally {
        draftingId.value = null;
        await scrollBottom();
    }
}

async function stageNextAction(item: AttentionItem) {
    nextActionId.value = item.conversation_id;
    try {
        const res = await fetch('/ai-employee/inbox/next-action', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                conversation_id: item.conversation_id,
                action: item.recommended_action,
            }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message ?? 'Failed');

        if (Array.isArray(data.pending_approvals)) {
            pending.value = data.pending_approvals;
        }
        if (data.card) {
            chat.value.push({
                role: 'assistant',
                content: data.card,
                channel: 'web',
            });
        }
    } catch (e) {
        chat.value.push({
            role: 'assistant',
            content: e instanceof Error ? e.message : 'Could not stage next action.',
            channel: 'web',
        });
    } finally {
        nextActionId.value = null;
        await scrollBottom();
    }
}

async function decide(approvalId: number, decision: 'approve' | 'reject') {
    decidingId.value = approvalId;
    try {
        const body: Record<string, unknown> = { approval_id: approvalId, decision };
        const approval = pending.value.find((a) => a.id === approvalId);
        if (decision === 'approve' && approval && isEditableDraft(approval)) {
            body.draft_text = draftEdits.value[approvalId] ?? approval.payload.draft_text;
        }

        const res = await fetch('/ai-employee/approvals/decide', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message ?? 'Failed');

        if (data.reply) {
            chat.value.push({ role: 'assistant', content: data.reply, channel: 'web' });
        }
        pending.value = Array.isArray(data.pending_approvals) ? data.pending_approvals : [];
        delete draftEdits.value[approvalId];
        await refreshAttention();
        await refreshActionHistory();
    } finally {
        decidingId.value = null;
        await scrollBottom();
    }
}

async function refreshWhatsAppStatus() {
    try {
        const res = await fetch('/ai-employee/whatsapp/status', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        const data = await res.json();
        if (!res.ok) return;

        whatsappLinked.value = Boolean(data.linked);
        whatsappPhone.value = data.phone ?? null;

        if (whatsappLinked.value) {
            stopLinkPolling();
            linkInfo.value = null;
            whatsappOpen.value = false;
        }
    } catch {
        // ignore polling errors
    }
}

function stopLinkPolling() {
    if (linkPollTimer !== null) {
        clearInterval(linkPollTimer);
        linkPollTimer = null;
    }
}

function startLinkPolling() {
    stopLinkPolling();
    void refreshWhatsAppStatus();
    linkPollTimer = setInterval(() => {
        void refreshWhatsAppStatus();
    }, 3000);
}

const chatBusy = computed(() => sending.value || awaitingReply.value);

watch(chatBusy, (isBusy, wasBusy) => {
    // After queued reply lands, refresh side panels.
    if (wasBusy && !isBusy) {
        void refreshActionHistory();
        if (Array.isArray(sharedPendingApprovals.value)) {
            pending.value = sharedPendingApprovals.value as Approval[];
        }
    }
});

onBeforeUnmount(() => {
    stopLinkPolling();
});

onMounted(() => {
    void scrollBottom();

    if ((attention.value.nurture_brief?.total ?? 0) > 0) {
        void refreshNurture();
    }

    const params = new URLSearchParams(window.location.search);
    const starter = params.get('starter');
    if (starter) {
        draft.value = starter;
        window.history.replaceState({}, '', '/ai-employee');
    }
});

async function connectWhatsApp() {
    linkBusy.value = true;
    try {
        const res = await fetch('/ai-employee/whatsapp/link', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message ?? 'Link failed');
        linkInfo.value = {
            code: data.code,
            bot_number: data.bot_number,
            deep_link: data.deep_link,
            qr_svg: data.qr_svg,
            instructions: data.instructions,
            desktop_hint: data.desktop_hint,
            mobile_hint: data.mobile_hint,
        };
        startLinkPolling();
    } finally {
        linkBusy.value = false;
    }
}

async function disconnectWhatsApp() {
    linkBusy.value = true;
    try {
        const res = await fetch('/ai-employee/whatsapp/link', {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message ?? 'Disconnect failed');

        whatsappLinked.value = false;
        whatsappPhone.value = null;
        linkInfo.value = null;
        stopLinkPolling();
    } finally {
        linkBusy.value = false;
    }
}
</script>

<template>
    <Head title="Command Center" />

    <div class="flex h-[calc(100dvh-7rem)] min-h-[32rem] flex-col gap-4 overflow-hidden p-4 md:p-6">
        <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto lg:flex-row lg:overflow-hidden">
        <div class="flex h-[min(520px,52vh)] shrink-0 flex-col gap-4 lg:h-auto lg:min-h-0 lg:flex-1">
            <div class="shrink-0">
                <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                    <AlexAvatar size="md" :alt="employeeSettings.employee_name" online />
                    {{ employeeSettings.employee_name }}
                    <span class="text-muted-foreground font-normal">— Command Center</span>
                </h1>
                <p class="text-muted-foreground mt-1 text-sm">
                    One brain for web and WhatsApp. Autonomy: {{ autonomyLabel }}.
                    Autopilot runs allowlisted actions; undo them from Activity.
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2">
                <Button
                    v-for="s in suggestions"
                    :key="s"
                    size="sm"
                    variant="secondary"
                    :disabled="chatBusy"
                    @click="send(s)"
                >
                    {{ s }}
                </Button>
            </div>

            <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-card">
                <div
                    ref="scrollEl"
                    class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4"
                    @scroll="onChatScroll"
                >
                    <div v-if="hasOlderMessages" class="flex justify-center py-1">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border bg-background px-3 py-1 text-[11px] font-medium text-muted-foreground hover:bg-muted/50 disabled:opacity-60"
                            :disabled="loadingOlder"
                            @click="loadOlderMessages"
                        >
                            <Loader2 v-if="loadingOlder" class="size-3 animate-spin" />
                            {{ loadingOlder ? 'Loading…' : 'Load older messages' }}
                        </button>
                    </div>

                    <template v-for="(m, i) in chat" :key="m.id ?? `local-${i}`">
                        <div
                            v-if="showChatDateDivider(chat, i)"
                            class="flex justify-center py-1"
                        >
                            <span class="rounded-full bg-muted/70 px-3 py-1 text-[11px] font-medium text-muted-foreground shadow-sm">
                                {{ formatChatDateDivider(m.created_at) }}
                            </span>
                        </div>

                        <div
                            class="flex"
                            :class="m.role === 'user' ? 'justify-end' : 'justify-start'"
                        >
                            <div class="max-w-[90%] space-y-1">
                            <CommandCenterChannelLabel
                                :channel="m.channel"
                                :align="m.role === 'user' ? 'right' : 'left'"
                            />
                                <div
                                    class="rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap [&_strong]:font-semibold"
                                    :class="
                                        m.role === 'user'
                                            ? 'rounded-br-md bg-primary text-primary-foreground [&_strong]:text-primary-foreground'
                                            : 'rounded-bl-md bg-muted text-foreground'
                                    "
                                >
                                    <span v-html="formatMessageHtml(m.content)" />
                                    <div
                                        v-if="m.created_at"
                                        class="mt-1.5 flex justify-end border-t pt-1"
                                        :class="
                                            m.role === 'user'
                                                ? 'border-primary-foreground/15'
                                                : 'border-border/60'
                                        "
                                    >
                                        <time
                                            :datetime="m.created_at"
                                            class="text-[11px] font-medium tabular-nums tracking-wide select-none"
                                            :class="
                                                m.role === 'user'
                                                    ? 'text-primary-foreground/75'
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{ formatChatMessageTime(m.created_at) }}
                                        </time>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <ChatTypingIndicator
                        v-if="chatBusy"
                        :name="employeeSettings.employee_name"
                    />
                </div>
                <form class="flex shrink-0 gap-2 border-t p-3" @submit.prevent="send()">
                    <Input
                        v-model="draft"
                        placeholder="What do you want to accomplish?"
                        class="flex-1"
                        :disabled="chatBusy || !employeeSettings.enabled || employeeSettings.kill_switch"
                    />
                    <Button type="submit" :disabled="chatBusy || !draft.trim()">
                        <Loader2 v-if="chatBusy" class="size-4 animate-spin" />
                        <Send v-else class="size-4" />
                    </Button>
                </form>
            </div>
        </div>

        <aside class="flex min-h-[18rem] flex-1 flex-col gap-2 overflow-y-auto lg:w-80 lg:min-h-0 lg:flex-none">
            <CommandCenterEmployeeSettings
                :settings="employeeSettings"
                :is-platform-admin="isPlatformAdmin"
                @updated="onEmployeeSettingsUpdated"
            />

            <div class="flex min-h-[14rem] flex-1 flex-col overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-card">
                <div class="flex shrink-0 items-center justify-between border-b px-4 py-3">
                    <div class="text-sm font-medium">Review & Launch</div>
                    <span
                        v-if="pending.length"
                        class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    >
                        {{ pending.length }} pending
                    </span>
                </div>
                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto p-3">
                    <p v-if="!pending.length" class="text-muted-foreground text-xs">
                        No pending plans. In <strong>Assisted</strong> mode Alex stages plans here with Launch buttons.
                        <strong>Copilot</strong> never stages. <strong>Autopilot+</strong> auto-launches when audience exists.
                        Connect LinkedIn first or plans stay blocked.
                    </p>
                    <div v-for="a in pending" :key="a.id" class="space-y-2 rounded-lg border bg-zinc-50 p-3 text-sm dark:bg-muted/40">
                        <div class="text-muted-foreground text-[10px] uppercase tracking-wide">
                            #{{ a.id }} · {{ a.tool }}
                        </div>
                        <div
                            v-if="isOutreachPlan(a) && missingIntegrationsForPlan(a).length"
                            class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
                        >
                            Launch is blocked until you connect
                            {{ missingIntegrationsForPlan(a).join(' and ') }}.
                            <a href="/integrations" class="underline">Open Integrations</a>
                        </div>
                        <div
                            v-if="planFunnel(a).length"
                            class="overflow-x-auto rounded-md border bg-white/80 p-2 dark:bg-background/40"
                        >
                            <div class="flex min-w-max items-stretch gap-1">
                                <div
                                    v-for="(step, index) in planFunnel(a)"
                                    :key="step.step"
                                    class="flex items-center gap-1"
                                >
                                    <div
                                        class="flex w-28 flex-col rounded-md border px-2 py-1.5 text-[10px]"
                                        :class="funnelStatusClass(step.status)"
                                    >
                                        <div class="font-semibold uppercase tracking-wide">{{ step.label }}</div>
                                        <div class="mt-0.5 line-clamp-2 leading-snug">{{ step.detail }}</div>
                                    </div>
                                    <span
                                        v-if="index < planFunnel(a).length - 1"
                                        class="text-muted-foreground px-0.5"
                                    >→</span>
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="!isEditableDraft(a)"
                            class="font-sans text-xs whitespace-pre-wrap [&_strong]:font-semibold"
                            v-html="formatMessageHtml(a.card_text)"
                        />
                        <template v-else-if="isDraftReply(a)">
                            <div class="space-y-1 text-xs">
                                <div class="font-medium">{{ a.payload.prospect_name ?? 'Prospect' }}</div>
                                <div class="text-muted-foreground">{{ a.payload.channel_label ?? a.payload.channel }}</div>
                                <div v-if="a.payload.inbound_preview" class="text-muted-foreground italic">
                                    "{{ a.payload.inbound_preview }}"
                                </div>
                            </div>
                            <textarea
                                v-model="draftEdits[a.id]"
                                rows="4"
                                class="border-input bg-background w-full resize-y rounded-md border px-2 py-1.5 text-xs"
                                placeholder="Edit reply before Launch…"
                            />
                        </template>
                        <template v-else-if="isPersonalizedMessage(a)">
                            <div class="space-y-1 text-xs">
                                <div class="font-medium">{{ a.payload.prospect_name ?? 'Prospect' }}</div>
                                <div class="text-muted-foreground">{{ a.payload.channel_label ?? a.payload.channel }}</div>
                                <div v-if="a.payload.evidence" class="text-muted-foreground text-[10px]">
                                    Evidence: {{ JSON.stringify(a.payload.evidence) }}
                                </div>
                            </div>
                            <textarea
                                v-model="draftEdits[a.id]"
                                rows="4"
                                class="border-input bg-background w-full resize-y rounded-md border px-2 py-1.5 text-xs"
                                placeholder="Edit message before Launch…"
                            />
                        </template>
                        <div class="flex gap-2">
                            <Button
                                size="sm"
                                class="flex-1"
                                :disabled="decidingId === a.id"
                                @click="decide(a.id, 'approve')"
                            >
                                <Rocket class="mr-1 size-3.5" />
                                {{ approveLabelFor(a) }}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="decidingId === a.id"
                                @click="decide(a.id, 'reject')"
                            >
                                <X class="mr-1 size-3.5" />
                                {{ rejectLabelFor(a) }}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <Collapsible v-model:open="whatsappOpen" class="shrink-0 overflow-visible rounded-xl border bg-white shadow-sm dark:bg-card">
                <CollapsibleTrigger class="flex w-full items-center gap-2 px-4 py-3 text-left hover:bg-muted/30">
                    <MessageCircle class="size-4 shrink-0 text-[#25D366]" />
                    <span class="flex-1 text-sm font-medium">WhatsApp</span>
                    <span
                        v-if="whatsappLinked"
                        class="inline-flex items-center gap-1 rounded-full bg-[#25D366]/15 px-2 py-0.5 text-[10px] font-medium text-[#128C7E]"
                    >
                        <Check class="size-3" />
                        Connected
                    </span>
                    <span
                        v-else
                        class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    >
                        Setup
                    </span>
                    <ChevronDown class="size-4 shrink-0 transition-transform" :class="whatsappOpen ? 'rotate-180' : ''" />
                </CollapsibleTrigger>
                <CollapsibleContent class="overflow-visible border-t px-4 py-3">
                    <template v-if="whatsappLinked">
                        <p class="text-muted-foreground text-xs">{{ whatsappPhone }}</p>
                        <Button
                            class="mt-3 w-full"
                            size="sm"
                            variant="destructive"
                            :disabled="linkBusy"
                            @click="disconnectWhatsApp"
                        >
                            <Loader2 v-if="linkBusy" class="mr-1 size-3.5 animate-spin" />
                            Disconnect
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-muted-foreground text-xs">Same Alex brain from your phone.</p>
                        <Button
                            v-if="!linkInfo"
                            class="mt-3 w-full border-0 bg-[#25D366] text-white hover:bg-[#1da851]"
                            size="sm"
                            :disabled="linkBusy"
                            @click="connectWhatsApp"
                        >
                            <Loader2 v-if="linkBusy" class="mr-1 size-3.5 animate-spin" />
                            <Link2 v-else class="mr-1 size-3.5" />
                            Connect WhatsApp
                        </Button>
                    </template>

                    <WhatsAppCommandLinkPanel
                        v-if="linkInfo && !whatsappLinked"
                        :link="linkInfo"
                        compact
                        class="mt-3"
                    />

                    <div
                        v-if="disconnectedPrimaryIntegrations.length || disconnectedSecondaryIntegrations.length"
                        class="mt-3 space-y-2 border-t pt-3"
                    >
                        <p v-if="disconnectedPrimaryIntegrations.length" class="text-muted-foreground text-[11px]">
                            <span class="font-medium text-foreground">Primary outreach:</span>
                            connect
                            {{ disconnectedPrimaryIntegrations.map((i) => i.label).join(' + ') }}.
                            <a href="/integrations" class="text-primary underline">Integrations</a>
                        </p>
                        <p v-if="disconnectedSecondaryIntegrations.length" class="text-muted-foreground text-[11px]">
                            <span class="font-medium text-foreground">Secondary (optional):</span>
                            {{ disconnectedSecondaryIntegrations.map((i) => i.label).join(', ') }}
                            — connect when your plan uses them.
                        </p>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <Collapsible v-model:open="activityOpen" class="shrink-0 overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-card">
                <div class="flex items-center gap-1 border-b px-2 py-1.5">
                    <CollapsibleTrigger class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-muted/30">
                        <History class="size-4 shrink-0" />
                        <span class="flex-1 truncate text-sm font-medium">Activity</span>
                        <span
                            v-if="actionHistory.length"
                            class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                        >
                            {{ actionHistory.length }}
                        </span>
                        <ChevronDown class="size-4 shrink-0 transition-transform" :class="activityOpen ? 'rotate-180' : ''" />
                    </CollapsibleTrigger>
                    <Button size="sm" variant="ghost" class="h-7 px-2 text-[11px]" as-child>
                        <a href="/ai-employee/activity">View all</a>
                    </Button>
                    <Button size="icon" variant="ghost" class="size-7" :disabled="actionHistoryBusy" @click="refreshActionHistory">
                        <Loader2 v-if="actionHistoryBusy" class="size-3.5 animate-spin" />
                        <RefreshCw v-else class="size-3.5" />
                    </Button>
                </div>
                <CollapsibleContent>
                    <div class="max-h-56 space-y-2 overflow-y-auto p-3">
                        <p class="text-muted-foreground text-xs">
                            Latest actions (posts, campaigns, sends). Full history on Activity.
                        </p>
                        <p v-if="!actionHistory.length" class="text-muted-foreground text-xs">
                            No execute actions yet.
                        </p>
                        <div
                            v-for="item in actionHistory"
                            :key="item.id"
                            class="space-y-1.5 rounded-lg border bg-zinc-50 p-2.5 text-xs dark:bg-muted/40"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-medium">{{ item.label }}</div>
                                    <div class="text-muted-foreground text-[10px]">
                                        #{{ item.id }}
                                        <span v-if="item.created_at"> · {{ actionTimeLabel(item.created_at) }}</span>
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium uppercase"
                                    :class="
                                        item.undone_at
                                            ? 'bg-slate-100 text-slate-700 dark:bg-slate-900 dark:text-slate-200'
                                            : item.status === 'success'
                                              ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                                              : 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                                    "
                                >
                                    {{ item.undone_at ? 'Undone' : item.status }}
                                </span>
                            </div>
                            <p class="text-muted-foreground line-clamp-2">{{ item.summary }}</p>
                            <Button
                                v-if="item.can_undo"
                                size="sm"
                                class="h-7 w-full text-xs"
                                variant="outline"
                                :disabled="undoingId === item.id"
                                @click="undoAction(item)"
                            >
                                <Loader2 v-if="undoingId === item.id" class="mr-1 size-3 animate-spin" />
                                <Undo2 v-else class="mr-1 size-3" />
                                {{ item.undo_hint }}
                            </Button>
                            <p v-else-if="item.undo_hint" class="text-muted-foreground text-[10px]">
                                {{ item.undo_hint }}
                            </p>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <Collapsible v-model:open="attentionOpen" class="shrink-0 overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-card">
                <div class="flex items-center gap-1 border-b px-2 py-1.5">
                    <CollapsibleTrigger class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-muted/30">
                        <Inbox class="size-4 shrink-0" />
                        <span class="flex-1 truncate text-sm font-medium">Attention</span>
                        <span
                            v-if="(attention.inbox_brief?.need_you ?? 0) > 0"
                            class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                        >
                            {{ attention.inbox_brief?.need_you }} need you
                        </span>
                        <ChevronDown class="size-4 shrink-0 transition-transform" :class="attentionOpen ? 'rotate-180' : ''" />
                    </CollapsibleTrigger>
                    <Button size="icon" variant="ghost" class="size-7" :disabled="attentionBusy" @click="refreshAttention">
                        <Loader2 v-if="attentionBusy" class="size-3.5 animate-spin" />
                        <RefreshCw v-else class="size-3.5" />
                    </Button>
                </div>
                <CollapsibleContent>
                    <div class="max-h-56 space-y-2 overflow-y-auto p-3">
                        <p v-if="attention.inbox_brief" class="text-xs font-medium leading-snug">
                            {{ attention.inbox_brief.headline }}
                        </p>
                        <div v-if="attention.inbox_brief" class="flex flex-wrap gap-1.5 text-[10px]">
                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                                AI handled ~{{ attention.inbox_brief.ai_handled_estimate }}
                            </span>
                            <span
                                v-if="attention.inbox_brief.need_you > 0"
                                class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                            >
                                {{ attention.inbox_brief.need_you }} need you
                            </span>
                            <span
                                v-if="attention.inbox_brief.meeting_ready > 0"
                                class="rounded-full bg-sky-50 px-2 py-0.5 text-sky-900 dark:bg-sky-950 dark:text-sky-200"
                            >
                                {{ attention.inbox_brief.meeting_ready }} meeting-ready
                            </span>
                        </div>
                        <p class="text-muted-foreground text-xs">{{ attention.summary }}</p>
                        <p v-if="!attention.items.length" class="text-muted-foreground text-xs">
                            No unread inbox threads. Say "attention" in chat to refresh.
                        </p>
                        <div
                            v-for="item in attention.items"
                            :key="item.conversation_id"
                            class="space-y-2 rounded-lg border bg-zinc-50 p-2.5 text-xs dark:bg-muted/40"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="font-medium">{{ item.prospect_name }}</div>
                                    <div class="text-muted-foreground">{{ item.channel_label }}</div>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium uppercase"
                                    :class="priorityClass(item.priority)"
                                >
                                    {{ priorityLabel(item.priority) }}
                                </span>
                            </div>
                            <p class="text-muted-foreground line-clamp-2">{{ item.preview }}</p>
                            <p v-if="item.intent" class="text-muted-foreground text-[10px] capitalize">
                                {{ intentLabel(item.intent) }} · {{ item.stage?.replace(/_/g, ' ') }}
                            </p>
                            <p class="text-muted-foreground line-clamp-2 text-[10px]">{{ item.recommended_action }}</p>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    class="h-7 flex-1 text-xs"
                                    variant="secondary"
                                    :disabled="draftingId === item.conversation_id"
                                    @click="draftInboxReply(item.conversation_id)"
                                >
                                    <Loader2
                                        v-if="draftingId === item.conversation_id"
                                        class="mr-1 size-3 animate-spin"
                                    />
                                    Draft reply
                                </Button>
                                <Button
                                    size="sm"
                                    class="h-7 flex-1 text-xs"
                                    variant="outline"
                                    :disabled="nextActionId === item.conversation_id"
                                    @click="stageNextAction(item)"
                                >
                                    <Loader2
                                        v-if="nextActionId === item.conversation_id"
                                        class="mr-1 size-3 animate-spin"
                                    />
                                    Next action
                                </Button>
                                <Button size="sm" class="h-7 text-xs" variant="outline" as-child>
                                    <a :href="item.inbox_url" target="_blank" rel="noopener">Open</a>
                                </Button>
                            </div>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <Collapsible
                v-if="(attention.nurture_brief?.total ?? 0) > 0 || nurture"
                v-model:open="nurtureOpen"
                class="shrink-0 overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-card"
            >
                <div class="flex items-center gap-1 border-b px-2 py-1.5">
                    <CollapsibleTrigger class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-muted/30">
                        <PauseCircle class="size-4 shrink-0 text-violet-600" />
                        <span class="flex-1 truncate text-sm font-medium">Nurture</span>
                        <span
                            v-if="(attention.nurture_brief?.total ?? nurture?.nurture_brief?.total ?? 0) > 0"
                            class="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-medium text-violet-800 dark:bg-violet-950 dark:text-violet-200"
                        >
                            {{ attention.nurture_brief?.total ?? nurture?.nurture_brief?.total }}
                        </span>
                        <ChevronDown class="size-4 shrink-0 transition-transform" :class="nurtureOpen ? 'rotate-180' : ''" />
                    </CollapsibleTrigger>
                    <Button size="icon" variant="ghost" class="size-7" :disabled="nurtureBusy" @click="refreshNurture">
                        <Loader2 v-if="nurtureBusy" class="size-3.5 animate-spin" />
                        <RefreshCw v-else class="size-3.5" />
                    </Button>
                </div>
                <CollapsibleContent>
                    <div class="max-h-56 space-y-2 overflow-y-auto p-3">
                        <p class="text-xs font-medium leading-snug">
                            {{ nurture?.nurture_brief?.headline ?? attention.nurture_brief?.headline }}
                        </p>
                        <p v-if="nurture?.summary" class="text-muted-foreground text-[11px]">{{ nurture.summary }}</p>
                        <Button
                            size="sm"
                            variant="ghost"
                            class="h-7 w-full text-[11px]"
                            @click="askAlexNurture(`Who's due for nurture follow-up?`)"
                        >
                            Who's due for nurture follow-up?
                        </Button>
                        <div
                            v-for="item in nurture?.items ?? []"
                            :key="item.outreach_lead_id"
                            class="rounded-lg border bg-violet-50/60 p-2 text-xs dark:bg-violet-950/20"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-medium">{{ item.prospect_name }}</div>
                                    <div class="text-muted-foreground text-[10px]">{{ item.channel_label ?? 'Outreach' }}</div>
                                </div>
                                <span class="shrink-0 rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-medium text-violet-800 dark:bg-violet-950 dark:text-violet-200">
                                    {{ nurtureStatusLabel(item) }}
                                </span>
                            </div>
                            <p class="text-muted-foreground mt-1 line-clamp-2 text-[10px]">{{ item.reason }}</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <Button
                                    size="sm"
                                    class="h-6 flex-1 text-[10px]"
                                    variant="secondary"
                                    @click="askAlexNurture(item.alex_starter)"
                                >
                                    <Bot class="mr-1 size-3" />
                                    Ask Alex
                                </Button>
                                <Button v-if="item.inbox_url" size="sm" class="h-6 text-[10px]" variant="outline" as-child>
                                    <a :href="item.inbox_url">Open</a>
                                </Button>
                            </div>
                        </div>
                        <Button size="sm" variant="ghost" class="h-7 w-full text-[11px]" as-child>
                            <a href="/inbox?tab=nurture">Manage all in Inbox →</a>
                        </Button>
                    </div>
                </CollapsibleContent>
            </Collapsible>
        </aside>
        </div>
    </div>
</template>
