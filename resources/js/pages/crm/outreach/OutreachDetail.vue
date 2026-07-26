<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { AlertCircle, Copy, Inbox, Info, Layers, Loader2, Pause, Play, Pencil, Rocket, Sparkles, Trash2, Users } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import OutreachLeadReadinessPanel from '@/components/outreach/OutreachLeadReadinessPanel.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { ConnectedChannel, OutreachStep } from '@/components/outreach/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Multi-Channel', href: '/outreach' }, { title: 'Detail' }] },
});

const props = defineProps<{
    campaign: {
        id: number;
        name: string;
        template_type: string;
        status: string;
        node_model: OutreachStep[];
        meta: Record<string, unknown> | null;
        outreach_leads_count: number;
        created_at: string;
    };
    leads: { data: Array<{ id: number; full_name: string | null; status: string; email: string | null; progress: { current_node_label: string | null; next_run_at: string | null } | null }> };
    attachedLists: Array<{ id: number; list_name: string; list_hash: string; list_src: 'aud' | 'sn' | 'csv'; lead_count: number }>;
    connectedChannels: ConnectedChannel[];
    inboxSummary?: {
        replied_leads_count: number;
        platforms: Array<{
            channel: string;
            label: string;
            color: string;
            threads_count: number;
            inbox_href: string;
            recent_threads: Array<{ id: number; prospect_name: string | null; last_message_at: string | null; href: string }>;
            settings: { ai_context: string; auto_reply_enabled: boolean; pause_on_reply: boolean };
            settings_update_url: string;
        }>;
    };
    aiConfigured?: boolean;
    stats?: {
        total_leads: number;
        by_status: { pending: number; running: number; replied: number; done: number; error: number; skipped: number };
        completion_rate: number;
        reply_rate: number;
        invite_accepted_count: number;
        invite_accepted_rate: number;
        steps_completed: number;
        steps_failed: number;
        actions_by_channel: Record<string, number>;
        funnel: Array<{
            node_key: number;
            label: string;
            type: string;
            channel: string | null;
            reached: number;
            completed: number;
            failed: number;
            waiting: number;
            skipped: number;
            conversion_rate: number;
        }>;
    };
}>();

const page = usePage();
const launching = ref(false);
const duplicating = ref(false);
const savingTemplate = ref(false);
const activityEvents = ref<Array<{ id: number; message: string; status: string; executed_at: string; lead_name?: string }>>([]);
let poll: ReturnType<typeof setInterval> | null = null;

const isRunning = computed(() => ['active', 'running'].includes(props.campaign.status));
const flashError = computed(() => (page.props.flash as { error?: string })?.error);
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);

type ChannelSettings = { ai_context: string; auto_reply_enabled: boolean; pause_on_reply: boolean };
const localChannelSettings = ref<Record<string, ChannelSettings>>({});
const savingChannel = ref<string | null>(null);

watch(
    () => props.inboxSummary?.platforms,
    (platforms) => {
        const next: Record<string, ChannelSettings> = {};
        for (const p of platforms ?? []) {
            next[p.channel] = { ...p.settings };
        }
        localChannelSettings.value = next;
    },
    { immediate: true },
);

function savePlatformSettings(platform: NonNullable<typeof props.inboxSummary>['platforms'][0]) {
    const payload = localChannelSettings.value[platform.channel];
    if (!payload) return;
    savingChannel.value = platform.channel;
    router.put(platform.settings_update_url, payload, {
        preserveScroll: true,
        onFinish: () => { savingChannel.value = null; },
    });
}

function flatten(nodes: OutreachStep[]): OutreachStep[] {
    const result: OutreachStep[] = [];
    for (const n of nodes) {
        if (n.type !== 'end') result.push(n);
        if (n.branches) {
            result.push(...flatten(n.branches.accepted || []), ...flatten(n.branches.not_accepted || []));
        }
    }
    return result;
}

const allSteps = computed(() => flatten(props.campaign.node_model ?? []));

async function fetchActivity() {
    const res = await fetch(`/outreach/${props.campaign.id}/activity`, { headers: { Accept: 'application/json' } });
    if (res.ok) {
        const json = await res.json();
        activityEvents.value = json.events ?? [];
    }
}

onMounted(() => {
    fetchActivity();
    if (isRunning.value) poll = setInterval(fetchActivity, 8000);
});
onUnmounted(() => { if (poll) clearInterval(poll); });

function launch() {
    launching.value = true;
    router.post(`/outreach/${props.campaign.id}/activate`, {}, { onFinish: () => { launching.value = false; } });
}

function toggleStatus() {
    const next = isRunning.value ? 'paused' : 'active';
    router.put(`/outreach/${props.campaign.id}`, { status: next }, { preserveScroll: true });
}

function confirmDelete() {
    if (!confirm(`Delete "${props.campaign.name}"?`)) return;
    router.delete(`/outreach/${props.campaign.id}`);
}

function duplicateCampaign() {
    duplicating.value = true;
    router.post(`/outreach/${props.campaign.id}/duplicate`, {}, {
        onFinish: () => { duplicating.value = false; },
    });
}

function saveAsTemplate() {
    const name = prompt('Template name', `Template: ${props.campaign.name}`);
    if (name === null) return;
    savingTemplate.value = true;
    router.post(`/outreach/${props.campaign.id}/save-template`, { name: name.trim() || undefined }, {
        onFinish: () => { savingTemplate.value = false; },
    });
}

const channelActionEntries = computed(() =>
    Object.entries(props.stats?.actions_by_channel ?? {}).sort((a, b) => b[1] - a[1]),
);
</script>

<template>
    <Head :title="campaign.name" />

    <div class="flex max-w-5xl flex-col gap-5 p-4">
        <div v-if="flashError" class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <AlertCircle class="mt-0.5 h-4 w-4" /> {{ flashError }}
        </div>
        <div v-if="flashSuccess" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ flashSuccess }}</div>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold">{{ campaign.name }}</h1>
                <p class="text-sm text-muted-foreground capitalize">{{ campaign.status }} · {{ campaign.outreach_leads_count }} leads</p>
                <span v-if="isRunning" class="mt-1 inline-flex items-center gap-1 text-xs text-green-600"><Loader2 class="h-3 w-3 animate-spin" /> Running</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <AppToolbarButton v-if="campaign.status === 'draft'" :disabled="launching" @click="launch">
                    <Rocket class="h-4 w-4" /> {{ launching ? 'Launching…' : 'Launch' }}
                </AppToolbarButton>
                <AppToolbarButton v-else :variant="isRunning ? 'amber' : 'success'" @click="toggleStatus">
                    <Pause v-if="isRunning" class="h-4 w-4" /><Play v-else class="h-4 w-4" />
                    {{ isRunning ? 'Pause' : 'Resume' }}
                </AppToolbarButton>
                <Button variant="violet" size="toolbar" as-child>
                    <Link :href="`/outreach/${campaign.id}/edit`"><Pencil class="h-4 w-4" /> Edit</Link>
                </Button>
                <AppToolbarButton :disabled="duplicating" @click="duplicateCampaign">
                    <Copy class="h-4 w-4" /> {{ duplicating ? 'Copying…' : 'Duplicate' }}
                </AppToolbarButton>
                <AppToolbarButton :disabled="savingTemplate" @click="saveAsTemplate">
                    <Layers class="h-4 w-4" /> {{ savingTemplate ? 'Saving…' : 'Save template' }}
                </AppToolbarButton>
                <AppToolbarButton variant="dangerGradient" @click="confirmDelete"><Trash2 class="h-4 w-4" /></AppToolbarButton>
            </div>
        </div>

        <OutreachLeadReadinessPanel
            v-if="attachedLists.length"
            compact
            :lead-lists="attachedLists.map(l => ({ list_hash: l.list_hash, list_src: l.list_src, list_name: l.list_name }))"
            :node-model="campaign.node_model ?? []"
        />

        <div v-if="stats && stats.total_leads > 0" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Total leads</p>
                <p class="mt-1 text-2xl font-semibold">{{ stats.total_leads }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Completion rate</p>
                <p class="mt-1 text-2xl font-semibold">{{ stats.completion_rate }}%</p>
                <p class="text-xs text-muted-foreground">{{ stats.by_status.done }} done · {{ stats.by_status.replied }} replied</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Reply rate</p>
                <p class="mt-1 text-2xl font-semibold">{{ stats.reply_rate }}%</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Invite accepted</p>
                <p class="mt-1 text-2xl font-semibold">{{ stats.invite_accepted_rate }}%</p>
                <p class="text-xs text-muted-foreground">{{ stats.invite_accepted_count }} accepted</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Steps executed</p>
                <p class="mt-1 text-2xl font-semibold">{{ stats.steps_completed }}</p>
                <p v-if="stats.steps_failed" class="text-xs text-red-600">{{ stats.steps_failed }} failed</p>
            </div>
        </div>

        <div v-if="stats?.funnel?.length" class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">Step funnel</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">How many leads reached each step in the sequence.</p>
            <div class="mt-4 space-y-2">
                <div
                    v-for="step in stats.funnel"
                    :key="step.node_key"
                    class="flex items-center gap-3 rounded-lg border border-border/60 px-3 py-2"
                >
                    <OutreachChannelIcon v-if="step.channel" :channel="step.channel" class="h-3.5 w-3.5 shrink-0" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-medium">{{ step.label }}</p>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-primary transition-all"
                                :style="{ width: `${Math.min(step.conversion_rate, 100)}%` }"
                            />
                        </div>
                    </div>
                    <div class="shrink-0 text-right text-[10px] text-muted-foreground">
                        <p class="font-semibold text-foreground">{{ step.reached }}</p>
                        <p>{{ step.conversion_rate }}%</p>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="channelActionEntries.length" class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">Actions by channel</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                <span
                    v-for="[channel, count] in channelActionEntries"
                    :key="channel"
                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                >
                    <OutreachChannelIcon :channel="channel" class="h-3.5 w-3.5" />
                    {{ count }}
                </span>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-semibold">Sequence ({{ allSteps.length }} steps)</h2>
                <div class="mt-3 flex flex-col gap-1.5">
                    <div v-for="step in allSteps" :key="step.key" class="flex items-center gap-2 rounded-lg bg-muted/30 px-2.5 py-2 text-xs">
                        <OutreachChannelIcon v-if="step.channel" :channel="step.channel" class="h-3.5 w-3.5" />
                        <span class="font-medium">{{ step.label }}</span>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-semibold">Activity</h2>
                <div v-if="activityEvents.length === 0" class="mt-4 text-sm text-muted-foreground">No activity yet.</div>
                <div v-else class="mt-3 max-h-64 space-y-2 overflow-y-auto">
                    <div v-for="event in activityEvents" :key="event.id" class="rounded-lg border px-3 py-2 text-xs">
                        <p>{{ event.message }}</p>
                        <p class="text-muted-foreground">{{ event.lead_name }} · {{ event.status }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="inboxSummary?.platforms?.length" class="rounded-xl border bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="flex items-center gap-2 text-sm font-semibold"><Inbox class="h-4 w-4" /> Inbox & replies</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ inboxSummary.replied_leads_count }} lead(s) replied · configure AI per platform below
                    </p>
                </div>
            </div>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div
                    v-for="platform in inboxSummary.platforms"
                    :key="platform.channel"
                    class="rounded-lg border border-border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: platform.color }" />
                            <span class="text-sm font-medium">{{ platform.label }}</span>
                        </div>
                        <Link :href="platform.inbox_href" class="text-xs text-primary hover:underline">Open inbox</Link>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">{{ platform.threads_count }} thread(s)</p>
                    <ul v-if="platform.recent_threads.length" class="mt-2 space-y-1">
                        <li v-for="thread in platform.recent_threads" :key="thread.id">
                            <Link :href="thread.href" class="text-xs text-muted-foreground hover:text-foreground">
                                {{ thread.prospect_name ?? 'Unknown' }}
                            </Link>
                        </li>
                    </ul>
                    <form class="mt-3 space-y-3 border-t border-border/60 pt-3" @submit.prevent="savePlatformSettings(platform)">
                        <textarea
                            v-model="localChannelSettings[platform.channel].ai_context"
                            rows="2"
                            class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-xs"
                            :placeholder="`${platform.label} AI context for this campaign`"
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
                                        When enabled, inbound replies on {{ platform.label }} get an AI draft using this campaign context, thread summary, and last 5 messages.
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                            <Switch
                                v-model="localChannelSettings[platform.channel].auto_reply_enabled"
                                class="shrink-0"
                            />
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
                                        Stops this lead's sequence when they reply on {{ platform.label }}. Other leads keep running.
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                            <Switch
                                v-model="localChannelSettings[platform.channel].pause_on_reply"
                                class="shrink-0"
                            />
                        </div>
                        <button type="submit" class="rounded-md bg-gradient-to-r from-blue-600 to-indigo-600 px-2.5 py-1 text-xs font-medium text-white disabled:opacity-50" :disabled="savingChannel === platform.channel">
                            Save {{ platform.label }} settings
                        </button>
                    </form>
                </div>
            </div>
            <p v-if="!aiConfigured" class="mt-3 text-xs text-amber-600">Add OPENAI_API_KEY to enable AI auto-replies on every inbox channel below.</p>
        </div>

        <div class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">Leads</h2>
            <table class="mt-3 w-full text-xs">
                <thead><tr class="border-b text-left text-muted-foreground"><th class="py-2">Name</th><th>Step</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="lead in leads.data" :key="lead.id" class="border-b border-border/50">
                        <td class="py-2">{{ lead.full_name ?? 'Unknown' }}</td>
                        <td>{{ lead.progress?.current_node_label ?? '—' }}</td>
                        <td class="capitalize">{{ lead.status }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
