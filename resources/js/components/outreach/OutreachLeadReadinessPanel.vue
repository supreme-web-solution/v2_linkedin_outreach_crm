<script setup lang="ts">
import { AlertTriangle, CheckCircle2, Clock, Loader2, RefreshCw, Sparkles } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import EmailEnrichmentInfoTooltip from '@/components/crm/EmailEnrichmentInfoTooltip.vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { Button } from '@/components/ui/button';
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
    contact_prep: {
        batch_size: number;
        remaining_total: number;
        can_prepare: boolean;
        pending_async: number;
    };
    warnings: string[];
    can_launch: boolean;
    should_confirm_launch: boolean;
}

export interface EnrichmentLimits {
    email: {
        used: number;
        limit: number;
        remaining: number;
        in_flight?: number;
        effective_remaining?: number;
        unlimited: boolean;
        at_limit: boolean;
        percent: number;
    };
    pending_email_jobs: number;
    lookup_pace_seconds: { min: number; max: number };
    resets_at: string;
}

const props = defineProps<{
    leadLists: LeadListRef[];
    nodeModel: OutreachStep[];
    compact?: boolean;
    sidebar?: boolean;
    /** Shown beside the sequence canvas — shorter copy, no “go back” hints */
    embeddedInBuild?: boolean;
}>();

const loading = ref(false);
const preparingContacts = ref(false);
const error = ref<string | null>(null);
const readiness = ref<ReadinessPreview | null>(null);
const enrichmentLimits = ref<EnrichmentLimits | null>(null);
const fetchMessage = ref<string | null>(null);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const hasLists = computed(() => props.leadLists.length > 0);
const linkedinLists = computed(() => props.leadLists.filter((l) => l.list_src === 'aud' || l.list_src === 'sn'));
const importedLists = computed(() => props.leadLists.filter((l) => l.list_src === 'csv'));
const hasLinkedinLists = computed(() => linkedinLists.value.length > 0);
const hasImportedLists = computed(() => importedLists.value.length > 0);

const subtitleText = computed(() => {
    if (props.embeddedInBuild) {
        return 'Updates live as you add send steps on the canvas.';
    }
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
    const required = readiness.value?.required_channels ?? [];

    if (!required.length) {
        steps.push('Build your sequence — add send steps (Instagram, WhatsApp, email, etc.) to see what each contact needs.');
        return steps;
    }

    if (hasImportedLists.value) {
        steps.push('Imported spreadsheet — your contacts (phone, email, social handles).');
    }
    if (hasLinkedinLists.value && (required.includes('email') || required.includes('linkedin'))) {
        steps.push('LinkedIn lists — optionally pull email/phone/socials from profiles.');
    }
    if (required.includes('whatsapp')) {
        steps.push('WhatsApp — verify phone numbers before sending.');
    }
    if (required.some((ch) => ['instagram', 'telegram', 'twitter'].includes(ch))) {
        steps.push('Social DMs — resolve @handles before Instagram/Telegram/X sends.');
    }
    const batchSize = readiness.value?.contact_prep?.batch_size ?? 25;
    steps.push(`Prepare contacts — one batched click (up to ${batchSize} leads) for whatever your sequence needs.`);
    steps.push('Repeat until Fully ready matches your lead count, then launch.');

    return steps;
});
const channelRows = computed(() => {
    if (!readiness.value) return [];
    if (!readiness.value.required_channels.length) return [];
    return readiness.value.required_channels.map((ch) => readiness.value!.channels[ch]).filter(Boolean);
});

const hasSequenceChannels = computed(() => (readiness.value?.required_channels?.length ?? 0) > 0);

const needsContactPrep = computed(() => {
    if (!readiness.value) return false;
    if (readiness.value.contact_prep?.can_prepare) return true;
    if ((readiness.value.contact_prep?.pending_async ?? 0) > 0) return true;
    if ((readiness.value.handle_resolve?.needs_resolve ?? 0) > 0) return true;
    return false;
});

const prepBatchSize = computed(() => readiness.value?.contact_prep?.batch_size ?? 25);

const prepRemainingTotal = computed(() => readiness.value?.contact_prep?.remaining_total ?? 0);

const prepPendingAsync = computed(() => readiness.value?.contact_prep?.pending_async ?? 0);

const canRunPrepareBatch = computed(() => prepRemainingTotal.value > 0 && !preparingContacts.value);

const prepSummaryLines = computed(() => {
    if (!readiness.value) return [];
    const r = readiness.value;
    const required = r.required_channels;
    const lines: string[] = [];
    if (required.includes('email') && r.email_fetch.fetchable > 0) {
        lines.push(`${Math.min(r.email_fetch.fetchable, prepBatchSize.value)} email lookup(s) from LinkedIn (queued)`);
    }
    if ((required.includes('email') || required.includes('whatsapp')) && r.phone_fetch.fetchable > 0) {
        lines.push(`${Math.min(r.phone_fetch.fetchable, prepBatchSize.value)} phone lookup(s) from LinkedIn (queued)`);
    }
    if (required.includes('whatsapp') && r.whatsapp_verify.needs_verify > 0) {
        lines.push(`${Math.min(r.whatsapp_verify.needs_verify, prepBatchSize.value)} WhatsApp verify`);
    }
    if (r.handle_resolve.needs_resolve > 0) {
        const socialLabels = (r.handle_resolve.channels ?? []).map((c) => c.charAt(0).toUpperCase() + c.slice(1)).join('/');
        lines.push(`${Math.min(r.handle_resolve.needs_resolve, prepBatchSize.value)} ${socialLabels || 'social'} handle resolve`);
    }
    return lines;
});

const prepareButtonLabel = computed(() => {
    if (preparingContacts.value) return 'Preparing batch…';
    if (prepPendingAsync.value > 0 && prepRemainingTotal.value <= 0) {
        return 'Waiting for queued lookups…';
    }
    const remaining = prepRemainingTotal.value;
    if (remaining <= 0) return 'Contacts prepared';
    return `Prepare next batch (up to ${prepBatchSize.value})`;
});

const emailUsageLabel = computed(() => {
    if (!enrichmentLimits.value) return '';
    const { used, limit, in_flight = 0, remaining } = enrichmentLimits.value.email;
    const inProgress = in_flight > 0 ? ` (+${in_flight} in progress)` : '';
    return `${used}${inProgress} / ${limit} used · ${remaining} left today`;
});

function applyEnrichmentLimits(payload: EnrichmentLimits | null | undefined) {
    if (payload) {
        enrichmentLimits.value = payload;
    }
}

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
        applyEnrichmentLimits(data.enrichment_limits as EnrichmentLimits | undefined);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Could not load lead readiness.';
        readiness.value = null;
    } finally {
        loading.value = false;
    }
}

async function prepareContacts() {
    if (!canRunPrepareBatch.value) return;

    preparingContacts.value = true;
    fetchMessage.value = null;
    error.value = null;

    try {
        const res = await fetch('/outreach/enrich/prepare-contacts', {
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
        applyEnrichmentLimits(data.enrichment_limits as EnrichmentLimits | undefined);
        if (!res.ok) {
            throw new Error(data.message || 'Contact preparation failed.');
        }
        fetchMessage.value = data.message as string;
        if ((data.emails_queued ?? 0) > 0 || (data.phones_queued ?? 0) > 0) {
            startPolling();
        }
        await loadReadiness();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Contact preparation failed.';
        await loadReadiness();
    } finally {
        preparingContacts.value = false;
    }
}

function startPolling() {
    stopPolling();
    pollTimer = setInterval(async () => {
        const pendingReadiness =
            (readiness.value?.email_fetch.pending ?? 0)
            + (readiness.value?.phone_fetch.pending ?? 0)
            + (readiness.value?.contact_prep?.pending_async ?? 0);
        const pendingJobs = enrichmentLimits.value?.pending_email_jobs ?? 0;

        if (pendingReadiness > 0 || pendingJobs > 0) {
            await loadReadiness();
        } else {
            await loadReadiness();
            stopPolling();
        }
    }, 5000);
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

        <div
            v-if="!embeddedInBuild"
            class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-[11px] leading-relaxed text-slate-700"
        >
            <p class="mb-1.5 font-semibold text-slate-900">Contact prep flow</p>
            <ol class="list-decimal space-y-0.5 pl-4">
                <li v-for="(step, idx) in contactPrepSteps" :key="idx">{{ step }}</li>
            </ol>
        </div>

        <div
            v-else-if="!hasSequenceChannels"
            class="mb-4 rounded-lg border border-dashed border-primary/30 bg-primary/5 px-3 py-3 text-center"
        >
            <p class="text-xs font-semibold text-primary">Add a send step on the canvas</p>
            <p class="mt-1 text-[11px] leading-relaxed text-muted-foreground">
                Use <strong>+ Add step</strong> to add Instagram, WhatsApp, email, or LinkedIn. Contact prep for your leads shows up here immediately — no need to switch screens.
            </p>
        </div>

        <template v-if="readiness">
            <div class="mb-4 grid grid-cols-3 gap-2">
                <div class="min-w-0 rounded-lg bg-muted/40 px-3 py-2">
                    <p class="text-[10px] uppercase text-muted-foreground">Total leads</p>
                    <p class="text-lg font-semibold">{{ readiness.total_leads }}</p>
                </div>
                <div class="min-w-0 rounded-lg bg-emerald-50 px-3 py-2">
                    <p class="text-[10px] uppercase text-emerald-700">Fully ready</p>
                    <p class="text-lg font-semibold text-emerald-900">{{ readiness.fully_ready }}</p>
                    <p class="mt-0.5 text-[10px] leading-tight text-emerald-800/80">Verified for every step in your sequence</p>
                </div>
                <div class="min-w-0 rounded-lg px-3 py-2" :class="readiness.will_skip_any ? 'bg-amber-50' : 'bg-muted/40'">
                    <p class="text-[10px] uppercase" :class="readiness.will_skip_any ? 'text-amber-700' : 'text-muted-foreground'">May skip steps</p>
                    <p class="text-lg font-semibold" :class="readiness.will_skip_any ? 'text-amber-900' : ''">{{ readiness.will_skip_any }}</p>
                </div>
            </div>

            <div v-if="!hasSequenceChannels" class="mb-4 rounded-lg border border-dashed border-amber-200 bg-amber-50 px-3 py-2.5 text-[11px] leading-relaxed text-amber-900">
                Add send steps to your sequence (e.g. Instagram DM) — readiness only checks channels you actually use. LinkedIn is not required for imported CSV lists.
            </div>

            <div v-if="(needsContactPrep || prepPendingAsync > 0) && hasSequenceChannels" class="mb-4 space-y-3 rounded-lg border border-primary/20 bg-primary/5 p-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="text-xs font-semibold text-primary">Prepare contacts</p>
                        <p class="mt-0.5 text-[11px] leading-relaxed text-muted-foreground">
                            One batched click (up to {{ prepBatchSize }} leads) for your sequence:
                            <span v-if="readiness.required_channels.length">{{ readiness.required_channels.join(', ') }}</span>
                        </p>
                    </div>
                    <span
                        v-if="prepRemainingTotal > 0"
                        class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium tabular-nums text-primary"
                    >
                        {{ prepRemainingTotal }} remaining
                    </span>
                </div>

                <div
                    v-if="enrichmentLimits && hasLinkedinLists && readiness.required_channels.includes('email') && (readiness.email_fetch.can_batch_fetch || readiness.email_fetch.pending > 0)"
                    class="rounded-lg border border-blue-200/60 bg-blue-50/50 p-3 dark:border-blue-900/40 dark:bg-blue-950/20"
                >
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span class="inline-flex items-center gap-1.5 font-medium text-blue-900 dark:text-blue-200">
                            Daily email enrichment
                            <EmailEnrichmentInfoTooltip side="right" />
                        </span>
                        <span class="text-blue-800/80 dark:text-blue-300/80">
                            {{ emailUsageLabel }}
                        </span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-blue-200/50 dark:bg-blue-900/40">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-blue-500 to-blue-600 transition-all"
                            :style="{ width: `${Math.min(100, enrichmentLimits.email.percent)}%` }"
                        />
                    </div>
                    <p v-if="enrichmentLimits.pending_email_jobs > 0" class="mt-2 flex items-center gap-1.5 text-[11px] text-amber-700 dark:text-amber-400">
                        <Clock class="h-3 w-3" />
                        {{ enrichmentLimits.pending_email_jobs }} enrichment job(s) in progress…
                    </p>
                </div>

                <ul v-if="prepSummaryLines.length" class="space-y-1 rounded-lg border border-border bg-white px-3 py-2 text-[11px] text-muted-foreground dark:bg-card">
                    <li v-for="(line, idx) in prepSummaryLines" :key="idx" class="flex items-start gap-1.5">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-primary/60" />
                        <span>Next batch: {{ line }}</span>
                    </li>
                </ul>

                <p v-if="prepPendingAsync > 0" class="flex items-center gap-1.5 text-[11px] text-amber-700 dark:text-amber-400">
                    <Clock class="h-3 w-3" />
                    {{ prepPendingAsync }} LinkedIn lookup(s) still running in the background…
                </p>

                <Button
                    type="button"
                    class="w-full"
                    :disabled="!canRunPrepareBatch"
                    @click="prepareContacts"
                >
                    <Loader2 v-if="preparingContacts" class="h-4 w-4 animate-spin" />
                    <Sparkles v-else class="h-4 w-4" />
                    {{ prepareButtonLabel }}
                </Button>

                <p v-if="prepRemainingTotal > prepBatchSize" class="text-center text-[10px] text-muted-foreground">
                    {{ readiness.total_leads }} leads total — click again after each batch to avoid API overload.
                </p>
            </div>

            <div v-if="channelRows.length === 0 && hasSequenceChannels" class="mb-3 rounded-lg border border-dashed border-border px-3 py-3 text-xs text-muted-foreground">
                Channel readiness will appear here once your sequence includes send steps.
            </div>

            <div v-else-if="channelRows.length === 0 && !embeddedInBuild" class="mb-3 rounded-lg border border-dashed border-border px-3 py-3 text-xs text-muted-foreground">
                Add send steps to your sequence to see which contacts need email, phone, or social handle prep.
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
