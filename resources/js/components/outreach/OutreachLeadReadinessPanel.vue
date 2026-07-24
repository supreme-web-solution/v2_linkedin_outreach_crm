<script setup lang="ts">
import { AlertTriangle, CheckCircle2, Loader2, Mail, Phone, RefreshCw, MessageCircle, AtSign } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import type { OutreachChannel, OutreachStep } from '@/components/outreach/types';

export interface LeadListRef {
    list_hash: string;
    list_src: 'aud' | 'sn' | 'csv';
    list_name?: string;
}

export interface ChannelReadiness {
    channel: string;
    label: string;
    ready: number;
    missing: number;
    total: number;
    percent: number;
    field_label: string;
    help: string;
    is_messaging: boolean;
}

export interface ReadinessPreview {
    total_leads: number;
    fully_ready: number;
    will_skip_any: number;
    required_channels: string[];
    channels: Record<string, ChannelReadiness>;
    email_fetch: {
        missing_email: number;
        fetchable: number;
        pending: number;
        can_batch_fetch: boolean;
        sn_only_hint: boolean;
        batches: Array<{ list_hash: string; audience_list_ids: number[]; count: number }>;
    };
    phone_fetch: {
        missing_phone: number;
        fetchable: number;
        pending: number;
        can_batch_fetch: boolean;
        batches: Array<{ list_hash: string; list_src: string; record_ids: number[]; count: number }>;
    };
    whatsapp_verify: {
        with_phone: number;
        verified: number;
        needs_verify: number;
        can_verify: boolean;
    };
    handle_resolve: {
        needs_resolve: number;
        can_resolve: boolean;
        channels: string[];
    };
    warnings: string[];
    can_launch: boolean;
    should_confirm_launch: boolean;
}

const props = defineProps<{
    leadLists: LeadListRef[];
    nodeModel: OutreachStep[];
    compact?: boolean;
    sidebar?: boolean;
}>();

const loading = ref(false);
const fetchingEmails = ref(false);
const fetchingPhones = ref(false);
const verifyingWhatsApp = ref(false);
const resolvingHandles = ref(false);
const error = ref<string | null>(null);
const readiness = ref<ReadinessPreview | null>(null);
const fetchMessage = ref<string | null>(null);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const hasLists = computed(() => props.leadLists.length > 0);
const linkedinLists = computed(() => props.leadLists.filter((l) => l.list_src === 'aud' || l.list_src === 'sn'));
const importedLists = computed(() => props.leadLists.filter((l) => l.list_src === 'csv'));
const hasLinkedinLists = computed(() => linkedinLists.value.length > 0);
const hasImportedLists = computed(() => importedLists.value.length > 0);

const subtitleText = computed(() => {
    if (props.sidebar) {
        return 'Prepare contacts, then check readiness for your sequence.';
    }
    if (hasImportedLists.value && !hasLinkedinLists.value) {
        return 'Imported contacts → verify channels → launch when ready.';
    }
    if (hasLinkedinLists.value && !hasImportedLists.value) {
        return 'LinkedIn lists → enrich contact info → launch when ready.';
    }
    return 'Add contacts → enrich missing info → launch when ready.';
});

const contactPrepSteps = computed(() => {
    const steps: string[] = [];

    if (hasImportedLists.value) {
        steps.push('Imported spreadsheet — your contacts (phone, email, social handles).');
    }
    if (hasLinkedinLists.value) {
        steps.push(
            hasImportedLists.value
                ? 'LinkedIn lists — optionally fetch email/phone from profiles.'
                : 'LinkedIn lists — fetch email/phone from profiles.',
        );
    }
    steps.push('Verify WhatsApp — confirm phone numbers (required before WhatsApp steps).');
    steps.push('Resolve handles — IG / Telegram / X from spreadsheet columns.');

    return steps;
});
const channelRows = computed(() => {
    if (!readiness.value) return [];
    return props.nodeModel.length
        ? readiness.value.required_channels.map((ch) => readiness.value!.channels[ch]).filter(Boolean)
        : [];
});

const needsContactPrep = computed(() => {
    if (!readiness.value) return false;
    const r = readiness.value;
    return (
        r.email_fetch.can_batch_fetch ||
        r.phone_fetch.can_batch_fetch ||
        r.whatsapp_verify.can_verify ||
        r.handle_resolve.can_resolve
    );
});

function xsrf(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function leadListsPayload() {
    return props.leadLists.map((l) => ({ list_hash: l.list_hash, list_src: l.list_src }));
}

function linkedinListsPayload() {
    return linkedinLists.value.map((l) => ({ list_hash: l.list_hash, list_src: l.list_src }));
}

async function loadReadiness() {
    if (typeof document === 'undefined' || !hasLists.value) {
        if (!hasLists.value) {
            readiness.value = null;
        }
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const res = await fetch('/outreach/readiness-preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                lead_lists: leadListsPayload(),
                node_model: props.nodeModel,
            }),
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message || 'Could not load lead readiness.');
        }
        readiness.value = data.readiness as ReadinessPreview;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Could not load lead readiness.';
        readiness.value = null;
    } finally {
        loading.value = false;
    }
}

async function fetchEmails() {
    if (!readiness.value?.email_fetch.can_batch_fetch) return;

    fetchingEmails.value = true;
    fetchMessage.value = null;
    error.value = null;

    try {
        let queued = 0;
        for (const batch of readiness.value.email_fetch.batches) {
            for (let i = 0; i < batch.audience_list_ids.length; i += 50) {
                const chunk = batch.audience_list_ids.slice(i, i + 50);
                const res = await fetch(`/leads/${batch.list_hash}/fetch-email-batch?src=aud`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': xsrf(),
                    },
                    body: JSON.stringify({ audience_list_ids: chunk }),
                });
                const data = await res.json();
                if (!res.ok) {
                    throw new Error(data.message || 'Email fetch failed.');
                }
                queued += chunk.length;
            }
        }
        fetchMessage.value = `Queued email fetch for ${queued} profile(s). Refreshing automatically…`;
        startPolling();
        await loadReadiness();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Email fetch failed.';
    } finally {
        fetchingEmails.value = false;
    }
}

async function fetchPhones() {
    if (!readiness.value?.phone_fetch.can_batch_fetch) return;

    fetchingPhones.value = true;
    fetchMessage.value = null;
    error.value = null;

    try {
        const res = await fetch('/outreach/enrich/fetch-phones', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ lead_lists: linkedinListsPayload() }),
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message || 'Phone fetch failed.');
        }
        fetchMessage.value = data.message || 'Phone fetch queued.';
        startPolling();
        await loadReadiness();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Phone fetch failed.';
    } finally {
        fetchingPhones.value = false;
    }
}

async function verifyWhatsApp() {
    if (!readiness.value?.whatsapp_verify.can_verify) return;

    verifyingWhatsApp.value = true;
    fetchMessage.value = null;
    error.value = null;

    try {
        const res = await fetch('/outreach/enrich/verify-whatsapp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ lead_lists: leadListsPayload() }),
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message || 'WhatsApp verification failed.');
        }
        fetchMessage.value = data.message;
        await loadReadiness();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'WhatsApp verification failed.';
    } finally {
        verifyingWhatsApp.value = false;
    }
}

async function resolveHandles() {
    if (!readiness.value?.handle_resolve.can_resolve) return;

    resolvingHandles.value = true;
    fetchMessage.value = null;
    error.value = null;

    try {
        const res = await fetch('/outreach/enrich/resolve-handles', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ lead_lists: leadListsPayload() }),
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message || 'Handle resolve failed.');
        }
        fetchMessage.value = data.message;
        await loadReadiness();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Handle resolve failed.';
    } finally {
        resolvingHandles.value = false;
    }
}

function startPolling() {
    stopPolling();
    pollTimer = setInterval(() => {
        const pending =
            (readiness.value?.email_fetch.pending ?? 0) + (readiness.value?.phone_fetch.pending ?? 0);
        if (pending > 0) {
            loadReadiness();
        } else {
            stopPolling();
        }
    }, 12000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

onMounted(loadReadiness);

watch(
    () => [props.leadLists, props.nodeModel],
    () => {
        loadReadiness();
    },
    { deep: true },
);

onUnmounted(stopPolling);

defineExpose({
    readiness,
    loadReadiness,
    launchConfirmMessage(): string | null {
        if (!readiness.value?.should_confirm_launch) return null;
        const r = readiness.value;
        return `${r.will_skip_any} of ${r.total_leads} leads are missing contact info for one or more steps in your sequence. Those steps will be skipped for those leads.\n\nOnly ${r.fully_ready} lead(s) are fully ready for every channel in this sequence.\n\nLaunch anyway?`;
    },
});
</script>

<template>
    <div v-if="hasLists" class="rounded-xl border border-border bg-white" :class="compact ? 'p-4' : sidebar ? 'p-4' : 'p-5'">
        <div class="mb-3 flex items-start justify-between gap-3">
            <div>
                <h2 v-if="!sidebar" class="text-sm font-semibold">Lead readiness</h2>
                <p class="text-xs text-muted-foreground" :class="sidebar ? '' : 'mt-0.5'">
                    {{ subtitleText }}
                </p>
            </div>
            <button
                type="button"
                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs hover:bg-muted"
                :disabled="loading"
                @click="loadReadiness"
            >
                <RefreshCw class="h-3 w-3" :class="loading ? 'animate-spin' : ''" />
                Refresh
            </button>
        </div>

        <div v-if="loading && !readiness" class="flex items-center gap-2 py-6 text-sm text-muted-foreground">
            <Loader2 class="h-4 w-4 animate-spin" /> Checking leads…
        </div>

        <p v-if="error" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ error }}</p>
        <p v-if="fetchMessage" class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">{{ fetchMessage }}</p>

        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-[11px] leading-relaxed text-slate-700">
            <p class="mb-1.5 font-semibold text-slate-900">Contact prep flow</p>
            <ol class="list-decimal space-y-0.5 pl-4">
                <li v-for="(step, idx) in contactPrepSteps" :key="idx">{{ step }}</li>
            </ol>
        </div>

        <template v-if="readiness">
            <div class="mb-4 grid gap-2" :class="sidebar ? 'grid-cols-1' : 'sm:grid-cols-3'">
                <div class="rounded-lg bg-muted/40 px-3 py-2">
                    <p class="text-[10px] uppercase text-muted-foreground">Total leads</p>
                    <p class="text-lg font-semibold">{{ readiness.total_leads }}</p>
                </div>
                <div class="rounded-lg bg-emerald-50 px-3 py-2">
                    <p class="text-[10px] uppercase text-emerald-700">Fully ready</p>
                    <p class="text-lg font-semibold text-emerald-900">{{ readiness.fully_ready }}</p>
                    <p class="mt-0.5 text-[10px] text-emerald-800/80">Verified for every step in your sequence</p>
                </div>
                <div class="rounded-lg px-3 py-2" :class="readiness.will_skip_any ? 'bg-amber-50' : 'bg-muted/40'">
                    <p class="text-[10px] uppercase" :class="readiness.will_skip_any ? 'text-amber-700' : 'text-muted-foreground'">May skip steps</p>
                    <p class="text-lg font-semibold" :class="readiness.will_skip_any ? 'text-amber-900' : ''">{{ readiness.will_skip_any }}</p>
                </div>
            </div>

            <div v-if="needsContactPrep" class="mb-4 space-y-3 rounded-lg border border-primary/20 bg-primary/5 p-3">
                <p class="text-xs font-semibold text-primary">Prepare contacts</p>

                <div v-if="readiness.email_fetch.can_batch_fetch && linkedinLists.length" class="rounded-lg border border-border bg-white p-3 shadow-sm">
                    <p class="text-[11px] leading-relaxed text-muted-foreground">
                        <Mail class="mr-1 inline h-3.5 w-3.5 align-text-bottom" />
                        {{ readiness.email_fetch.fetchable }} emails to fetch from LinkedIn profiles
                    </p>
                    <button
                        type="button"
                        class="mt-2.5 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-blue-700 bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 active:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="fetchingEmails"
                        @click="fetchEmails"
                    >
                        <Loader2 v-if="fetchingEmails" class="h-4 w-4 animate-spin" />
                        <Mail v-else class="h-4 w-4" />
                        {{ fetchingEmails ? 'Fetching emails…' : 'Fetch emails' }}
                    </button>
                </div>

                <div v-if="readiness.phone_fetch.can_batch_fetch && linkedinLists.length" class="rounded-lg border border-border bg-white p-3 shadow-sm">
                    <p class="text-[11px] leading-relaxed text-muted-foreground">
                        <Phone class="mr-1 inline h-3.5 w-3.5 align-text-bottom" />
                        {{ readiness.phone_fetch.fetchable }} phone numbers to fetch from LinkedIn profiles
                    </p>
                    <button
                        type="button"
                        class="mt-2.5 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-violet-700 bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700 active:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="fetchingPhones"
                        @click="fetchPhones"
                    >
                        <Loader2 v-if="fetchingPhones" class="h-4 w-4 animate-spin" />
                        <Phone v-else class="h-4 w-4" />
                        {{ fetchingPhones ? 'Fetching phones…' : 'Fetch phone numbers' }}
                    </button>
                </div>

                <div v-if="readiness.whatsapp_verify.can_verify" class="rounded-lg border border-border bg-white p-3 shadow-sm">
                    <p class="text-[11px] leading-relaxed text-muted-foreground">
                        <MessageCircle class="mr-1 inline h-3.5 w-3.5 align-text-bottom" />
                        {{ readiness.whatsapp_verify.needs_verify }} contacts to verify on WhatsApp
                    </p>
                    <button
                        type="button"
                        class="mt-2.5 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-green-700 bg-green-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-700 active:bg-green-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="verifyingWhatsApp"
                        @click="verifyWhatsApp"
                    >
                        <Loader2 v-if="verifyingWhatsApp" class="h-4 w-4 animate-spin" />
                        <MessageCircle v-else class="h-4 w-4" />
                        {{ verifyingWhatsApp ? 'Verifying WhatsApp…' : 'Verify WhatsApp' }}
                    </button>
                </div>

                <div v-if="readiness.handle_resolve.can_resolve" class="rounded-lg border border-border bg-white p-3 shadow-sm">
                    <p class="text-[11px] leading-relaxed text-muted-foreground">
                        <AtSign class="mr-1 inline h-3.5 w-3.5 align-text-bottom" />
                        {{ readiness.handle_resolve.needs_resolve }} social handles to resolve
                    </p>
                    <button
                        type="button"
                        class="mt-2.5 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-pink-700 bg-pink-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-pink-700 active:bg-pink-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="resolvingHandles"
                        @click="resolveHandles"
                    >
                        <Loader2 v-if="resolvingHandles" class="h-4 w-4 animate-spin" />
                        <AtSign v-else class="h-4 w-4" />
                        {{ resolvingHandles ? 'Resolving handles…' : 'Resolve handles' }}
                    </button>
                </div>
            </div>

            <div v-if="channelRows.length === 0" class="mb-3 rounded-lg border border-dashed border-border px-3 py-3 text-xs text-muted-foreground">
                Pick a template or build your sequence to see which channels need contact info.
            </div>

            <div v-else class="mb-4 space-y-3">
                <div v-for="row in channelRows" :key="row.channel" class="rounded-lg border border-border px-3 py-2.5">
                    <div class="mb-1.5 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 text-sm font-medium">
                            <OutreachChannelIcon :channel="row.channel as OutreachChannel" class="h-4 w-4" />
                            {{ row.label }}
                        </div>
                        <span class="text-xs tabular-nums text-muted-foreground">{{ row.ready }} / {{ row.total }}</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full transition-all"
                            :class="row.percent >= 80 ? 'bg-emerald-500' : row.percent >= 40 ? 'bg-amber-500' : 'bg-red-500'"
                            :style="{ width: `${row.percent}%` }"
                        />
                    </div>
                    <p class="mt-1.5 text-[11px] text-muted-foreground">{{ row.help }}</p>
                </div>
            </div>

            <div v-if="readiness.warnings.length" class="space-y-2">
                <div
                    v-for="(warning, idx) in readiness.warnings"
                    :key="idx"
                    class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950"
                >
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600" />
                    <span>{{ warning }}</span>
                </div>
            </div>

            <div
                v-if="readiness.fully_ready === readiness.total_leads && readiness.total_leads > 0 && channelRows.length"
                class="mt-3 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800"
            >
                <CheckCircle2 class="h-4 w-4" />
                All leads are ready for every step in this sequence.
            </div>
        </template>
    </div>
</template>
