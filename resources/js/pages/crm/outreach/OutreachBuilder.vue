<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Bookmark, Camera, ChevronRight, Copy, FileSpreadsheet, Info, Layers, Mail, MessageCircle, Plus, Rocket, Settings2, Sparkles, Trash2, Upload, Users, X } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import OutreachBuilderStepper from '@/components/outreach/OutreachBuilderStepper.vue';
import OutreachFlowCanvas from '@/components/outreach/OutreachFlowCanvas.vue';
import OutreachImportListPanel, { type ImportedListOption } from '@/components/outreach/OutreachImportListPanel.vue';
import OutreachLeadReadinessPanel from '@/components/outreach/OutreachLeadReadinessPanel.vue';
import OutreachChannelInboxSettingsPanel, { type ChannelInboxSettings } from '@/components/outreach/OutreachChannelInboxSettingsPanel.vue';
import OutreachStepPreviewChip from '@/components/outreach/OutreachStepPreviewChip.vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import ClientPagination from '@/components/crm/ClientPagination.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { stepChipLabel, type ConnectedChannel, type OutreachStep } from '@/components/outreach/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Multi-Channel', href: '/outreach' }, { title: 'Builder' }] },
});

interface LeadListOption {
    list_name: string;
    list_hash: string;
    total_leads: number;
    source: string;
    src: 'aud' | 'sn' | 'csv';
    type: string;
}

const props = defineProps<{
    templates: Record<string, { label: string; description: string; node_model: OutreachStep[]; saved?: boolean }>;
    channelRegistry: {
        channels: Record<string, { label: string; color: string }>;
        actions: Record<string, Array<{ key: string; label: string }>>;
        conditions?: Record<string, Array<{ key: string; label: string }>>;
    };
    connectedChannels: ConnectedChannel[];
    campaign?: {
        id: number;
        name: string;
        template_type: string;
        status: string;
        node_model: OutreachStep[];
        meta?: { channel_inbox?: Record<string, ChannelInboxSettings> } | null;
    } | null;
    availableLeadLists: LeadListOption[];
    attachedLists: Array<{ list_hash: string; list_src: string; list_name: string; lead_count?: number }>;
    initialStep?: string;
    aiConfigured?: boolean;
}>();

const INBOX_PLATFORMS = new Set(['linkedin', 'email', 'whatsapp', 'instagram', 'telegram', 'twitter']);

function defaultChannelSettings(): ChannelInboxSettings {
    return { ai_context: '', auto_reply_enabled: false, pause_on_reply: true };
}

const page = usePage();
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

type Phase = 'template' | 'leads' | 'build' | 'review';
const phase = ref<Phase>(props.campaign ? (['leads', 'build', 'review'].includes(props.initialStep ?? '') ? props.initialStep as Phase : 'build') : 'template');

const selectedType = ref(props.campaign?.template_type ?? '');
const campaignName = ref(props.campaign?.name ?? '');
const steps = ref<OutreachStep[]>(props.campaign?.node_model ? JSON.parse(JSON.stringify(props.campaign.node_model)) : []);
const selectedLists = ref([...props.attachedLists]);
const saving = ref(false);
const autoSaveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const errors = ref<Record<string, string>>({});
const reviewReadinessRef = ref<InstanceType<typeof OutreachLeadReadinessPanel> | null>(null);
const buildReadinessRef = ref<InstanceType<typeof OutreachLeadReadinessPanel> | null>(null);
const showMobilePrep = ref(false);
const extraImportLists = ref<LeadListOption[]>([]);
const importModalOpen = ref(false);
const leadSourceTab = ref<'imported' | 'linkedin'>('imported');

const allLeadLists = computed(() => {
    const seen = new Set<string>();
    const merged: LeadListOption[] = [];
    for (const list of [...props.availableLeadLists, ...extraImportLists.value]) {
        const key = `${list.src}:${list.list_hash}`;
        if (seen.has(key)) continue;
        seen.add(key);
        merged.push(list);
    }
    return merged;
});

const csvLeadLists = computed(() => allLeadLists.value.filter((l) => l.src === 'csv'));
const linkedinLeadLists = computed(() => allLeadLists.value.filter((l) => l.src !== 'csv'));

const LIST_PAGE_SIZE = 4;
const linkedinListPage = ref(1);
const csvListPage = ref(1);

const linkedinListTotalPages = computed(() =>
    Math.max(1, Math.ceil(linkedinLeadLists.value.length / LIST_PAGE_SIZE)),
);

const csvListTotalPages = computed(() =>
    Math.max(1, Math.ceil(csvLeadLists.value.length / LIST_PAGE_SIZE)),
);

const paginatedLinkedinLeadLists = computed(() => {
    const start = (linkedinListPage.value - 1) * LIST_PAGE_SIZE;
    return linkedinLeadLists.value.slice(start, start + LIST_PAGE_SIZE);
});

const paginatedCsvLeadLists = computed(() => {
    const start = (csvListPage.value - 1) * LIST_PAGE_SIZE;
    return csvLeadLists.value.slice(start, start + LIST_PAGE_SIZE);
});

watch(linkedinLeadLists, () => {
    linkedinListPage.value = 1;
});

watch(csvLeadLists, () => {
    csvListPage.value = 1;
});

const isEditing = computed(() => !!props.campaign);
const isLiveCampaign = computed(() =>
    isEditing.value && ['running', 'active'].includes(props.campaign?.status ?? ''),
);

const aiConfigured = computed(() => props.aiConfigured ?? false);

const templateIcons: Record<string, unknown> = {
    linkedin_only: Users,
    linkedin_email: Layers,
    linkedin_whatsapp: MessageCircle,
    multichannel: Layers,
    email_only: Mail,
    whatsapp_only: MessageCircle,
    social_dm: Camera,
    instagram_only: Camera,
    custom: Settings2,
};

function templateIcon(key: string) {
    if (key.startsWith('saved_')) return Bookmark;
    return templateIcons[key] ?? Settings2;
}

const builtInTemplates = computed(() =>
    Object.fromEntries(Object.entries(props.templates).filter(([, tpl]) => !tpl.saved)),
);

const savedTemplates = computed(() =>
    Object.fromEntries(Object.entries(props.templates).filter(([, tpl]) => tpl.saved)),
);

const selectedListsLeadCount = computed(() =>
    selectedLists.value.reduce((sum, list) => sum + (list.lead_count ?? 0), 0),
);

const buildPrepSummary = computed(() => {
    const r = buildReadinessRef.value?.readiness;
    if (!r || !requiredChannels.value.length) return null;
    return `${r.fully_ready} / ${r.total_leads} fully ready`;
});

const requiredChannels = computed(() => {
    const channels = new Set<string>();
    const sendActions = new Set(['send_message', 'send_email', 'send_invite']);
    const walk = (nodes: OutreachStep[]) => {
        for (const n of nodes) {
            if (n.type === 'action' && n.channel && n.action && sendActions.has(n.action)) {
                channels.add(n.channel);
            }
            if (n.branches) {
                walk(n.branches.accepted || []);
                walk(n.branches.not_accepted || []);
            }
        }
    };
    walk(steps.value);
    return [...channels];
});

const sequenceInboxChannels = computed(() => {
    const channels = new Set<string>();
    const walk = (nodes: OutreachStep[]) => {
        for (const n of nodes) {
            if ((n.type === 'action' || n.type === 'condition') && n.channel && INBOX_PLATFORMS.has(n.channel)) {
                channels.add(n.channel);
            }
            if (n.branches) {
                walk(n.branches.accepted || []);
                walk(n.branches.not_accepted || []);
            }
        }
    };
    walk(steps.value);
    return [...channels];
});

const inboxPlatformsForReview = computed(() =>
    sequenceInboxChannels.value.map((channel) => ({
        channel,
        label: props.channelRegistry.channels[channel]?.label ?? channel,
        color: props.channelRegistry.channels[channel]?.color ?? '#64748b',
    })),
);

const hasReplyConditionBranches = computed(() => {
    let found = false;
    const walk = (nodes: OutreachStep[]) => {
        for (const n of nodes) {
            if (
                n.type === 'condition'
                && ['message_replied', 'has_replied', 'email_replied', 'invite_accepted'].includes(n.condition ?? '')
            ) {
                found = true;
            }
            if (n.branches) {
                walk(n.branches.accepted || []);
                walk(n.branches.not_accepted || []);
            }
        }
    };
    walk(steps.value);
    return found;
});

const channelInboxSettings = ref<Record<string, ChannelInboxSettings>>({});

function syncChannelInboxSettings() {
    const stored = props.campaign?.meta?.channel_inbox ?? {};
    const next: Record<string, ChannelInboxSettings> = {};

    for (const channel of sequenceInboxChannels.value) {
        next[channel] = {
            ...defaultChannelSettings(),
            ...(stored[channel] ?? channelInboxSettings.value[channel] ?? {}),
        };
    }

    channelInboxSettings.value = next;
}

watch(sequenceInboxChannels, syncChannelInboxSettings, { immediate: true });

function pickTemplate(key: string) {
    selectedType.value = key;
    steps.value = JSON.parse(JSON.stringify(props.templates[key].node_model));
    if (!campaignName.value) campaignName.value = props.templates[key].label;
    phase.value = 'leads';
}

function duplicateSavedTemplate(key: string, event: Event) {
    event.stopPropagation();
    const templateId = key.replace(/^saved_/, '');
    if (!templateId) return;
    router.post(`/outreach/${templateId}/duplicate-template`, {}, { preserveScroll: true });
}

function deleteSavedTemplate(key: string, event: Event) {
    event.stopPropagation();
    const templateId = key.replace(/^saved_/, '');
    if (!templateId) return;
    const tpl = props.templates[key];
    if (!confirm(`Delete template "${tpl?.label ?? 'this template'}"?`)) return;
    router.delete(`/outreach/templates/${templateId}`, { preserveScroll: true });
}

function toggleList(list: LeadListOption) {
    const idx = selectedLists.value.findIndex((l) => l.list_hash === list.list_hash && l.list_src === list.src);
    if (idx >= 0) selectedLists.value.splice(idx, 1);
    else selectedLists.value.push({ list_hash: list.list_hash, list_src: list.src, list_name: list.list_name, lead_count: list.total_leads });
}

function isListSelected(list: LeadListOption) {
    return selectedLists.value.some((l) => l.list_hash === list.list_hash && l.list_src === list.src);
}

function removeSelectedList(listHash: string, listSrc: string) {
    selectedLists.value = selectedLists.value.filter(
        (l) => !(l.list_hash === listHash && l.list_src === listSrc),
    );
}

function onListImported(list: ImportedListOption) {
    extraImportLists.value.push(list);
    if (!selectedLists.value.some((l) => l.list_hash === list.list_hash && l.list_src === 'csv')) {
        selectedLists.value.push({ list_hash: list.list_hash, list_src: 'csv', list_name: list.list_name, lead_count: list.total_leads });
    }
    importModalOpen.value = false;
    leadSourceTab.value = 'imported';
}

function goToBuild() {
    if (!campaignName.value.trim()) { errors.value.name = 'Name required.'; return; }
    if (!selectedLists.value.length) { errors.value.lists = 'Import a CSV list or select a LinkedIn list.'; return; }
    phase.value = 'build';
}

function goToReview() {
    if (!campaignName.value.trim()) { errors.value.name = 'Name required.'; return; }
    if (!selectedLists.value.length) { errors.value.lists = 'Import a CSV list or select a LinkedIn list.'; return; }
    phase.value = 'review';
}

function payload(activate = false) {
    const data: Record<string, unknown> = {
        name: campaignName.value.trim(),
        template_type: selectedType.value,
        node_model: steps.value,
        lead_lists: selectedLists.value.map((l) => ({ list_hash: l.list_hash, list_src: l.list_src, list_name: l.list_name })),
    };

    if (Object.keys(channelInboxSettings.value).length > 0) {
        data.meta = { channel_inbox: channelInboxSettings.value };
    }

    if (activate) {
        data.activate = true;
    } else if (!props.campaign) {
        data.status = 'draft';
    }

    return data;
}

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

let autoSaveTimer: ReturnType<typeof setTimeout> | null = null;

function scheduleAutoSave() {
    if (!isEditing.value || phase.value !== 'build') return;

    if (autoSaveTimer) clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(() => {
        void autoSave();
    }, 900);
}

async function autoSave() {
    if (!props.campaign || saving.value) return;

    autoSaveState.value = 'saving';

    try {
        const res = await fetch(`/outreach/${props.campaign.id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify(payload(false)),
        });

        if (!res.ok) {
            throw new Error('Auto-save failed');
        }

        autoSaveState.value = 'saved';
        setTimeout(() => {
            if (autoSaveState.value === 'saved') autoSaveState.value = 'idle';
        }, 2000);
    } catch {
        autoSaveState.value = 'error';
    }
}

function saveChanges() {
    saving.value = true;
    const data = payload(false);
    if (props.campaign) {
        router.put(`/outreach/${props.campaign.id}`, data, {
            onFinish: () => { saving.value = false; },
        });
    } else {
        router.post('/outreach', data, { onFinish: () => { saving.value = false; } });
    }
}

function launch() {
    errors.value = {};
    const confirmMsg = reviewReadinessRef.value?.launchConfirmMessage();
    if (confirmMsg && !window.confirm(confirmMsg)) {
        return;
    }

    saving.value = true;
    const data = { ...payload(true), activate: true };
    if (props.campaign) router.put(`/outreach/${props.campaign.id}`, data, { onFinish: () => { saving.value = false; } });
    else router.post('/outreach', data, { onFinish: () => { saving.value = false; } });
}

function previewSteps(nodes: OutreachStep[]) {
    return nodes.filter((s) => s.type !== 'end').slice(0, 4);
}

function stepBadge(step: OutreachStep) {
    if (step.type === 'delay') return 'border-amber-300 bg-white text-amber-800 shadow-sm';
    if (step.type === 'condition') return 'border-orange-300 bg-white text-orange-800 shadow-sm';
    return 'border-border bg-white text-foreground shadow-sm';
}

watch(phase, (value) => {
    document.body.style.overflow = value === 'build' ? 'hidden' : '';
});

watch([campaignName, steps], () => {
    scheduleAutoSave();
}, { deep: true });

onMounted(() => {
    document.body.style.overflow = phase.value === 'build' ? 'hidden' : '';
});

onUnmounted(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <Head :title="campaign ? 'Edit Outreach' : 'New Outreach'" />

    <div v-if="phase === 'template'" class="mx-auto flex max-w-5xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">New Multi-Channel Outreach</h1>
                <p class="text-sm text-muted-foreground">Pick a realistic preset — prepare contacts on the next step, then build and launch.</p>
            </div>
            <Link href="/outreach" class="rounded-lg border px-3 py-2 text-sm">Cancel</Link>
        </div>

        <div class="flex gap-2 rounded-xl border border-blue-200 bg-blue-50/70 px-4 py-3 text-xs text-blue-950">
            <Info class="mt-0.5 h-4 w-4 shrink-0" />
            <p>
                Leads always start from <strong>LinkedIn lists</strong> and/or <strong>your own spreadsheet imports</strong> (CSV, Excel, ODS).
                On the next step you pick your lists, then build your sequence and prepare contacts on the same screen — no switching back and forth.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <button
                v-for="(tpl, key) in builtInTemplates"
                :key="key"
                type="button"
                class="flex flex-col gap-3 rounded-2xl border-2 bg-card p-5 text-left hover:border-primary/40"
                @click="pickTemplate(key)"
            >
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <component :is="templateIcon(key)" class="h-5 w-5" />
                    </div>
                    <span class="font-semibold text-sm">{{ tpl.label }}</span>
                </div>
                <p class="text-xs text-muted-foreground">{{ tpl.description }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <OutreachStepPreviewChip v-for="step in previewSteps(tpl.node_model)" :key="step.key" :step="step" :badge-class="stepBadge(step)" />
                </div>
            </button>
        </div>

        <div v-if="Object.keys(savedTemplates).length" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">Your saved templates</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="(tpl, key) in savedTemplates"
                    :key="key"
                    type="button"
                    class="relative flex flex-col gap-3 rounded-2xl border-2 border-violet-200 bg-card p-5 text-left hover:border-violet-400/60"
                    @click="pickTemplate(key)"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10 text-violet-700">
                                <Bookmark class="h-5 w-5" />
                            </div>
                            <span class="font-semibold text-sm">{{ tpl.label }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                title="Duplicate template"
                                @click="duplicateSavedTemplate(key, $event)"
                            >
                                <Copy class="h-3.5 w-3.5" />
                            </button>
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-muted-foreground hover:bg-red-50 hover:text-red-600"
                                title="Delete template"
                                @click="deleteSavedTemplate(key, $event)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ tpl.description }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        <OutreachStepPreviewChip v-for="step in previewSteps(tpl.node_model)" :key="step.key" :step="step" :badge-class="stepBadge(step)" />
                    </div>
                </button>
            </div>
        </div>
    </div>

    <div v-else-if="phase === 'leads'" class="mx-auto flex max-w-4xl flex-col gap-5 p-6">
        <OutreachBuilderStepper current="leads" :include-template="!isEditing" />

        <div>
            <h1 class="text-xl font-semibold">Choose your leads</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Import contacts or select LinkedIn lists. Next you’ll build your sequence and prepare contacts side-by-side.
            </p>
        </div>

        <input v-model="campaignName" type="text" placeholder="Campaign name" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-sm outline-none focus:border-primary" />
        <p v-if="errors.name" class="text-xs text-red-500">{{ errors.name }}</p>

        <div class="space-y-4">
            <!-- Selected lists as tags -->
            <div v-if="selectedLists.length" class="flex flex-wrap items-center gap-2 rounded-xl border border-blue-200 bg-blue-50/50 px-3 py-2.5 dark:border-blue-900/40 dark:bg-blue-950/20">
                <span class="text-xs font-medium text-muted-foreground">Selected</span>
                <span
                    v-for="list in selectedLists"
                    :key="`${list.list_src}:${list.list_hash}`"
                    class="inline-flex items-center gap-1.5 rounded-full border border-blue-200 bg-white py-1 pl-2.5 pr-1 text-xs font-medium text-foreground shadow-sm dark:border-blue-800 dark:bg-card"
                >
                    <FileSpreadsheet v-if="list.list_src === 'csv'" class="h-3 w-3 text-blue-600" />
                    <Users v-else class="h-3 w-3 text-blue-600" />
                    {{ list.list_name }}
                    <span class="text-muted-foreground">· {{ (list.lead_count ?? 0).toLocaleString() }}</span>
                    <button
                        type="button"
                        class="rounded-full p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                        @click="removeSelectedList(list.list_hash, list.list_src)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </span>
                <span class="ml-auto text-xs text-muted-foreground">
                    ~{{ selectedListsLeadCount.toLocaleString() }} contacts
                </span>
            </div>

            <!-- Tabs -->
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-1">
                <div class="flex gap-1">
                    <button
                        type="button"
                        class="rounded-t-lg px-4 py-2 text-sm font-medium transition-colors"
                        :class="leadSourceTab === 'imported' ? 'border-b-2 border-blue-600 text-foreground' : 'text-muted-foreground hover:text-foreground'"
                        @click="leadSourceTab = 'imported'"
                    >
                        Imported lists
                        <span class="ml-1.5 rounded-full bg-muted px-2 py-0.5 text-xs">{{ csvLeadLists.length }}</span>
                    </button>
                    <button
                        type="button"
                        class="rounded-t-lg px-4 py-2 text-sm font-medium transition-colors"
                        :class="leadSourceTab === 'linkedin' ? 'border-b-2 border-blue-600 text-foreground' : 'text-muted-foreground hover:text-foreground'"
                        @click="leadSourceTab = 'linkedin'"
                    >
                        LinkedIn lists
                        <span class="ml-1.5 rounded-full bg-muted px-2 py-0.5 text-xs">{{ linkedinLeadLists.length }}</span>
                    </button>
                </div>
                <Button v-if="leadSourceTab === 'imported'" class="gap-2" size="sm" @click="importModalOpen = true">
                    <Upload class="h-4 w-4" />
                    Import spreadsheet
                </Button>
            </div>

            <!-- Imported tab -->
            <template v-if="leadSourceTab === 'imported'">
                <div v-if="csvLeadLists.length" class="overflow-hidden rounded-xl border border-border bg-card">
                    <div class="grid gap-2 p-3">
                        <button
                            v-for="list in paginatedCsvLeadLists"
                            :key="list.type"
                            type="button"
                            class="rounded-xl border p-4 text-left transition-colors"
                            :class="isListSelected(list) ? 'border-blue-500 bg-blue-50/80 ring-1 ring-blue-500/20 dark:bg-blue-950/30' : 'border-border bg-card hover:border-blue-300'"
                            @click="toggleList(list)"
                        >
                            <p class="font-medium text-sm">{{ list.list_name }}</p>
                            <p class="text-xs text-muted-foreground">{{ list.source }} · {{ list.total_leads.toLocaleString() }} contacts</p>
                        </button>
                    </div>
                    <ClientPagination
                        v-model:page="csvListPage"
                        :total-pages="csvListTotalPages"
                        :total="csvLeadLists.length"
                        :per-page="LIST_PAGE_SIZE"
                        label="lists"
                    />
                </div>
                <div v-else class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-border bg-muted/20 p-10 text-center">
                    <FileSpreadsheet class="h-10 w-10 text-blue-500/60" />
                    <div>
                        <p class="font-medium">No imported lists yet</p>
                        <p class="mt-1 text-sm text-muted-foreground">Upload a spreadsheet with emails, phones, or social handles.</p>
                    </div>
                    <Button class="gap-2" @click="importModalOpen = true">
                        <Plus class="h-4 w-4" />
                        Import spreadsheet
                    </Button>
                </div>
            </template>

            <!-- LinkedIn tab -->
            <template v-else>
                <div v-if="linkedinLeadLists.length" class="overflow-hidden rounded-xl border border-border bg-card">
                    <div class="grid gap-2 p-3">
                        <button
                            v-for="list in paginatedLinkedinLeadLists"
                            :key="list.type"
                            type="button"
                            class="rounded-xl border p-4 text-left transition-colors"
                            :class="isListSelected(list) ? 'border-blue-500 bg-blue-50/80 ring-1 ring-blue-500/20 dark:bg-blue-950/30' : 'border-border bg-card hover:border-blue-300'"
                            @click="toggleList(list)"
                        >
                            <p class="font-medium text-sm">{{ list.list_name }}</p>
                            <p class="text-xs text-muted-foreground">{{ list.source }} · {{ list.total_leads.toLocaleString() }} leads</p>
                        </button>
                    </div>
                    <ClientPagination
                        v-model:page="linkedinListPage"
                        :total-pages="linkedinListTotalPages"
                        :total="linkedinLeadLists.length"
                        :per-page="LIST_PAGE_SIZE"
                        label="lists"
                    />
                </div>
                <p v-else class="rounded-xl border border-dashed border-border bg-muted/20 p-8 text-center text-sm text-muted-foreground">
                    No LinkedIn lists yet. Harvest audiences or import Sales Navigator leads — or use an imported spreadsheet instead.
                </p>
            </template>

            <p v-if="errors.lists" class="text-xs text-red-500">{{ errors.lists }}</p>
        </div>

        <div class="flex justify-between border-t border-border pt-4">
            <AppToolbarButton variant="slate" @click="phase = 'template'">← Templates</AppToolbarButton>
            <button type="button" class="rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700" @click="goToBuild">
                Continue to sequence & prep →
            </button>
        </div>
    </div>

    <!-- Sequence builder: fullscreen canvas -->
    <Teleport to="body">
        <div
            v-if="phase === 'build'"
            class="fixed inset-0 flex flex-col bg-slate-100"
            style="z-index: 9999; width: 100vw; height: 100dvh;"
        >
            <header class="flex shrink-0 flex-col gap-2 border-b border-slate-200 bg-white px-4 py-3 shadow-sm">
                <OutreachBuilderStepper current="build" :include-template="!isEditing" />
                <div class="flex items-center justify-between gap-4">
                    <AppToolbarButton variant="slate" @click="phase = 'leads'">
                        <ArrowLeft class="h-4 w-4" />
                        Leads
                    </AppToolbarButton>
                    <div class="min-w-0 text-center">
                        <input
                            v-model="campaignName"
                            type="text"
                            class="w-full max-w-xs truncate border-0 bg-transparent text-center text-sm font-semibold outline-none focus:ring-0"
                            placeholder="Outreach name"
                        />
                        <p v-if="requiredChannels.length" class="text-[10px] text-muted-foreground">
                            {{ requiredChannels.join(' · ') }}
                            <span v-if="buildPrepSummary"> · {{ buildPrepSummary }}</span>
                        </p>
                        <p v-else class="text-[10px] text-muted-foreground">Add send steps — prep panel updates live on the right</p>
                        <p v-if="isEditing && autoSaveState !== 'idle'" class="text-[10px] text-muted-foreground">
                            {{ autoSaveState === 'saving' ? 'Saving…' : autoSaveState === 'saved' ? 'Saved' : 'Save failed' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-border bg-white px-2.5 py-2 text-xs font-medium lg:hidden"
                            @click="showMobilePrep = true"
                        >
                            <Sparkles class="h-3.5 w-3.5" />
                            Prep
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium hover:opacity-90"
                            :class="isLiveCampaign ? 'border border-border bg-white text-foreground shadow-sm hover:bg-muted' : 'bg-primary text-primary-foreground'"
                            @click="goToReview"
                        >
                            {{ isLiveCampaign ? 'Review & save' : 'Launch' }}
                            <ChevronRight class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </header>

            <div class="flex min-h-0 flex-1 overflow-hidden">
                <div class="min-h-0 min-w-0 flex-1">
                    <OutreachFlowCanvas
                        :steps="steps"
                        :channel-registry="channelRegistry"
                        :connected-channels="connectedChannels"
                        @steps-changed="(next) => { steps = next; scheduleAutoSave(); }"
                    />
                </div>

                <aside class="hidden w-[min(100%,22rem)] shrink-0 flex-col border-l border-slate-200 bg-white lg:flex">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <p class="text-sm font-semibold">Contact prep</p>
                        <p class="text-[11px] text-muted-foreground">Prepare leads for the channels in your sequence — stay on this screen.</p>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto p-3">
                        <OutreachLeadReadinessPanel
                            ref="buildReadinessRef"
                            embedded-in-build
                            sidebar
                            compact
                            :lead-lists="selectedLists"
                            :node-model="steps"
                        />
                    </div>
                </aside>
            </div>

            <!-- Mobile prep drawer -->
            <div
                v-if="showMobilePrep"
                class="fixed inset-0 z-[10000] flex flex-col bg-black/40 lg:hidden"
                @click.self="showMobilePrep = false"
            >
                <div class="mt-auto flex max-h-[85dvh] flex-col rounded-t-2xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b px-4 py-3">
                        <p class="text-sm font-semibold">Contact prep</p>
                        <button type="button" class="rounded-lg p-1 hover:bg-muted" @click="showMobilePrep = false">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="overflow-y-auto p-3">
                        <OutreachLeadReadinessPanel
                            embedded-in-build
                            sidebar
                            compact
                            :lead-lists="selectedLists"
                            :node-model="steps"
                        />
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <div v-if="phase === 'review'" class="mx-auto flex max-w-3xl flex-col gap-5 p-6">
        <OutreachBuilderStepper current="review" :include-template="!isEditing" />

        <div>
            <h1 class="text-xl font-semibold">{{ isLiveCampaign ? 'Review changes' : 'Launch campaign' }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{
                    isLiveCampaign
                        ? 'Save updates to your live sequence — waiting leads will be rescheduled automatically.'
                        : 'Finish preparing contacts if needed, then launch when ready.'
                }}
            </p>
        </div>

        <p v-if="flashError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ flashError }}</p>
        <p v-if="errors.launch" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ errors.launch }}</p>

        <OutreachLeadReadinessPanel
            ref="reviewReadinessRef"
            :lead-lists="selectedLists"
            :node-model="steps"
        />

        <OutreachChannelInboxSettingsPanel
            v-if="inboxPlatformsForReview.length"
            v-model="channelInboxSettings"
            :platforms="inboxPlatformsForReview"
            :ai-configured="aiConfigured"
            :show-reply-branch-hint="hasReplyConditionBranches"
        />

        <div class="rounded-xl border divide-y bg-white">
            <div class="p-4"><p class="text-xs text-muted-foreground">Name</p><p class="font-semibold">{{ campaignName }}</p></div>
            <div class="p-4">
                <p class="text-xs text-muted-foreground">Connected channels</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span
                        v-for="ch in connectedChannels.filter(c => c.connected)"
                        :key="ch.channel"
                        class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1 text-xs shadow-sm"
                        :class="requiredChannels.includes(ch.channel) ? 'border-primary/40 text-foreground' : 'border-border text-muted-foreground'"
                    >
                        <OutreachChannelIcon :channel="ch.channel" :size="16" class="h-4 w-4" />
                        {{ ch.label }}
                        <span v-if="requiredChannels.includes(ch.channel)" class="text-[10px] font-medium text-primary">required</span>
                    </span>
                </div>
                <p v-if="requiredChannels.some(ch => !connectedChannels.find(c => c.channel === ch && c.connected))" class="mt-2 text-xs text-red-600">
                    Connect all required channels on Integrations before launching.
                </p>
            </div>
            <div class="p-4">
                <p class="text-xs text-muted-foreground">Sequence steps</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <span v-for="step in steps.filter(s => s.type !== 'end')" :key="step.key" class="rounded-lg border px-2.5 py-1 text-[11px] font-medium" :class="stepBadge(step)">
                        {{ stepChipLabel(step) }}
                    </span>
                </div>
            </div>
            <div class="p-4">
                <p class="text-xs text-muted-foreground">Lead lists ({{ selectedLists.length }})</p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="list in selectedLists" :key="list.list_src + list.list_hash">{{ list.list_name }}</li>
                </ul>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <button
                type="button"
                class="rounded-lg border border-border bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-muted"
                @click="phase = 'build'"
            >
                ← Sequence & prep
            </button>
            <template v-if="isLiveCampaign">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm disabled:opacity-60"
                    :disabled="saving"
                    @click="saveChanges"
                >
                    Save changes
                </button>
            </template>
            <template v-else>
                <button
                    type="button"
                    class="rounded-lg border border-border bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-muted disabled:opacity-60"
                    :disabled="saving"
                    @click="saveChanges"
                >
                    Save draft
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm disabled:opacity-60"
                    :disabled="saving"
                    @click="launch"
                >
                    <Rocket class="h-4 w-4" /> Launch
                </button>
            </template>
        </div>
    </div>

    <Dialog v-model:open="importModalOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Import contact list</DialogTitle>
                <DialogDescription>
                    Drop a CSV or Excel file — WhatsApp, email, Instagram, Telegram, or X handles.
                </DialogDescription>
            </DialogHeader>
            <OutreachImportListPanel in-modal @imported="onListImported" />
        </DialogContent>
    </Dialog>
</template>
