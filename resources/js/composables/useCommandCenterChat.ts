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

export function useCommandCenterChat() {
    const chat = ref<CommandCenterChatMessage[]>([]);
    const draft = ref('');
    const conversationId = ref<number | null>(null);
    const settings = ref<CommandCenterSettings>({
        enabled: true,
        kill_switch: false,
        employee_name: 'Alex',
    });
    const pendingApprovalsCount = ref(0);
    const sending = ref(false);
    const bootstrapping = ref(false);
    const bootstrapped = ref(false);
    const hasOlderMessages = ref(false);
    const loadingOlder = ref(false);
    const preserveScrollOnPrepend = ref(false);
    const scrollEl = ref<HTMLElement | null>(null);

    async function scrollBottom() {
        await nextTick();
        requestAnimationFrame(() => {
            const el = scrollEl.value;
            if (el) {
                el.scrollTop = el.scrollHeight;
            }
        });
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
            hasOlderMessages.value = Boolean(data.has_older_messages);

            const messages = Array.isArray(data.messages) ? data.messages : [];
            chat.value = messages.length
                ? [...messages]
                : [
                      {
                          role: 'assistant',
                          content: `Hi — I'm ${settings.value.employee_name}, your SociFusion Command Center.\nTell me what you want to accomplish (same thread as WhatsApp once linked).`,
                      },
                  ];

            bootstrapped.value = true;
            await scrollBottom();
        } finally {
            bootstrapping.value = false;
        }
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
        if (!text || sending.value || !conversationId.value) return;

        sending.value = true;
        chat.value.push({
            role: 'user',
            content: text,
            channel: 'web',
            created_at: new Date().toISOString(),
        });
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
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message ?? 'Request failed');

            conversationId.value = data.conversation_id ?? conversationId.value;
            chat.value.push({
                role: 'assistant',
                content: data.reply || '…',
                channel: 'web',
                created_at: new Date().toISOString(),
            });

            if (Array.isArray(data.pending_approvals)) {
                pendingApprovalsCount.value = data.pending_approvals.length;
            }
        } catch {
            chat.value.push({
                role: 'assistant',
                content: 'Something went wrong. Check AI provider config and try again.',
                created_at: new Date().toISOString(),
            });
        } finally {
            sending.value = false;
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

    watch(sending, (active) => {
        if (active) {
            void scrollBottom();
        }
    });

    function formatMessageHtml(content: string) {
        return formatChatMarkdown(content);
    }

    return {
        chat,
        draft,
        conversationId,
        settings,
        pendingApprovalsCount,
        sending,
        bootstrapping,
        bootstrapped,
        hasOlderMessages,
        loadingOlder,
        scrollEl,
        bootstrap,
        send,
        loadOlderMessages,
        onChatScroll,
        scrollBottom,
        formatMessageHtml,
        formatChatDateDivider,
        formatChatMessageTime,
        showChatDateDivider,
    };
}
