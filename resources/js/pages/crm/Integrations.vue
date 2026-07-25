<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, ChevronDown, ExternalLink, Mail, RefreshCw, Trash2 } from '@lucide/vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { computed, nextTick, onMounted, ref } from 'vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Integrations', href: '/integrations' }] },
});

interface Account {
    id: number;
    status: string;
    live_status?: string | null;
    provider?: string;
    provider_account_id: string | null;
    connection_method: string;
    connected_at: string | null;
    email: string | null;
    unipile_account_id: string | null;
    last_synced_at: string | null;
    disconnect_reason?: string | null;
    message?: string | null;
}

interface EspIntegration {
    id: number;
    provider: string;
    enabled: boolean;
    created_at: string;
    has_api_key: boolean;
}

interface EspProvider {
    key: string;
    label: string;
    fields: string[];
    wired?: boolean;
}

interface ChannelRow {
    channel: string;
    label: string;
    connected: boolean;
    status: string;
    email?: string | null;
    account_name?: string | null;
    integration_account_id?: number | null;
}

const props = defineProps<{
    accounts: Account[];
    connectedChannels?: ChannelRow[];
    espIntegrations: EspIntegration[];
    hasOrg: boolean;
    connected: boolean;
    connectedChannel?: string | null;
    channelConnectionError?: string | null;
    connectionError: boolean;
    unipileConfigured: boolean;
    unipileWebhookCallbackUrl?: string | null;
    deliveryStats: { total: number; sent: number; failed: number };
    espProviders: EspProvider[];
}>();

const page = usePage();
const showEspForm = ref(false);
const linkedinAdvancedOpen = ref(false);

const linkedinChannel = computed(() => props.connectedChannels?.find((c) => c.channel === 'linkedin') ?? null);

const connectedPlatformCount = computed(() => props.connectedChannels?.filter((c) => c.connected).length ?? 0);
const totalPlatformCount = computed(() => props.connectedChannels?.length ?? 0);

const platformsConnectedLabel = computed(() => {
    const connected = connectedPlatformCount.value;
    const total = totalPlatformCount.value;
    if (connected === 0) return 'No channels connected yet';
    if (connected === total) return `All ${total} channels connected`;
    return `${connected} of ${total} channels connected`;
});

const csrfToken = computed(() => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta?.getAttribute('content') ?? '';
});

const sessionForm = useForm({
    li_at: '',
});

onMounted(() => {
    if (props.connectionError || (linkedinChannel.value && !linkedinChannel.value.connected)) {
        linkedinAdvancedOpen.value = true;
    }
});

const espForm = useForm({
    provider: 'mailchimp',
    api_key: '',
    api_base: '',
    audience_id: '',
    from_email: '',
    from_name: '',
    portal_id: '',
    enabled: true,
});

const statusColor = (s: string) => {
    if (s === 'active') return 'bg-green-500/10 text-green-600 border-green-200';
    if (s === 'disconnected' || s === 'error') return 'bg-red-500/10 text-red-500 border-red-200';
    return 'bg-muted text-muted-foreground border-border';
};

const disconnectedAccounts = () => props.accounts.filter((a) => a.live_status === 'disconnected' || a.status === 'disconnected');

const selectedProvider = () => props.espProviders.find((p) => p.key === espForm.provider);

const sessionReady = () => sessionForm.li_at.trim() !== '';

function openLinkedInAdvanced() {
    linkedinAdvancedOpen.value = true;
    nextTick(() => {
        document.getElementById('linkedin-advanced')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
}

function connectLinkedInFromGrid() {
    openLinkedInAdvanced();
}

function disconnectLinkedInFromGrid() {
    const accountId = linkedinChannel.value?.integration_account_id ?? props.accounts.find((a) => a.status === 'active')?.id;
    if (accountId) {
        disconnectUnipile(accountId);
    }
}

function handleChannelConnect(ch: ChannelRow) {
    if (ch.channel === 'linkedin') {
        connectLinkedInFromGrid();
        return;
    }
    connectChannel(ch.channel);
}

function handleChannelDisconnect(ch: ChannelRow) {
    if (ch.channel === 'linkedin') {
        disconnectLinkedInFromGrid();
        return;
    }
    disconnectChannel(ch.channel, ch.label);
}

function verifyUnipile() {
    router.post('/integrations/unipile/verify', {}, { preserveScroll: true });
}

function connectUnipile() {
    if (!sessionReady()) return;

    sessionForm.post('/integrations/unipile/cookie', {
        preserveScroll: true,
        onSuccess: () => sessionForm.reset('li_at'),
    });
}

function disconnectUnipile(id: number) {
    if (!confirm('Disconnect your LinkedIn connection?')) return;
    router.delete(`/integrations/unipile/${id}`, { preserveScroll: true });
}

function connectChannel(channel: string) {
    router.post(`/integrations/channels/${channel}/connect`, {}, { preserveScroll: true });
}

function disconnectChannel(channel: string, label: string) {
    if (!confirm(`Disconnect ${label}? Outreach campaigns using this channel will stop until you reconnect.`)) return;
    router.delete(`/integrations/channels/${channel}/disconnect`, { preserveScroll: true });
}

function channelStatusLabel(ch: ChannelRow) {
    if (ch.connected) {
        return ch.email || ch.account_name || 'Connected';
    }
    if (ch.status === 'disconnected') {
        return 'Disconnected — click Reconnect';
    }
    return 'Not connected';
}

const friendlyFlashError = computed(() => {
    const raw = String((page.props.flash as { error?: string })?.error ?? '').trim();
    if (!raw) return '';

    if (raw.length > 280
        || raw.includes('Unipile API error')
        || raw.includes('"schema"')
        || raw.includes('errors/')
        || raw.includes('HTTP 4')
        || raw.includes('HTTP 5')) {
        if (/subscription|payment|402|403/i.test(raw)) {
            return 'This integration requires an active subscription. Please contact your administrator.';
        }
        return 'Connection could not be completed. Please try again or contact your administrator.';
    }

    return raw;
});

function saveEsp() {
    espForm.post('/integrations/esp', {
        preserveScroll: true,
        onSuccess: () => {
            espForm.reset('api_key', 'api_base', 'audience_id', 'from_email', 'from_name', 'portal_id');
            showEspForm.value = false;
        },
    });
}

function toggleEsp(id: number) {
    router.post(`/integrations/esp/${id}/toggle`, {}, { preserveScroll: true });
}

function removeEsp(id: number, provider: string) {
    if (!confirm(`Remove ${provider} integration?`)) return;
    router.delete(`/integrations/esp/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Integrations" />

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Integrations</h1>
            <p class="text-sm text-muted-foreground">
                Connect LinkedIn for messaging, lead search, and profile enrichment. Add other channels for multi-channel outreach.
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium uppercase text-muted-foreground">Platforms connected</p>
                <p class="mt-1 text-2xl font-semibold">{{ connectedPlatformCount }}</p>
                <p class="text-xs text-muted-foreground">{{ platformsConnectedLabel }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium uppercase text-muted-foreground">ESP deliveries</p>
                <p class="mt-1 text-2xl font-semibold">{{ deliveryStats.total }}</p>
                <p class="text-xs text-muted-foreground">{{ deliveryStats.sent }} sent · {{ deliveryStats.failed }} failed</p>
            </div>
        </div>

        <div v-if="page.props.flash?.success" class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle2 class="h-4 w-4 shrink-0" />
            {{ page.props.flash.success }}
        </div>
        <div v-if="friendlyFlashError" class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle class="h-4 w-4 shrink-0" />
            {{ friendlyFlashError }}
        </div>
        <div v-if="connected" class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle2 class="h-4 w-4 shrink-0" />
            LinkedIn connected successfully. Refresh the browser extension to sync status.
        </div>
        <div v-if="connectedChannel && !page.props.flash?.success" class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle2 class="h-4 w-4 shrink-0" />
            {{ connectedChannels?.find(c => c.channel === connectedChannel)?.label ?? connectedChannel }} connected successfully.
        </div>
        <div v-if="connectionError" class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle class="h-4 w-4 shrink-0" />
            LinkedIn connection failed. Check your session cookie and try again.
        </div>
        <div v-if="channelConnectionError" class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle class="h-4 w-4 shrink-0" />
            {{ connectedChannels?.find(c => c.channel === channelConnectionError)?.label ?? channelConnectionError }} connection failed. Please try again.
        </div>
        <div v-if="disconnectedAccounts().length" class="flex items-center gap-3 rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800">
            <AlertCircle class="h-4 w-4 shrink-0" />
            LinkedIn is disconnected. Open <strong>LinkedIn advanced setup</strong> below to paste a fresh <code class="rounded bg-white/70 px-1">li_at</code> cookie, or use the browser extension on LinkedIn and click <strong>Detect &amp; save LinkedIn session</strong>.
        </div>

        <!-- Multi-channel outreach -->
        <section v-if="connectedChannels?.length" class="rounded-xl border border-border bg-card shadow-sm">
            <div class="border-b border-border px-4 py-3">
                <h2 class="text-sm font-semibold">Connected channels</h2>
                <p class="text-xs text-muted-foreground">Connect LinkedIn and other platforms for <Link href="/outreach" class="text-primary hover:underline">Multi-Channel Outreach</Link>, the unified inbox, and <Link href="/calls" class="text-primary hover:underline">Call Manager</Link> calendar sync.</p>
            </div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                <div
                    v-for="ch in connectedChannels"
                    :key="ch.channel"
                    class="flex items-center justify-between rounded-lg border border-border px-3 py-3"
                >
                    <div class="flex items-center gap-3">
                        <OutreachChannelIcon :channel="ch.channel" class="h-5 w-5" />
                        <div>
                            <p class="text-sm font-medium">{{ ch.label }}</p>
                            <p class="text-xs text-muted-foreground">{{ channelStatusLabel(ch) }}</p>
                            <p v-if="ch.channel === 'google_calendar' || ch.channel === 'outlook_calendar'" class="text-[11px] text-muted-foreground">Used by Call Manager to create events when you book calls.</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="!ch.connected"
                            type="button"
                            class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground"
                            @click="handleChannelConnect(ch)"
                        >
                            {{ ch.status === 'disconnected' ? 'Reconnect' : 'Connect' }}
                        </button>
                        <template v-else>
                            <button
                                type="button"
                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-muted/50"
                                @click="handleChannelConnect(ch)"
                            >
                                Reconnect
                            </button>
                            <button
                                type="button"
                                class="text-xs text-red-600 hover:underline"
                                @click="handleChannelDisconnect(ch)"
                            >
                                Disconnect
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </section>

        <!-- LinkedIn advanced (cookie / secure login) — collapsed by default -->
        <Collapsible v-model:open="linkedinAdvancedOpen">
            <section id="linkedin-advanced" class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <CollapsibleTrigger class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-muted/30">
                    <div class="flex min-w-0 items-center gap-3">
                        <OutreachChannelIcon channel="linkedin" :size="22" class="h-5 w-5 shrink-0" />
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-sm font-semibold">LinkedIn advanced setup</h2>
                                <span class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium uppercase text-muted-foreground">Advanced</span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Cookie paste, secure login, and session details — use Connect above or open here.
                            </p>
                        </div>
                    </div>
                    <ChevronDown
                        class="h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200"
                        :class="linkedinAdvancedOpen ? 'rotate-180' : ''"
                    />
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <div class="space-y-4 border-t border-border p-4">
                        <p class="text-sm text-muted-foreground">
                            Open LinkedIn in your browser while using the extension — it can read the <code class="rounded bg-muted px-1">li_at</code> cookie automatically.
                            You can also paste the cookie manually from DevTools → Application → Cookies → www.linkedin.com.
                        </p>

                        <label class="grid gap-1.5 text-sm">
                            <span class="font-medium">li_at cookie <span class="text-red-500">*</span></span>
                            <textarea
                                v-model="sessionForm.li_at"
                                rows="3"
                                placeholder="Paste your LinkedIn li_at cookie value"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 font-mono text-xs"
                                :class="{ 'border-red-300': sessionForm.errors.li_at }"
                            />
                            <span v-if="sessionForm.errors.li_at" class="text-xs text-red-600">{{ sessionForm.errors.li_at }}</span>
                        </label>

                        <div v-if="!unipileConfigured" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                            LinkedIn messaging is not available on this server yet. Contact your administrator if this persists.
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-md bg-[#0077b5] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#005885] disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!sessionReady() || sessionForm.processing || !unipileConfigured"
                                @click="connectUnipile"
                            >
                                Connect LinkedIn (Messaging)
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-border px-3 py-2 text-xs font-medium hover:bg-muted/50"
                                @click="verifyUnipile"
                            >
                                <RefreshCw class="h-3 w-3" /> Verify connection
                            </button>
                        </div>

                        <div class="rounded-lg border border-dashed border-border px-4 py-3">
                            <p class="text-xs text-muted-foreground">
                                Prefer signing in on LinkedIn's secure login page instead of pasting a cookie?
                            </p>
                            <form method="POST" action="/integrations/unipile/hosted-auth" class="mt-2">
                                <input type="hidden" name="_token" :value="csrfToken" />
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!unipileConfigured"
                                >
                                    <ExternalLink class="h-3 w-3" />
                                    Connect via secure login
                                </button>
                            </form>
                        </div>

                        <div v-if="accounts.length" class="overflow-hidden rounded-lg border border-border">
                            <div class="border-b border-border bg-muted/40 px-3 py-2">
                                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">LinkedIn sessions</p>
                            </div>
                            <table class="w-full text-sm">
                                <thead class="border-b border-border bg-muted/20">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Account</th>
                                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Method</th>
                                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                                        <th class="px-3 py-2 text-right font-medium text-muted-foreground">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="acc in accounts" :key="acc.id">
                                        <td class="px-3 py-3">
                                            <p class="font-medium">{{ acc.email ?? 'LinkedIn session' }}</p>
                                            <p v-if="acc.connected_at" class="text-[10px] text-muted-foreground">Connected {{ acc.connected_at }}</p>
                                        </td>
                                        <td class="px-3 py-3 capitalize text-xs">{{ acc.connection_method }}</td>
                                        <td class="px-3 py-3">
                                            <span class="rounded-full border px-2 py-0.5 text-xs capitalize" :class="statusColor(acc.live_status === 'disconnected' || acc.status === 'disconnected' ? 'disconnected' : acc.status)">
                                                {{ acc.live_status === 'disconnected' || acc.status === 'disconnected' ? 'disconnected' : acc.status }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-3 text-right">
                                            <button
                                                v-if="acc.status === 'active'"
                                                type="button"
                                                class="text-xs text-red-600 hover:underline"
                                                @click="disconnectUnipile(acc.id)"
                                            >
                                                Disconnect
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </CollapsibleContent>
            </section>
        </Collapsible>

        <section class="rounded-xl border border-border bg-card shadow-sm">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <div class="flex items-center gap-2">
                    <Mail class="h-4 w-4" />
                    <h2 class="text-sm font-semibold">Email service providers (ESP)</h2>
                </div>
                <button
                    v-if="hasOrg"
                    type="button"
                    class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-muted/50"
                    @click="showEspForm = !showEspForm"
                >
                    {{ showEspForm ? 'Cancel' : 'Add ESP' }}
                </button>
            </div>

            <div v-if="!hasOrg" class="p-6 text-center text-sm text-muted-foreground">
                Set up your workspace to configure ESP integrations.
            </div>

            <div v-else class="space-y-4 p-4">
                <form v-if="showEspForm" class="space-y-3 rounded-lg border border-primary/20 bg-primary/5 p-4" @submit.prevent="saveEsp">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm">
                            <span class="font-medium">Provider</span>
                            <select v-model="espForm.provider" class="rounded-lg border border-border bg-background px-3 py-2">
                                <option v-for="p in espProviders" :key="p.key" :value="p.key">{{ p.label }}</option>
                            </select>
                        </label>
                        <label class="grid gap-1 text-sm">
                            <span class="font-medium">API key</span>
                            <PasswordInput v-model="espForm.api_key" placeholder="Leave blank to keep existing" autocomplete="off" class="rounded-lg" />
                        </label>
                    </div>
                    <div v-if="selectedProvider()?.fields.includes('api_base')" class="grid gap-1 text-sm">
                        <span class="font-medium">API URL</span>
                        <input v-model="espForm.api_base" type="url" placeholder="https://youraccount.api-us1.com" class="rounded-lg border border-border bg-background px-3 py-2" />
                    </div>
                    <div v-if="selectedProvider()?.fields.includes('audience_id')" class="grid gap-1 text-sm">
                        <span class="font-medium">{{ espForm.provider === 'convertkit' ? 'Form ID' : espForm.provider === 'activecampaign' ? 'List ID' : 'Audience / List ID' }}</span>
                        <input v-model="espForm.audience_id" type="text" class="rounded-lg border border-border bg-background px-3 py-2" />
                    </div>
                    <div v-if="selectedProvider()?.fields.includes('from_email')" class="grid gap-3 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm">
                            <span class="font-medium">From email</span>
                            <input v-model="espForm.from_email" type="email" class="rounded-lg border border-border bg-background px-3 py-2" />
                        </label>
                        <label class="grid gap-1 text-sm">
                            <span class="font-medium">From name</span>
                            <input v-model="espForm.from_name" type="text" class="rounded-lg border border-border bg-background px-3 py-2" />
                        </label>
                    </div>
                    <div v-if="selectedProvider()?.fields.includes('portal_id')" class="grid gap-1 text-sm">
                        <span class="font-medium">Portal ID</span>
                        <input v-model="espForm.portal_id" type="text" class="rounded-lg border border-border bg-background px-3 py-2" />
                    </div>
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground" :disabled="espForm.processing">Save integration</button>
                </form>

                <div v-if="!espIntegrations.length && !showEspForm" class="py-4 text-center text-sm text-muted-foreground">
                    No ESP configured. Connect Mailchimp, SendGrid, HubSpot, Klaviyo, Brevo, ActiveCampaign, MailerLite, ConvertKit, or GetResponse to subscribe leads from the extension when keys and list IDs are valid.
                </div>

                <table v-if="espIntegrations.length" class="w-full text-sm">
                    <thead class="border-b border-border bg-muted/40">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Provider</th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">API key</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="esp in espIntegrations" :key="esp.id">
                            <td class="px-3 py-3 capitalize font-medium">{{ esp.provider }}</td>
                            <td class="px-3 py-3">
                                <span class="rounded-full border px-2 py-0.5 text-xs" :class="esp.enabled ? 'border-green-200 bg-green-50 text-green-700' : 'border-border text-muted-foreground'">
                                    {{ esp.enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-xs text-muted-foreground">{{ esp.has_api_key ? 'Configured' : 'Missing' }}</td>
                            <td class="px-3 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="inline-flex items-center gap-1 text-xs text-primary hover:underline" @click="toggleEsp(esp.id)">
                                        <RefreshCw class="h-3 w-3" /> {{ esp.enabled ? 'Disable' : 'Enable' }}
                                    </button>
                                    <button type="button" class="text-xs text-red-600 hover:underline" @click="removeEsp(esp.id, esp.provider)">
                                        <Trash2 class="inline h-3 w-3" /> Remove
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
