<script setup lang="ts">
import { CheckCircle2, Loader2, Paperclip, Rocket, Send, X } from '@lucide/vue';
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
    business_profile_complete?: boolean;
    conversion_assets_complete?: boolean;
    business_profile_summary?: string | null;
    conversion_assets?: { sales_page_url?: string | null; webinar_url?: string | null };
    ready: boolean;
    workspace_configured?: boolean;
    autonomy_level?: number;
    connections_complete?: boolean;
    required_progress?: { connected: number; total: number; complete: boolean; remaining?: string[] };
    composer_mode?: 'goal' | 'connect' | 'business' | 'conversion' | 'chat';
    assistant_prompt?: string;
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
const attachedFile = ref<File | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);
const salesPageUrl = ref('');
const webinarUrl = ref('');
const businessInputUnlocked = ref(false);
const conversionInputUnlocked = ref(false);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const BUSINESS_ASK =
    'Great — connections are set.\n\nTell me about **your business** — who you help, what you sell, and what makes you different.\n\n**One is enough** — paste a short description **or** drop your website link **or** tap **attach** for a PDF. You can also combine any of these — you don\'t need to do all three.';
const CONVERSION_ASK =
    'Perfect — I built your ICP.\n\nAdd your **sales page** and/or **webinar** link in the fields below — **one is enough**, or fill both. Tap **Continue** when ready. I only share these when a prospect shows real interest.';

const employeeName = computed(() => status.value.employee_name || 'Soci');
const showSetupChecklist = computed(
    () =>
        Boolean(status.value.goal)
        && status.value.connections.length > 0
        && status.value.composer_mode === 'connect',
);
const checklistConnections = computed(() =>
    status.value.connections.filter((c) => c.key !== 'whatsapp_command'),
);
const connectedCount = computed(
    () => status.value.required_progress?.connected
        ?? checklistConnections.value.filter((c) => c.connected).length,
);
const totalConnections = computed(
    () => status.value.required_progress?.total ?? checklistConnections.value.length,
);
const connectionsGateComplete = computed(() => {
    if (status.value.required_progress) {
        return status.value.required_progress.complete;
    }
    return totalConnections.value === 0 || connectedCount.value >= totalConnections.value;
});
const showConversionForm = computed(() => conversionInputUnlocked.value);
const canSubmitConversionForm = computed(() => {
    if (busy.value) return false;
    return salesPageUrl.value.trim() !== '' || webinarUrl.value.trim() !== '';
});
const showAttachButton = computed(() => businessInputUnlocked.value && !showConversionForm.value);
const attachBeep = computed(() => businessInputUnlocked.value && !attachedFile.value);
const composerDisabled = computed(() => busy.value || showConversionForm.value);
const composerPlaceholder = computed(() => {
    if ((status.value.composer_mode ?? 'goal') === 'goal') return 'Type your own goal…';
    if (businessInputUnlocked.value) {
        return 'Paste a description, website link, or attach a PDF — one is enough…';
    }
    return 'Message Soci…';
});
const canSendComposer = computed(() => {
    if (composerDisabled.value) return false;
    if (attachedFile.value) return true;
    return draft.value.trim() !== '';
});
const canOpenCommandCenter = computed(
    () => Boolean(status.value.can_open_command_center ?? status.value.ready),
);
const showSkipWhatsapp = computed(
    () =>
        Boolean(
            status.value.outreach_ready
            && !status.value.connections_complete
            && !status.value.skip_whatsapp_command
            && !status.value.whatsapp_command?.linked
            && status.value.connections.some((c) => c.key === 'whatsapp_command'),
        ),
);
const autonomyLabel = computed(() => {
    const map: Record<number, string> = {
        1: 'Copilot',
        2: 'Assisted',
        3: 'Autopilot',
        4: 'Autonomous',
    };
    return map[status.value.autonomy_level ?? 3] ?? 'Autopilot';
});

function syncComposerUnlocks(options: { announce?: boolean; prevMode?: string } = {}): void {
    const mode = status.value.composer_mode ?? 'goal';
    const prevMode = options.prevMode;

    if (mode === 'business' && status.value.connections_complete && connectionsGateComplete.value) {
        if (!businessInputUnlocked.value) {
            businessInputUnlocked.value = true;
            if (options.announce && prevMode !== 'business') {
                chat.value.push({ role: 'assistant', content: BUSINESS_ASK });
                void scrollChat();
            }
        }
    } else {
        businessInputUnlocked.value = false;
        if (mode !== 'business') {
            clearAttachedFile();
        }
    }

    if (mode === 'conversion') {
        if (!conversionInputUnlocked.value) {
            conversionInputUnlocked.value = true;
            if (options.announce && prevMode !== 'conversion') {
                chat.value.push({ role: 'assistant', content: CONVERSION_ASK });
                void scrollChat();
            }
        }
    } else {
        conversionInputUnlocked.value = false;
    }
}

function applyStatus(next: OnboardingStatus, options: { announce?: boolean; prevMode?: string } = {}): void {
    status.value = next;
    syncComposerUnlocks(options);
}
function workspaceConfiguredMessage(label: string): string {
    return `Great choice — **${label}**.\n\nI've configured your workspace on **${autonomyLabel.value}** — I can auto-send inbox replies and move maybe-later leads to nurture. Connect what's below and I'll detect when you're done.`;
}

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
    );
}

function connectionIconChannel(key: string): string {
    return key === 'whatsapp_command' ? 'whatsapp' : key;
}

async function refreshStatus(announce = true): Promise<void> {
    const prevMode = status.value.composer_mode;
    const res = await fetch('/onboarding/status', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        credentials: 'same-origin',
    });
    if (res.ok) {
        applyStatus(await res.json(), { announce, prevMode });
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
            const prevMode = status.value.composer_mode;
            applyStatus(data.status, { announce: true, prevMode });
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
        const data = await res.json();
        applyStatus(data, { announce: true, prevMode: 'goal' });
        chat.value.push({
            role: 'assistant',
            content: workspaceConfiguredMessage(label),
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

        if (!res.ok || data.kind === 'error') {
            const hint = typeof data.hint === 'string' && data.hint.trim() !== ''
                ? `\n\n${data.hint}`
                : '';
            chat.value.push({
                role: 'assistant',
                content: `Couldn't open **${conn.label}** connect right now. ${data.message ?? 'Unipile connection failed.'}${hint}`,
            });
            return;
        }

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
            // Same as Integrations: leave this page for Unipile hosted auth (same window).
            window.location.assign(data.redirect_url);
            return;
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
            const data = (await res.json()) as OnboardingStatus;
            const prevMode = status.value.composer_mode;
            if (data.composer_mode === 'business') {
                chat.value.push({
                    role: 'assistant',
                    content: 'Got it — we\'ll skip WhatsApp for now. You can link your phone anytime later.',
                });
            } else {
                chat.value.push({
                    role: 'assistant',
                    content: 'Got it — WhatsApp hidden for now. You can link your phone anytime later from settings.',
                });
            }
            applyStatus(data, { announce: data.composer_mode === 'business', prevMode });
        }
    } finally {
        busy.value = false;
        await scrollChat();
    }
}

function isLikelyUrl(text: string): boolean {
    const value = text.trim();
    if (value === '') return false;
    if (/^https?:\/\//i.test(value)) return true;
    return /^[\w-]+\.[\w.-]+(\/\S*)?$/i.test(value);
}

function normalizeUrl(text: string): string {
    const value = text.trim();
    if (/^https?:\/\//i.test(value)) return value;
    return `https://${value.replace(/^\/+/, '')}`;
}

function extractUrls(text: string): string[] {
    const matches = text.match(/https?:\/\/[^\s)\]"']+/gi) ?? [];
    return [...new Set(matches.map((url) => url.replace(/[.,;]+$/, '')))];
}

function onAttachedFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    attachedFile.value = input.files?.[0] ?? null;
}

function clearAttachedFile(): void {
    attachedFile.value = null;
    if (fileInputRef.value) fileInputRef.value.value = '';
}

function openFilePicker(): void {
    fileInputRef.value?.click();
}

function parseBusinessInput(text: string): { description: string; websiteUrl: string } {
    const trimmed = text.trim();
    if (trimmed === '') {
        return { description: '', websiteUrl: '' };
    }

    if (isLikelyUrl(trimmed)) {
        return { description: '', websiteUrl: normalizeUrl(trimmed) };
    }

    const urls = extractUrls(trimmed);
    if (urls.length === 1 && trimmed === urls[0]) {
        return { description: '', websiteUrl: urls[0] };
    }

    return {
        description: trimmed,
        websiteUrl: urls[0] ?? '',
    };
}

async function submitBusinessProfileFromComposer(text: string, file: File | null): Promise<void> {
    const { description, websiteUrl } = parseBusinessInput(text);
    if (!description && !websiteUrl && !file) {
        chat.value.push({
            role: 'assistant',
            content: 'Tell me about your business — paste a short description **or** your website link **or** tap **attach** for a PDF. One is enough; you can combine them too.',
        });
        await scrollChat();
        return;
    }

    busy.value = true;
    const userLine = description || websiteUrl || (file?.name ?? 'Uploaded business file');
    chat.value.push({ role: 'user', content: userLine });
    draft.value = '';
    await scrollChat();

    try {
        const form = new FormData();
        if (description) form.append('description', description);
        if (websiteUrl) form.append('website_url', websiteUrl);
        if (file) form.append('file', file);

        const res = await fetch('/onboarding/business-profile', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
            body: form,
        });
        const data = await res.json();
        if (!res.ok) {
            chat.value.push({
                role: 'assistant',
                content: data.message ?? 'Could not save your business profile. Try again.',
            });
            return;
        }

        if (data.status) {
            const prevMode = status.value.composer_mode;
            chat.value.push({
                role: 'assistant',
                content: data.message ?? 'Business profile saved.',
            });
            applyStatus(data.status, { announce: true, prevMode });
        } else {
            chat.value.push({
                role: 'assistant',
                content: data.message ?? 'Business profile saved.',
            });
        }
        clearAttachedFile();
        startPolling();
    } finally {
        busy.value = false;
        await scrollChat();
    }
}

async function submitConversionAssetsFromForm(): Promise<void> {
    const sales = salesPageUrl.value.trim() ? normalizeUrl(salesPageUrl.value.trim()) : '';
    const webinar = webinarUrl.value.trim() ? normalizeUrl(webinarUrl.value.trim()) : '';

    if (!sales && !webinar) {
        chat.value.push({
            role: 'assistant',
            content: 'Add at least one link — **sales page** or **webinar** — then tap **Continue**.',
        });
        await scrollChat();
        return;
    }

    busy.value = true;
    chat.value.push({
        role: 'user',
        content: [sales ? `Sales page: ${sales}` : null, webinar ? `Webinar: ${webinar}` : null]
            .filter(Boolean)
            .join('\n'),
    });
    await scrollChat();

    try {
        const res = await fetch('/onboarding/conversion-assets', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                sales_page_url: sales || null,
                webinar_url: webinar || null,
            }),
        });
        const data = await res.json();
        if (!res.ok) {
            chat.value.push({
                role: 'assistant',
                content: data.message ?? 'Could not save conversion links. Try again.',
            });
            return;
        }

        salesPageUrl.value = '';
        webinarUrl.value = '';

        if (data.status) {
            applyStatus(data.status, { announce: false, prevMode: status.value.composer_mode });
        }
        chat.value.push({ role: 'assistant', content: data.message ?? 'Conversion assets saved.' });
        startPolling();
    } finally {
        busy.value = false;
        await scrollChat();
    }
}

async function handleComposerSubmit(): Promise<void> {
    const text = draft.value.trim();
    const file = attachedFile.value;

    if (businessInputUnlocked.value) {
        await submitBusinessProfileFromComposer(text, file);
        return;
    }

    await sendChat(text);
}

function connectionHint(conn: Connection): string {
    if (conn.key === 'whatsapp_command') {
        return conn.connected ? 'Linked — text me anytime' : 'Optional — control Soci from your phone';
    }
    return conn.connected ? 'Connected' : 'Required before we talk about your business';
}

function connectedChannelLabels(): string[] {
    return checklistConnections.value.filter((c) => c.connected).map((c) => c.label);
}

function formatChannelList(labels: string[]): string {
    const bold = labels.map((label) => `**${label}**`);
    if (bold.length <= 1) return bold[0] ?? '';
    if (bold.length === 2) return `${bold[0]} and ${bold[1]}`;
    return `${bold.slice(0, -1).join(', ')}, and ${bold[bold.length - 1]}`;
}

function connectFollowUp(label: string, connected: boolean): string {
    if (!connected) {
        return `**${label}** connection finished on Unipile — if Done doesn't show yet, tap Refresh.`;
    }

    const progress = status.value.required_progress;
    const done = progress?.connected ?? connectedCount.value;
    const total = progress?.total ?? totalConnections.value;
    const remaining = progress?.remaining ?? checklistConnections.value.filter((c) => !c.connected).map((c) => c.label);

    if (!connectionsGateComplete.value) {
        const left = remaining.length > 0 ? remaining.join(', ') : 'the rest';
        return `**${label}** is connected. **${done} of ${total}** — connect ${left} before we talk about your business.`;
    }

    const labels = connectedChannelLabels();
    if (labels.length <= 1) {
        return `**${label}** is connected. That's the one this setup needs.`;
    }

    return `${formatChannelList(labels)} are connected.`;
}

function whatsappConnectLabel(conn: Connection): string {
    return conn.key === 'whatsapp_command' ? 'Link phone' : 'Connect';
}

function startPolling(): void {
    stopPolling();
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
    const params = new URLSearchParams(window.location.search);
    const returningFromConnect = params.get('onboarding') === '1';
    await refreshStatus(!returningFromConnect);
    const mode = status.value.composer_mode ?? 'goal';
    const deferBusinessAsk = returningFromConnect && mode === 'business' && connectionsGateComplete.value;
    let opening = `Hey 👋 I'm **${employeeName.value}**, your AI Sales Employee.\n\nWhat's your customer goal? Say it in plain English — e.g. *get more clients*, *find ideal customers*, *turn replies into sales* — or tap a quick pick.`;
    if (status.value.goal) {
        if (mode === 'connect' || (mode === 'business' && !connectionsGateComplete.value)) {
            opening = `Welcome back — we're setting up **${status.value.goal_label ?? 'your goal'}**.\n\nConnect every channel in the **${connectedCount.value}/${totalConnections.value}** checklist before we talk about your business. If that number is 1, that one channel is enough. WhatsApp phone control is optional.`;
        } else if (mode === 'conversion') {
            opening = CONVERSION_ASK;
        } else if (mode === 'business' && !deferBusinessAsk) {
            opening = BUSINESS_ASK;
        } else if (!deferBusinessAsk) {
            opening = `Welcome back — we're setting up **${status.value.goal_label ?? 'your goal'}**.`;
        } else {
            opening = '';
        }
    }

    chat.value = opening ? [{ role: 'assistant', content: opening }] : [];
    syncComposerUnlocks({ announce: false, prevMode: 'goal' });

    if (status.value.goal) {
        startPolling();
    }

    if (status.value.conversion_assets?.sales_page_url) {
        salesPageUrl.value = status.value.conversion_assets.sales_page_url;
    }
    if (status.value.conversion_assets?.webinar_url) {
        webinarUrl.value = status.value.conversion_assets.webinar_url;
    }

    if (params.get('onboarding') === '1') {
        const connectedKey = params.get('channel') || params.get('connected');
        const erroredKey = params.get('error') || params.get('channel_error');
        if (connectedKey && connectedKey !== '1') {
            await refreshStatus(false);
            const conn = status.value.connections.find((c) => c.key === connectedKey);
            const label = conn?.label ?? connectedKey;
            const isDone = Boolean(conn?.connected);
            chat.value.push({
                role: 'assistant',
                content: connectFollowUp(label, isDone),
            });
            if (connectionsGateComplete.value) {
                chat.value.push({ role: 'assistant', content: BUSINESS_ASK });
            }
            startPolling();
        } else if (params.get('connected') === '1') {
            await refreshStatus(false);
            const channel = params.get('channel');
            const conn = channel ? status.value.connections.find((c) => c.key === channel) : null;
            chat.value.push({
                role: 'assistant',
                content: conn?.connected
                    ? connectFollowUp(conn.label, true)
                    : 'Nice — connection saved. Keep going with the checklist until the number is complete.',
            });
            if (connectionsGateComplete.value) {
                chat.value.push({ role: 'assistant', content: BUSINESS_ASK });
            }
            startPolling();
        } else if (erroredKey && erroredKey !== '1') {
            chat.value.push({
                role: 'assistant',
                content: `**${erroredKey}** connection didn't finish. Tap Connect again when you're ready.`,
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
                    <p class="text-muted-foreground mt-2 text-xs">
                        Or type your own below — niche, channel, or a specific target audience.
                    </p>
                </div>

                <!-- Setup checklist (includes WhatsApp — comes after channels + business + assets) -->
                <div v-if="showSetupChecklist" class="pl-11 space-y-3">
                    <div
                        v-if="status.workspace_configured"
                        class="rounded-xl border border-blue-200 bg-blue-50/80 px-3.5 py-2.5 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100"
                    >
                        Workspace ready — **{{ autonomyLabel }}** mode with inbox reply + nurture auto-actions enabled.
                    </div>
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
                    <p v-if="totalConnections > 1" class="text-muted-foreground text-xs">
                        Connect all {{ totalConnections }} before the business step. WhatsApp phone control does not count.
                    </p>

                    <div
                        v-for="conn in status.connections"
                        :key="conn.key"
                        class="flex items-center justify-between gap-3 rounded-xl border bg-white p-3.5 shadow-sm transition dark:bg-zinc-900"
                        :class="[
                            conn.connected
                                ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900'
                                : conn.key === 'whatsapp_command'
                                  ? 'border-emerald-300 bg-emerald-50/80 dark:border-emerald-800 dark:bg-emerald-950/30'
                                  : '',
                        ]"
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
                                    {{ connectionHint(conn) }}
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
                                :class="
                                    conn.key === 'whatsapp_command'
                                    && !conn.connected
                                        ? 'bg-emerald-600 hover:bg-emerald-700'
                                        : ''
                                "
                                :disabled="busy"
                                @click="connectChannel(conn)"
                            >
                                {{ whatsappConnectLabel(conn) }}
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
                        Not now — hide WhatsApp for setup
                    </Button>

                    <WhatsAppCommandLinkPanel v-if="waLink" :link="waLink" class="mt-1" />
                </div>
            </div>

            <!-- Composer -->
            <div class="shrink-0 border-t bg-white px-5 py-4 dark:bg-zinc-950">
                <input
                    ref="fileInputRef"
                    type="file"
                    accept=".pdf,.txt,application/pdf,text/plain"
                    class="hidden"
                    :disabled="busy"
                    @change="onAttachedFileChange"
                />
                <div
                    v-if="attachedFile && !showConversionForm"
                    class="mb-2 flex items-center gap-2 rounded-xl border border-blue-100 bg-blue-50/60 px-3 py-2 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100"
                >
                    <Paperclip class="size-3.5 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">{{ attachedFile.name }}</span>
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground"
                        :disabled="busy"
                        @click="clearAttachedFile"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>

                <div v-if="showConversionForm" class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        Fill one or both — leave blank what you don't have yet.
                    </p>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Sales page</label>
                        <Input
                            v-model="salesPageUrl"
                            class="h-11 rounded-2xl border-zinc-200 bg-zinc-50 text-[15px] shadow-inner focus-visible:ring-blue-500 dark:bg-zinc-900"
                            placeholder="https://yoursite.com/sales (optional)"
                            :disabled="busy"
                            @keydown.enter.prevent="submitConversionAssetsFromForm()"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Webinar</label>
                        <Input
                            v-model="webinarUrl"
                            class="h-11 rounded-2xl border-zinc-200 bg-zinc-50 text-[15px] shadow-inner focus-visible:ring-blue-500 dark:bg-zinc-900"
                            placeholder="https://yoursite.com/webinar (optional)"
                            :disabled="busy"
                            @keydown.enter.prevent="submitConversionAssetsFromForm()"
                        />
                    </div>
                    <Button
                        type="button"
                        class="h-11 w-full rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-sm font-semibold shadow-md hover:from-blue-500 hover:to-blue-700"
                        :disabled="!canSubmitConversionForm"
                        @click="submitConversionAssetsFromForm()"
                    >
                        <Loader2 v-if="busy" class="mr-2 size-4 animate-spin" />
                        Continue
                    </Button>
                </div>

                <form v-else class="flex items-end gap-2" @submit.prevent="handleComposerSubmit()">
                    <Button
                        v-if="showAttachButton"
                        type="button"
                        size="icon"
                        variant="outline"
                        class="size-12 shrink-0 rounded-2xl border-zinc-200 bg-zinc-50 dark:bg-zinc-900"
                        :class="{ 'attach-beep': attachBeep }"
                        :disabled="composerDisabled"
                        :title="showBusinessProfile ? 'Attach PDF or text file' : 'Attach file'"
                        @click="openFilePicker"
                    >
                        <Paperclip class="size-5" />
                    </Button>
                    <div class="relative min-w-0 flex-1">
                        <Input
                            v-model="draft"
                            class="h-12 rounded-2xl border-zinc-200 bg-zinc-50 text-[15px] shadow-inner focus-visible:ring-blue-500 dark:bg-zinc-900"
                            :class="showAttachButton ? 'pr-4' : 'pr-12'"
                            :placeholder="composerPlaceholder"
                            :disabled="composerDisabled"
                        />
                    </div>
                    <Button
                        type="submit"
                        size="icon"
                        class="size-12 shrink-0 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 shadow-md hover:from-blue-500 hover:to-blue-700"
                        :disabled="!canSendComposer"
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

@keyframes attach-beep {
    0%,
    100% {
        box-shadow:
            0 0 0 0 rgb(59 130 246 / 0.45),
            inset 0 0 0 1px rgb(59 130 246 / 0.15);
    }

    50% {
        box-shadow:
            0 0 0 8px rgb(59 130 246 / 0),
            inset 0 0 0 1px rgb(59 130 246 / 0.35);
    }
}

.attach-beep {
    animation: attach-beep 2.2s ease-in-out infinite;
}

@media (prefers-reduced-motion: reduce) {
    .link-phone-beep,
    .attach-beep {
        animation: none;
    }
}
</style>
