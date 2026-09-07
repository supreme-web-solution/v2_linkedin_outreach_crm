<script setup lang="ts">
import { CheckCircle2, Loader2, Rocket, Send } from '@lucide/vue';
import AlexAvatar from '@/components/crm/AlexAvatar.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import WhatsAppCommandLinkPanel, { type WhatsAppCommandLink } from '@/components/crm/WhatsAppCommandLinkPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatChatMarkdown } from '@/lib/chatMarkdown';

type ChatLine = { role: 'assistant' | 'user'; content: string };
type GoalOption = { key: string; label: string };
type Connection = {
    key: string;
    label: string;
    connected: boolean;
    required: boolean;
    kind: string;
    phase?: string;
    highlight?: boolean;
};
type OnboardingStatus = {
    show: boolean;
    completed: boolean;
    step: string;
    phase?: string;
    goal: string | null;
    goal_label: string | null;
    starter_prompt: string | null;
    employee_name: string;
    goal_options: GoalOption[];
    connections: Connection[];
    whatsapp_command: { linked: boolean; configured: boolean };
    skip_whatsapp_command?: boolean;
    outreach_ready?: boolean;
    show_whatsapp_command?: boolean;
    can_open_command_center?: boolean;
    ready: boolean;
};

const props = defineProps<{
    onboarding: OnboardingStatus;
}>();

const open = ref(false);
const busy = ref(false);
const chat = ref<ChatLine[]>([]);
const draft = ref('');
const status = ref<OnboardingStatus>({ ...props.onboarding });
const scrollEl = ref<HTMLElement | null>(null);
const waLink = ref<WhatsAppCommandLink | null>(null);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const employeeName = computed(() => status.value.employee_name || 'Alex');
const showConnections = computed(() => status.value.goal && status.value.connections.length > 0);
const outreachConnections = computed(() =>
    status.value.connections.filter((c) => c.phase !== 'climax'),
);
const climaxConnections = computed(() =>
    status.value.connections.filter((c) => c.phase === 'climax'),
);
const connectedCount = computed(
    () => status.value.connections.filter((c) => c.connected).length,
);
const totalConnections = computed(() => status.value.connections.length);
const canOpenCommandCenter = computed(
    () => Boolean(status.value.can_open_command_center ?? status.value.ready),
);
const showSkipWhatsapp = computed(
    () =>
        Boolean(
            status.value.outreach_ready
            && !status.value.skip_whatsapp_command
            && !status.value.whatsapp_command?.linked
            && climaxConnections.value.length > 0,
        ),
);

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
    );
}

function connectionIconChannel(key: string): string {
    return key === 'whatsapp_command' ? 'whatsapp' : key;
}

async function refreshStatus(): Promise<void> {
    const res = await fetch('/onboarding/status', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        credentials: 'same-origin',
    });
    if (res.ok) {
        status.value = await res.json();
    }
}

async function scrollChat(): Promise<void> {
    await nextTick();
    scrollEl.value?.scrollTo({ top: scrollEl.value.scrollHeight, behavior: 'smooth' });
}

async function sendChat(message = draft.value): Promise<void> {
    const text = message.trim();
    if (!text || busy.value) return;

    chat.value.push({ role: 'user', content: text });
    draft.value = '';
    busy.value = true;
    await scrollChat();

    try {
        const res = await fetch('/onboarding/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ message: text }),
        });
        const data = await res.json();
        if (data.reply?.content) {
            chat.value.push({ role: 'assistant', content: data.reply.content });
        }
        if (data.status) {
            status.value = data.status;
            if (data.status.goal) {
                startPolling();
            }
        }
    } finally {
        busy.value = false;
        await scrollChat();
    }
}

async function pickGoal(key: string, label: string): Promise<void> {
    chat.value.push({ role: 'user', content: label });
    busy.value = true;
    await scrollChat();

    try {
        const res = await fetch('/onboarding/goal', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ goal: key }),
        });
        status.value = await res.json();
        chat.value.push({
            role: 'assistant',
            content: `Great choice — **${label}**.\n\nI'll only ask for the connections you need. Tap **Connect** below and I'll detect when you're done.`,
        });
        startPolling();
    } finally {
        busy.value = false;
        await scrollChat();
    }
}

async function connectChannel(conn: Connection): Promise<void> {
    busy.value = true;
    try {
        const res = await fetch(`/onboarding/connect/${conn.key}`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            credentials: 'same-origin',
        });
        const data = await res.json();

        if (data.kind === 'whatsapp_command') {
            waLink.value = {
                code: data.code,
                bot_number: data.bot_number,
                deep_link: data.deep_link,
                qr_svg: data.qr_svg,
                instructions: data.instructions,
                desktop_hint: data.desktop_hint,
                mobile_hint: data.mobile_hint,
            };
            chat.value.push({
                role: 'assistant',
                content: 'Use your **phone\'s WhatsApp app** — scan the QR or copy the number and code below. I\'ll detect when you\'re linked.',
            });
            startPolling();
            return;
        }

        if (data.redirect_url) {
            chat.value.push({
                role: 'assistant',
                content: `Opening **${conn.label}** in a new tab… come back here when done.`,
            });
            window.open(data.redirect_url, '_blank', 'noopener');
            startPolling();
        }
    } finally {
        busy.value = false;
        await scrollChat();
    }
}

async function skipWhatsappCommand(): Promise<void> {
    busy.value = true;
    try {
        const res = await fetch('/onboarding/skip-whatsapp', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        if (res.ok) {
            status.value = await res.json();
            chat.value.push({
                role: 'assistant',
                content: 'Got it — web Command Center only. Tap **Open Command Center** when you\'re ready.',
            });
        }
    } finally {
        busy.value = false;
        await scrollChat();
    }
}

function startPolling(): void {
    stopPolling();
    let climaxNudged = false;
    pollTimer = setInterval(async () => {
        const prevReady = status.value.ready;
        await refreshStatus();
        if (status.value.ready && !prevReady) {
            chat.value.push({
                role: 'assistant',
                content: "You're all set — open Command Center and I'll continue from your goal.",
            });
            stopPolling();
            await scrollChat();
            return;
        }
        if (
            !climaxNudged
            && status.value.outreach_ready
            && status.value.phase === 'link_whatsapp'
            && !status.value.whatsapp_command?.linked
        ) {
            climaxNudged = true;
            chat.value.push({
                role: 'assistant',
                content: 'Outreach is connected. Last step: link **WhatsApp** to control me from your phone — or skip if you only want the web app.',
            });
            await scrollChat();
        }
    }, 4000);
}

function stopPolling(): void {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function finish(): Promise<void> {
    busy.value = true;
    try {
        await fetch('/onboarding/complete', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        open.value = false;
        const prompt = status.value.starter_prompt ?? '';
        window.location.href = prompt
            ? `/ai-employee?starter=${encodeURIComponent(prompt)}`
            : '/ai-employee';
    } finally {
        busy.value = false;
    }
}

function onOpenChange(next: boolean): void {
    if (next) {
        open.value = true;
    }
}

function blockOutsideClose(event: Event): void {
    event.preventDefault();
}

async function dismiss(): Promise<void> {
    open.value = false;
    stopPolling();
    await fetch('/onboarding/dismiss', {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        credentials: 'same-origin',
    });
}

onMounted(async () => {
    if (!props.onboarding.show && props.onboarding.completed) return;
    open.value = true;
    await refreshStatus();

    chat.value = [
        {
            role: 'assistant',
            content: status.value.goal
                ? `Welcome back — we're setting up **${status.value.goal_label ?? 'your goal'}**. Connect what's below and I'll keep watch.`
                : `Hey 👋 I'm **${employeeName.value}**, your AI Sales Employee.\n\nWhat should we work on? Say it in plain English — e.g. *run a WhatsApp campaign*, *book meetings*, *find prospects* — or tap a quick pick.`,
        },
    ];

    if (status.value.goal) {
        startPolling();
    }

    const params = new URLSearchParams(window.location.search);
    if (params.get('onboarding') === '1') {
        if (params.get('connected')) {
            chat.value.push({
                role: 'assistant',
                content: 'Nice — I detected your new connection. Keep going or open Command Center when ready.',
            });
        }
        window.history.replaceState({}, '', '/dashboard');
    }
});

onBeforeUnmount(() => stopPolling());

watch(
    () => props.onboarding.show,
    (show) => {
        if (show && !props.onboarding.completed) {
            open.value = true;
        }
    },
);

watch(open, (v) => {
    if (!v) stopPolling();
});
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            :show-close-button="false"
            class="flex h-[min(760px,92dvh)] w-[min(640px,calc(100vw-1.5rem))] max-w-none flex-col gap-0 overflow-hidden rounded-2xl border-0 p-0 shadow-2xl sm:max-w-none"
            @pointer-down-outside="blockOutsideClose"
            @interact-outside="blockOutsideClose"
            @escape-key-down="blockOutsideClose"
        >
            <!-- Chat header -->
            <div class="relative shrink-0 overflow-hidden bg-gradient-to-br from-blue-600 via-blue-600 to-sky-500 px-5 py-5 text-white">
                <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,#ffffff12_1px,transparent_1px),linear-gradient(to_bottom,#ffffff12_1px,transparent_1px)] bg-[size:24px_24px]" />
                <div class="relative flex items-center gap-4">
                    <div class="relative">
                        <AlexAvatar size="lg" :alt="employeeName" online class="ring-2 ring-white/25" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <DialogTitle class="text-lg font-semibold tracking-tight text-white">
                            Meet {{ employeeName }}
                        </DialogTitle>
                        <DialogDescription class="mt-0.5 text-sm text-blue-100">
                            Your AI Sales Employee · online now
                        </DialogDescription>
                        <p class="mt-1 text-xs text-blue-100/90">
                            Chat to set up — I'll only connect what your goal needs
                        </p>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <div
                ref="scrollEl"
                class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-gradient-to-b from-slate-50 to-white px-5 py-5 dark:from-zinc-950 dark:to-zinc-900"
            >
                <div
                    v-for="(line, i) in chat"
                    :key="i"
                    class="flex gap-3"
                    :class="line.role === 'user' ? 'flex-row-reverse' : ''"
                >
                    <AlexAvatar
                        v-if="line.role === 'assistant'"
                        size="xs"
                        :alt="employeeName"
                        class="mt-1"
                    />
                    <div
                        v-else
                        class="mt-1 flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xs font-semibold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
                    >
                        You
                    </div>
                    <div
                        class="max-w-[78%] rounded-2xl px-4 py-3 text-[15px] leading-relaxed shadow-sm"
                        :class="
                            line.role === 'user'
                                ? 'rounded-tr-md bg-blue-600 text-white'
                                : 'rounded-tl-md border border-blue-100/80 bg-white text-foreground dark:border-zinc-800 dark:bg-zinc-900'
                        "
                    >
                        <span v-html="formatChatMarkdown(line.content)" />
                    </div>
                </div>

                <!-- Typing indicator -->
                <div v-if="busy" class="flex gap-3">
                    <AlexAvatar size="xs" :alt="employeeName" class="mt-1" />
                    <div class="flex items-center gap-1 rounded-2xl rounded-tl-md border bg-white px-4 py-3 shadow-sm dark:bg-zinc-900">
                        <span class="size-2 animate-bounce rounded-full bg-blue-400 [animation-delay:0ms]" />
                        <span class="size-2 animate-bounce rounded-full bg-blue-400 [animation-delay:150ms]" />
                        <span class="size-2 animate-bounce rounded-full bg-blue-400 [animation-delay:300ms]" />
                    </div>
                </div>

                <!-- Quick reply goals -->
                <div v-if="!status.goal && !busy" class="pl-11">
                    <p class="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Quick picks
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="g in status.goal_options"
                            :key="g.key"
                            type="button"
                            class="rounded-full border border-blue-200 bg-white px-4 py-2 text-sm font-medium text-blue-700 shadow-sm transition hover:border-blue-400 hover:bg-blue-50 hover:shadow disabled:opacity-50 dark:border-blue-900 dark:bg-zinc-900 dark:text-blue-300 dark:hover:bg-blue-950"
                            :disabled="busy"
                            @click="pickGoal(g.key, g.label)"
                        >
                            {{ g.label }}
                        </button>
                    </div>
                </div>

                <!-- Connection cards in chat flow -->
                <div v-if="showConnections" class="pl-11 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Setup checklist
                        </p>
                        <span
                            v-if="totalConnections > 0"
                            class="rounded-full bg-blue-100 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-300"
                        >
                            {{ connectedCount }}/{{ totalConnections }}
                        </span>
                    </div>

                    <div
                        v-for="conn in outreachConnections"
                        :key="conn.key"
                        class="flex items-center justify-between gap-3 rounded-xl border bg-white p-3.5 shadow-sm transition dark:bg-zinc-900"
                        :class="conn.connected ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900' : ''"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <OutreachChannelIcon
                                :channel="connectionIconChannel(conn.key)"
                                :size="24"
                                class="h-6 w-6 shrink-0"
                            />
                            <div class="min-w-0">
                                <div class="text-sm font-medium">{{ conn.label }}</div>
                                <div class="text-muted-foreground text-xs">
                                    {{ conn.required ? 'Required for your goal' : 'Optional backup' }}
                                </div>
                            </div>
                        </div>
                        <div class="shrink-0">
                            <span
                                v-if="conn.connected"
                                class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                            >
                                <CheckCircle2 class="size-3.5" /> Done
                            </span>
                            <Button
                                v-else
                                size="sm"
                                class="h-8 rounded-full px-4 text-xs"
                                :disabled="busy"
                                @click="connectChannel(conn)"
                            >
                                Connect
                            </Button>
                        </div>
                    </div>

                    <template v-if="climaxConnections.length > 0">
                        <div class="pt-1">
                            <p class="text-xs font-medium tracking-wide text-emerald-700 uppercase dark:text-emerald-400">
                                Final step — control Alex from your phone
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                Optional but recommended. Same Alex, same plans — reply LAUNCH from WhatsApp.
                            </p>
                        </div>

                        <div
                            v-for="conn in climaxConnections"
                            :key="conn.key"
                            class="flex items-center justify-between gap-3 rounded-xl border bg-white p-3.5 shadow-sm transition dark:bg-zinc-900"
                            :class="[
                                conn.connected
                                    ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900'
                                    : conn.highlight
                                      ? 'border-emerald-300 bg-emerald-50/80 ring-2 ring-emerald-200 dark:border-emerald-800 dark:bg-emerald-950/30 dark:ring-emerald-900'
                                      : 'border-dashed border-emerald-200 dark:border-emerald-900',
                            ]"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <OutreachChannelIcon
                                    channel="whatsapp"
                                    :size="24"
                                    class="h-6 w-6 shrink-0"
                                />
                                <div class="min-w-0">
                                    <div class="text-sm font-medium">{{ conn.label }}</div>
                                    <div class="text-muted-foreground text-xs">
                                        {{ conn.connected ? 'Linked — text me anytime' : 'Skip if you only want the web app' }}
                                    </div>
                                </div>
                            </div>
                            <div class="shrink-0">
                                <span
                                    v-if="conn.connected"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                                >
                                    <CheckCircle2 class="size-3.5" /> Done
                                </span>
                                <Button
                                    v-else
                                    size="sm"
                                    class="link-phone-beep h-8 rounded-full bg-emerald-600 px-4 text-xs hover:bg-emerald-700"
                                    :disabled="busy"
                                    @click="connectChannel(conn)"
                                >
                                    Link phone
                                </Button>
                            </div>
                        </div>

                        <Button
                            v-if="showSkipWhatsapp"
                            size="sm"
                            variant="ghost"
                            class="h-8 w-full rounded-full text-xs text-muted-foreground"
                            :disabled="busy"
                            @click="skipWhatsappCommand"
                        >
                            Skip WhatsApp control — web only
                        </Button>
                    </template>

                    <WhatsAppCommandLinkPanel v-if="waLink" :link="waLink" class="mt-1" />
                </div>
            </div>

            <!-- Composer -->
            <div class="shrink-0 border-t bg-white px-5 py-4 dark:bg-zinc-950">
                <form class="flex items-end gap-2" @submit.prevent="sendChat()">
                    <div class="relative min-w-0 flex-1">
                        <Input
                            v-model="draft"
                            class="h-12 rounded-2xl border-zinc-200 bg-zinc-50 pr-12 text-[15px] shadow-inner focus-visible:ring-blue-500 dark:bg-zinc-900"
                            placeholder="Message Alex…"
                            :disabled="busy"
                        />
                    </div>
                    <Button
                        type="submit"
                        size="icon"
                        class="size-12 shrink-0 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 shadow-md hover:from-blue-500 hover:to-blue-700"
                        :disabled="busy || !draft.trim()"
                    >
                        <Loader2 v-if="busy" class="size-5 animate-spin" />
                        <Send v-else class="size-5" />
                    </Button>
                </form>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <Button
                        v-if="canOpenCommandCenter"
                        size="sm"
                        class="h-9 flex-1 rounded-full bg-gradient-to-r from-blue-600 to-sky-500 text-xs font-semibold shadow-sm"
                        :disabled="busy"
                        @click="finish"
                    >
                        <Rocket class="mr-1.5 size-3.5" />
                        Open Command Center
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-9 rounded-full text-xs"
                        :disabled="busy"
                        @click="refreshStatus"
                    >
                        Refresh
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        class="h-9 rounded-full text-xs text-muted-foreground"
                        :disabled="busy"
                        @click="dismiss"
                    >
                        Skip setup
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
@keyframes link-phone-beep {
    0%,
    100% {
        box-shadow:
            0 0 0 0 rgb(5 150 105 / 0.55),
            0 1px 2px rgb(0 0 0 / 0.08);
        transform: scale(1);
    }

    50% {
        box-shadow:
            0 0 0 10px rgb(5 150 105 / 0),
            0 2px 8px rgb(5 150 105 / 0.35);
        transform: scale(1.03);
    }
}

.link-phone-beep {
    animation: link-phone-beep 2.4s ease-in-out infinite;
}

@media (prefers-reduced-motion: reduce) {
    .link-phone-beep {
        animation: none;
    }
}
</style>
