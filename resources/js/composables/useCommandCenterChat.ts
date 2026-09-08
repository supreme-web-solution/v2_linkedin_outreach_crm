import { nextTick, ref, watch } from 'vue';
import {
    formatChatDateDivider,
    formatChatMessageTime,
    showChatDateDivider,
} from '@/lib/chatTimeline';
import { formatChatMarkdown } from '@/lib/chatMarkdown';

export type CommandCenterChatMessage = {
    id?: number;
    role: 'user' | 'assistant';
    content: string;
    channel?: string | null;
    created_at?: string | null;
};

type CommandCenterSettings = {
    enabled: boolean;
    kill_switch: boolean;
    employee_name: string;
};

type ApprovalLite = {
    id: number;
    tool?: string;
    status?: string;
    payload?: Record<string, unknown>;
    card_text?: string;
    actions?: {
        approve_label?: string;
        reject_label?: string;
        preview_label?: string;
        show_preview?: boolean;
        summary?: string;
    };
};

const POLL_MS = 1200;
const POLL_TIMEOUT_MS = 3 * 60 * 1000;

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
    );
}

function mergeOlderMessages(
    existing: CommandCenterChatMessage[],
    older: CommandCenterChatMessage[],
): CommandCenterChatMessage[] {
    const seen = new Set(existing.map((m) => m.id).filter(Boolean));
    const prepend = older.filter((m) => !m.id || !seen.has(m.id));

    return [...prepend, ...existing];
}

function mergeNewerMessages(
    existing: CommandCenterChatMessage[],
    newer: CommandCenterChatMessage[],
): CommandCenterChatMessage[] {
    const seen = new Set(existing.map((m) => m.id).filter(Boolean));
    const append = newer.filter((m) => !m.id || !seen.has(m.id));

    if (append.length === 0) {
        return existing;
    }

    return [...existing, ...append];
}

function maxMessageId(messages: CommandCenterChatMessage[]): number {
    let max = 0;
    for (const m of messages) {
        if (typeof m.id === 'number' && m.id > max) {
            max = m.id;
        }
    }
    return max;
}

// Module-level singleton so Inertia navigations do not reset mid-flight polls.
const chat = ref<CommandCenterChatMessage[]>([]);
const draft = ref('');
const conversationId = ref<number | null>(null);
const settings = ref<CommandCenterSettings>({
    enabled: true,
    kill_switch: false,
    employee_name: 'Alex',
});
const pendingApprovalsCount = ref(0);
const pendingApprovals = ref<ApprovalLite[]>([]);
const sending = ref(false);
const decidingApprovalId = ref<number | null>(null);
const clearingChat = ref(false);
const bootstrapping = ref(false);
const bootstrapped = ref(false);
const hasOlderMessages = ref(false);
const loadingOlder = ref(false);
const preserveScrollOnPrepend = ref(false);
const scrollEl = ref<HTMLElement | null>(null);
const awaitingReply = ref(false);

let pollTimer: ReturnType<typeof setInterval> | null = null;
let pollDeadline = 0;
let pollAfterId = 0;
let pollGeneration = 0;
let pollInFlight = false;

function clearPollTimer() {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function stopPolling() {
    clearPollTimer();
    awaitingReply.value = false;
    pollAfterId = 0;
    pollDeadline = 0;
    pollInFlight = false;
}

async function scrollBottom() {
    await nextTick();
    requestAnimationFrame(() => {
        const el = scrollEl.value;
        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    });
}

function applyApprovals(data: {
    pending_approvals?: ApprovalLite[];
    pending_approvals_count?: number;
}) {
    if (Array.isArray(data.pending_approvals)) {
        pendingApprovals.value = data.pending_approvals;
        pendingApprovalsCount.value = data.pending_approvals.length;
    } else if (typeof data.pending_approvals_count === 'number') {
        pendingApprovalsCount.value = data.pending_approvals_count;
    }
}

async function pollOnce(generation: number): Promise<boolean> {
    if (generation !== pollGeneration) {
        return false;
    }
    if (!conversationId.value || pollAfterId <= 0 || pollInFlight) {
        return false;
    }

    pollInFlight = true;

    try {
        const params = new URLSearchParams({
            conversation_id: String(conversationId.value),
            after_id: String(pollAfterId),
        });

        const res = await fetch(`/ai-employee/messages?${params.toString()}`, {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        const data = await res.json();

        if (generation !== pollGeneration) {
            return false;
        }

        if (!res.ok) {
            return false;
        }

        const newer = Array.isArray(data.messages) ? (data.messages as CommandCenterChatMessage[]) : [];
        applyApprovals(data);

        if (newer.length) {
            chat.value = mergeNewerMessages(chat.value, newer);
        }

        const assistant = newer.find((m) => {
            if (m.role !== 'assistant' || typeof m.content !== 'string') {
                return false;
            }
            const text = m.content.trim();
            // Ignore empty / ellipsis placeholders until a real reply lands.
            return text !== '' && !/^\.{1,3}$|^…$/.test(text);
        });

        if (assistant) {
            clearPollTimer();
            awaitingReply.value = false;
            pollAfterId = 0;
            pollDeadline = 0;
            sending.value = false;
            await scrollBottom();
            return true;
        }

        return false;
    } catch {
        return false;
    } finally {
        if (generation === pollGeneration) {
            pollInFlight = false;
        }
    }
}

function startPolling(afterMessageId: number) {
    clearPollTimer();
    pollGeneration += 1;
    const generation = pollGeneration;

    pollAfterId = afterMessageId;
    pollDeadline = Date.now() + POLL_TIMEOUT_MS;
    awaitingReply.value = true;
    sending.value = true;
    pollInFlight = false;

    void pollOnce(generation);

    pollTimer = setInterval(() => {
        if (generation !== pollGeneration) {
            clearPollTimer();
            return;
        }

        if (Date.now() > pollDeadline) {
            stopPolling();
            sending.value = false;
            chat.value = [
                ...chat.value,
                {
                    role: 'assistant',
                    content: 'Still working on that — refresh if the reply does not appear shortly.',
                    channel: 'web',
                    created_at: new Date().toISOString(),
                },
            ];
            void scrollBottom();
            return;
        }

        void pollOnce(generation);
    }, POLL_MS);
}

async function bootstrap(force = false) {
    if (bootstrapping.value || (bootstrapped.value && !force)) {
        return;
    }

    bootstrapping.value = true;

    try {
        const res = await fetch('/ai-employee/widget/bootstrap', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok) return;

        conversationId.value = data.conversation_id ?? null;
        settings.value = {
            enabled: Boolean(data.settings?.enabled),
            kill_switch: Boolean(data.settings?.kill_switch),
            employee_name: data.settings?.employee_name ?? 'Alex',
        };
        pendingApprovalsCount.value = Number(data.pending_approvals_count ?? 0);
        if (Array.isArray(data.pending_approvals)) {
            pendingApprovals.value = data.pending_approvals;
            pendingApprovalsCount.value = data.pending_approvals.length;
        }
        hasOlderMessages.value = Boolean(data.has_older_messages);

        const messages = Array.isArray(data.messages) ? data.messages : [];
        if (!awaitingReply.value) {
            chat.value = messages.length
                ? [...messages]
                : [
                      {
                          role: 'assistant',
                          content: `Hi — I'm ${settings.value.employee_name}, your SociFusion Command Center.\nTell me what you want to accomplish (same thread as WhatsApp once linked).`,
                      },
                  ];
        } else if (messages.length) {
            // Keep typing state; still merge any newer server rows (e.g. the reply).
            chat.value = mergeNewerMessages(chat.value, messages);
        }

        bootstrapped.value = true;
        await scrollBottom();
    } finally {
        bootstrapping.value = false;
    }
}

/**
 * Hydrate from full Command Center Inertia props (shared with widget singleton).
 * Never clobber a live thread that already has newer local/server messages.
 */
function hydrateFromPage(payload: {
    conversation_id: number;
    messages: CommandCenterChatMessage[];
    has_older_messages?: boolean;
    pending_approvals?: ApprovalLite[];
    settings?: Partial<CommandCenterSettings> & { autonomy_level?: number };
}) {
    conversationId.value = payload.conversation_id;

    const incoming = Array.isArray(payload.messages) ? payload.messages : [];
    const localMax = maxMessageId(chat.value);
    const incomingMax = maxMessageId(incoming);

    if (awaitingReply.value) {
        if (incoming.length) {
            chat.value = mergeNewerMessages(chat.value, incoming);
        }
    } else if (incomingMax >= localMax) {
        chat.value = incoming.length
            ? [...incoming]
            : [
                  {
                      role: 'assistant',
                      content: `Hi — I'm ${payload.settings?.employee_name ?? settings.value.employee_name}, your SociFusion Command Center.\nTell me what you want to accomplish (same thread as WhatsApp once linked).`,
                  },
              ];
    } else if (incoming.length) {
        chat.value = mergeNewerMessages(incoming, chat.value);
    }

    hasOlderMessages.value = Boolean(payload.has_older_messages);
    if (Array.isArray(payload.pending_approvals)) {
        pendingApprovals.value = payload.pending_approvals;
        pendingApprovalsCount.value = payload.pending_approvals.length;
    }
    if (payload.settings) {
        settings.value = {
            enabled: Boolean(payload.settings.enabled ?? settings.value.enabled),
            kill_switch: Boolean(payload.settings.kill_switch ?? settings.value.kill_switch),
            employee_name: payload.settings.employee_name ?? settings.value.employee_name,
        };
    }
    bootstrapped.value = true;
}

async function loadOlderMessages() {
    if (loadingOlder.value || !hasOlderMessages.value || !conversationId.value) return;

    const oldestId = chat.value.find((m) => m.id)?.id;
    if (!oldestId) return;

    loadingOlder.value = true;
    preserveScrollOnPrepend.value = true;
    const el = scrollEl.value;
    const prevHeight = el?.scrollHeight ?? 0;
    const prevTop = el?.scrollTop ?? 0;

    try {
        const params = new URLSearchParams({
            conversation_id: String(conversationId.value),
            before_id: String(oldestId),
        });
        const res = await fetch(`/ai-employee/messages?${params.toString()}`, {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok) return;

        chat.value = mergeOlderMessages(chat.value, data.messages ?? []);
        hasOlderMessages.value = Boolean(data.has_older);

        await nextTick();
        if (el) {
            el.scrollTop = prevTop + (el.scrollHeight - prevHeight);
        }
    } finally {
        loadingOlder.value = false;
        preserveScrollOnPrepend.value = false;
    }
}

function onChatScroll() {
    const el = scrollEl.value;
    if (!el || loadingOlder.value || !hasOlderMessages.value) return;

    if (el.scrollTop < 96) {
        void loadOlderMessages();
    }
}

async function send(textOverride?: string) {
    const text = (textOverride ?? draft.value).trim();
    if (!text || sending.value || awaitingReply.value) return;

    if (!conversationId.value) {
        await bootstrap(true);
    }
    if (!conversationId.value) return;

    sending.value = true;
    chat.value = [
        ...chat.value,
        {
            role: 'user',
            content: text,
            channel: 'web',
            created_at: new Date().toISOString(),
        },
    ];
    if (!textOverride) draft.value = '';
    await scrollBottom();

    try {
        const res = await fetch('/ai-employee/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                message: text,
                conversation_id: conversationId.value,
            }),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok && res.status !== 202) {
            throw new Error(data.message ?? 'Request failed');
        }

        conversationId.value = data.conversation_id ?? conversationId.value;

        if (data.user_message?.id) {
            for (let i = chat.value.length - 1; i >= 0; i -= 1) {
                const row = chat.value[i];
                if (row?.role === 'user' && !row.id) {
                    chat.value[i] = {
                        ...row,
                        id: Number(data.user_message.id),
                        created_at: data.user_message.created_at ?? row.created_at,
                    };
                    chat.value = [...chat.value];
                    break;
                }
            }
        }

        applyApprovals(data);

        const queued =
            res.status === 202 ||
            data.status === 'queued' ||
            data.pending === true;
        const afterId = Number(data.after_message_id ?? data.user_message?.id ?? 0);

        if (queued && afterId > 0) {
            startPolling(afterId);
            return;
        }

        const reply = typeof data.reply === 'string' ? data.reply.trim() : '';
        // Never surface empty / placeholder replies from a sync response.
        if (reply !== '' && !/^\.{1,3}$|^…$/.test(reply)) {
            chat.value = [
                ...chat.value,
                {
                    role: 'assistant',
                    content: reply,
                    channel: 'web',
                    created_at: new Date().toISOString(),
                },
            ];
        }

        sending.value = false;
        awaitingReply.value = false;
        await scrollBottom();
    } catch {
        stopPolling();
        sending.value = false;
        chat.value = [
            ...chat.value,
            {
                role: 'assistant',
                content: 'Something went wrong. Check AI provider config and try again.',
                channel: 'web',
                created_at: new Date().toISOString(),
            },
        ];
        await scrollBottom();
    }
}

watch(
    () => chat.value.length,
    (next, prev) => {
        if (preserveScrollOnPrepend.value || next <= prev) return;
        void scrollBottom();
    },
);

watch([sending, awaitingReply], ([isSending, isAwaiting]) => {
    if (isSending || isAwaiting) {
        void scrollBottom();
    }
});

function formatMessageHtml(content: string) {
    return formatChatMarkdown(content);
}

function approvalApproveLabel(approval: ApprovalLite): string {
    return approval.actions?.approve_label || 'Launch';
}

function approvalRejectLabel(approval: ApprovalLite): string {
    return approval.actions?.reject_label || 'Reject';
}

function approvalSummary(approval: ApprovalLite): string {
    return approval.actions?.summary || 'Action ready to confirm';
}

async function decideApproval(approvalId: number, decision: 'approve' | 'reject', draftText?: string) {
    if (decidingApprovalId.value !== null || sending.value || awaitingReply.value) {
        return;
    }

    decidingApprovalId.value = approvalId;

    try {
        const res = await fetch('/ai-employee/approvals/decide', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                approval_id: approvalId,
                decision,
                draft_text: draftText,
            }),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message ?? 'Decision failed');
        }

        applyApprovals(data);

        if (typeof data.reply === 'string' && data.reply.trim() !== '') {
            chat.value = [
                ...chat.value,
                {
                    role: 'assistant',
                    content: data.reply,
                    channel: 'web',
                    created_at: new Date().toISOString(),
                },
            ];
            await scrollBottom();
        }
    } catch {
        chat.value = [
            ...chat.value,
            {
                role: 'assistant',
                content: 'Could not complete that action. Open Command Center and try again.',
                channel: 'web',
                created_at: new Date().toISOString(),
            },
        ];
        await scrollBottom();
    } finally {
        decidingApprovalId.value = null;
    }
}

async function clearChat(): Promise<boolean> {
    if (clearingChat.value || sending.value || awaitingReply.value) {
        return false;
    }

    const confirmed = window.confirm(
        'Clear chat and start a fresh thread?\n\n'
            + 'This archives the current conversation (web + WhatsApp share it). '
            + 'Pending Launch items stay in Review & Launch. Old messages are kept in the archive, not deleted.',
    );

    if (!confirmed) {
        return false;
    }

    clearingChat.value = true;
    stopPolling();

    try {
        const res = await fetch('/ai-employee/chat/clear', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message ?? 'Could not clear chat');
        }

        conversationId.value = data.conversation_id ?? null;
        hasOlderMessages.value = Boolean(data.has_older_messages);
        applyApprovals(data);

        const messages = Array.isArray(data.messages) ? data.messages : [];
        chat.value = messages.length
            ? [...messages]
            : [
                  {
                      role: 'assistant',
                      content: `Hi — I'm ${settings.value.employee_name}, your SociFusion Command Center.\nFresh thread started.`,
                  },
              ];

        draft.value = '';
        bootstrapped.value = true;
        await scrollBottom();
        return true;
    } catch {
        chat.value = [
            ...chat.value,
            {
                role: 'assistant',
                content: 'Could not clear the chat. Try again in a moment.',
                channel: 'web',
                created_at: new Date().toISOString(),
            },
        ];
        await scrollBottom();
        return false;
    } finally {
        clearingChat.value = false;
        sending.value = false;
        awaitingReply.value = false;
    }
}

/**
 * Shared Command Center chat API (widget + full page).
 * Always returns the same module-level refs so navigations stay non-blocking.
 */
export function useCommandCenterChat() {
    return {
        chat,
        draft,
        conversationId,
        settings,
        pendingApprovalsCount,
        pendingApprovals,
        sending,
        awaitingReply,
        decidingApprovalId,
        clearingChat,
        bootstrapping,
        bootstrapped,
        hasOlderMessages,
        loadingOlder,
        scrollEl,
        bootstrap,
        hydrateFromPage,
        send,
        clearChat,
        decideApproval,
        approvalApproveLabel,
        approvalRejectLabel,
        approvalSummary,
        loadOlderMessages,
        onChatScroll,
        scrollBottom,
        stopPolling,
        formatMessageHtml,
        formatChatDateDivider,
        formatChatMessageTime,
        showChatDateDivider,
    };
}
