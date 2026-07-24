<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Camera, ChevronRight, Info, Layers, Mail, MessageCircle, Rocket, Settings2, Users } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import OutreachFlowCanvas from '@/components/outreach/OutreachFlowCanvas.vue';
import OutreachImportListPanel, { type ImportedListOption } from '@/components/outreach/OutreachImportListPanel.vue';
import OutreachLeadReadinessPanel from '@/components/outreach/OutreachLeadReadinessPanel.vue';
import OutreachStepPreviewChip from '@/components/outreach/OutreachStepPreviewChip.vue';
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
    templates: Record<string, { label: string; description: string; node_model: OutreachStep[] }>;
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
    } | null;
    availableLeadLists: LeadListOption[];
    attachedLists: Array<{ list_hash: string; list_src: string; list_name: string; lead_count?: number }>;
    initialStep?: string;
}>();

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
const extraImportLists = ref<LeadListOption[]>([]);

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

const isEditing = computed(() => !!props.campaign);
const isLiveCampaign = computed(() =>
    isEditing.value && ['running', 'active'].includes(props.campaign?.status ?? ''),
);

const templateIcons: Record<string, unknown> = {
    linkedin_only: Users,
    linkedin_email: Layers,
    linkedin_whatsapp: MessageCircle,
    multichannel: Layers,
    email_only: Mail,
    whatsapp_only: MessageCircle,
    social_dm: Camera,
    custom: Settings2,
};

const requiredChannels = computed(() => {
    const channels = new Set<string>();
    const walk = (nodes: OutreachStep[]) => {
        for (const n of nodes) {
            if (n.type === 'action' && n.channel) channels.add(n.channel);
            if (n.branches) {
                walk(n.branches.accepted || []);
                walk(n.branches.not_accepted || []);
            }
        }
    };
    walk(steps.value);
    return [...channels];
});

function pickTemplate(key: string) {
    selectedType.value = key;
    steps.value = JSON.parse(JSON.stringify(props.templates[key].node_model));
    if (!campaignName.value) campaignName.value = props.templates[key].label;
    phase.value = 'leads';
}

function toggleList(list: LeadListOption) {
    const idx = selectedLists.value.findIndex((l) => l.list_hash === list.list_hash && l.list_src === list.src);
    if (idx >= 0) selectedLists.value.splice(idx, 1);
    else selectedLists.value.push({ list_hash: list.list_hash, list_src: list.src, list_name: list.list_name, lead_count: list.total_leads });
}

function isListSelected(list: LeadListOption) {
    return selectedLists.value.some((l) => l.list_hash === list.list_hash && l.list_src === list.src);
}

function onListImported(list: ImportedListOption) {
    extraImportLists.value.push(list);
    if (!selectedLists.value.some((l) => l.list_hash === list.list_hash && l.list_src === 'csv')) {
        selectedLists.value.push({ list_hash: list.list_hash, list_src: 'csv', list_name: list.list_name, lead_count: list.total_leads });
    }
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
                On the next step you can import a WhatsApp/email list, optionally add LinkedIn lists, then prepare contacts before building your sequence.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <button
                v-for="(tpl, key) in templates"
                :key="key"
                type="button"
                class="flex flex-col gap-3 rounded-2xl border-2 bg-card p-5 text-left hover:border-primary/40"
                @click="pickTemplate(key)"
            >
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <component :is="templateIcons[key]" class="h-5 w-5" />
                    </div>
                    <span class="font-semibold text-sm">{{ tpl.label }}</span>
                </div>
                <p class="text-xs text-muted-foreground">{{ tpl.description }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <OutreachStepPreviewChip v-for="step in previewSteps(tpl.node_model)" :key="step.key" :step="step" :badge-class="stepBadge(step)" />
                </div>
            </button>
        </div>
    </div>

    <div v-else-if="phase === 'leads'" class="mx-auto flex max-w-6xl flex-col gap-5 p-6">
        <div>
            <h1 class="text-xl font-semibold">Choose your leads</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Import your own contacts (WhatsApp, email, social) or add LinkedIn lists — or both. Multi-channel outreach does not require LinkedIn.
            </p>
        </div>

        <input v-model="campaignName" type="text" placeholder="Campaign name" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-sm outline-none focus:border-primary" />
        <p v-if="errors.name" class="text-xs text-red-500">{{ errors.name }}</p>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            <div class="min-w-0 space-y-5">
                <OutreachImportListPanel @imported="onListImported" />

                <div v-if="csvLeadLists.length">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Imported lists</p>
                    <div class="grid gap-2">
                        <button
                            v-for="list in csvLeadLists"
                            :key="list.type"
                            type="button"
                            class="rounded-xl border p-4 text-left transition-colors"
                            :class="isListSelected(list) ? 'border-violet-500 bg-violet-50' : 'border-border bg-white hover:border-violet-300'"
                            @click="toggleList(list)"
                        >
                            <p class="font-medium text-sm">{{ list.list_name }}</p>
                            <p class="text-xs text-muted-foreground">{{ list.source }} · {{ list.total_leads }} contacts</p>
                        </button>
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">LinkedIn lists (optional)</p>
                    <div v-if="linkedinLeadLists.length" class="grid max-h-[min(320px,40vh)] gap-2 overflow-y-auto pr-1">
                        <button
                            v-for="list in linkedinLeadLists"
                            :key="list.type"
                            type="button"
                            class="rounded-xl border p-4 text-left transition-colors"
                            :class="isListSelected(list) ? 'border-primary bg-primary/5' : 'border-border bg-white hover:border-primary/40'"
                            @click="toggleList(list)"
                        >
                            <p class="font-medium text-sm">{{ list.list_name }}</p>
                            <p class="text-xs text-muted-foreground">{{ list.source }} · {{ list.total_leads }} leads</p>
                        </button>
                    </div>
                    <p v-else class="rounded-xl border border-dashed border-border bg-white p-4 text-xs text-muted-foreground">
                        No LinkedIn lists yet. You can still run WhatsApp-only or email-only campaigns with an imported CSV above.
                    </p>
                </div>
                <p v-if="errors.lists" class="text-xs text-red-500">{{ errors.lists }}</p>
            </div>

            <div class="min-w-0 lg:sticky lg:top-4">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Lead readiness</p>
                <OutreachLeadReadinessPanel
                    v-if="selectedLists.length"
                    sidebar
                    :lead-lists="selectedLists"
                    :node-model="steps"
                />
                <div v-else class="rounded-xl border border-dashed border-border bg-white p-5 text-sm text-muted-foreground">
                    Import a CSV or select lists to see readiness for your sequence. For WhatsApp-only outreach, upload a CSV with phone numbers and pick the WhatsApp template.
                </div>
            </div>
        </div>

        <div class="flex justify-between border-t border-border pt-4">
            <button type="button" class="rounded-lg border border-border bg-white px-4 py-2 text-sm hover:bg-muted" @click="phase = 'template'">← Templates</button>
            <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm text-primary-foreground" @click="goToBuild">Continue to sequence →</button>
        </div>
    </div>

    <!-- Sequence builder: fullscreen canvas -->
    <Teleport to="body">
        <div
            v-if="phase === 'build'"
            class="fixed inset-0 flex flex-col bg-slate-100"
            style="z-index: 9999; width: 100vw; height: 100dvh;"
        >
            <header class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 shadow-sm">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium hover:bg-muted"
                    @click="phase = 'leads'"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Back
                </button>
                <div class="min-w-0 text-center">
                    <input
                        v-model="campaignName"
                        type="text"
                        class="w-full max-w-xs truncate border-0 bg-transparent text-center text-sm font-semibold outline-none focus:ring-0"
                        placeholder="Outreach name"
                    />
                    <p class="text-[11px] capitalize text-muted-foreground">{{ selectedType.replace(/_/g, ' ') }}</p>
                    <p v-if="isEditing && autoSaveState !== 'idle'" class="text-[10px] text-muted-foreground">
                        {{ autoSaveState === 'saving' ? 'Saving…' : autoSaveState === 'saved' ? 'Saved' : 'Save failed' }}
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium hover:opacity-90"
                    :class="isLiveCampaign ? 'border border-border bg-white text-foreground shadow-sm hover:bg-muted' : 'bg-primary text-primary-foreground'"
                    @click="goToReview"
                >
                    {{ isLiveCampaign ? 'Review & save' : 'Review & launch' }}
                    <ChevronRight class="h-4 w-4" />
                </button>
            </header>

            <div class="min-h-0 flex-1 overflow-hidden">
                <OutreachFlowCanvas
                    :steps="steps"
                    :channel-registry="channelRegistry"
                    :connected-channels="connectedChannels"
                    @steps-changed="(next) => { steps = next; scheduleAutoSave(); }"
                />
            </div>
        </div>
    </Teleport>

    <div v-if="phase === 'review'" class="mx-auto flex max-w-2xl flex-col gap-5 p-6">
        <div>
            <h1 class="text-xl font-semibold">{{ isLiveCampaign ? 'Review changes' : 'Review & launch' }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{
                    isLiveCampaign
                        ? 'Save updates to your live sequence — waiting leads will be rescheduled automatically.'
                        : 'Confirm your sequence and lead readiness before going live.'
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

        <div class="rounded-xl border divide-y bg-white">
            <div class="p-4"><p class="text-xs text-muted-foreground">Name</p><p class="font-semibold">{{ campaignName }}</p></div>
            <div class="p-4">
                <p class="text-xs text-muted-foreground">Connected channels</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span
                        v-for="ch in connectedChannels.filter(c => c.connected)"
                        :key="ch.channel"
                        class="inline-flex items-center gap-1 rounded-lg border bg-white px-2.5 py-1 text-xs shadow-sm"
                        :class="requiredChannels.includes(ch.channel) ? 'border-primary/40 text-foreground' : 'border-border text-muted-foreground'"
                    >
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
                ← Edit sequence
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
</template>
