<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Users, Star, Eye, Settings2, CheckCircle2, Layers, Plus, Trash2, Rocket, ChevronRight, Search, ChevronLeft, ArrowLeft } from '@lucide/vue';
import CampaignFlowCanvas from '@/components/campaign/CampaignFlowCanvas.vue';
import CampaignStepPreviewChip from '@/components/campaign/CampaignStepPreviewChip.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import { type CampaignStep } from '@/components/campaign/types';
import { Checkbox } from '@/components/ui/checkbox';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Campaigns', href: '/campaigns' },
            { title: 'Builder' },
        ],
    },
});

interface LeadListOption {
    list_name: string;
    list_hash: string;
    total_leads: number;
    source: string;
    src: 'aud' | 'sn';
    type: string;
}

interface AttachedList {
    id?: number;
    list_hash: string;
    list_src: 'aud' | 'sn';
    list_name: string;
    lead_count?: number;
}

interface Template {
    label: string;
    description: string;
    color?: string;
    node_model: CampaignStep[];
}

const props = defineProps<{
    templates: Record<string, Template>;
    campaign?: {
        id: number;
        name: string;
        sequence_type: string;
        status: string;
        node_model: CampaignStep[];
        meta: Record<string, unknown> | null;
    } | null;
    availableLeadLists: LeadListOption[];
    attachedLists: AttachedList[];
    initialStep?: string;
}>();

type Phase = 'template' | 'leads' | 'build' | 'review';

const phase = ref<Phase>(
    props.campaign
        ? (['leads', 'build', 'review'].includes(props.initialStep ?? '') ? props.initialStep as Phase : 'build')
        : 'template',
);

const selectedType = ref(props.campaign?.sequence_type ?? '');
const campaignName = ref(props.campaign?.name ?? '');
const steps = ref<CampaignStep[]>(
    props.campaign?.node_model ? JSON.parse(JSON.stringify(props.campaign.node_model)) : [],
);
const processConditions = ref<string[]>(
    (props.campaign?.meta?.process_conditions as string[]) ?? [],
);
const selectedLists = ref<AttachedList[]>([...props.attachedLists]);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

const PROCESS_CONDITIONS = [
    { key: 'lead_on_other_campaign', label: 'Lead is already running on another campaign' },
    { key: 'no_profile_photo', label: 'Profile has no photo' },
    { key: 'free_linkedin_account', label: 'Free LinkedIn account' },
    { key: 'open_profile_only', label: 'Open profiles only' },
];

function toggleProcessCondition(key: string, checked: boolean | 'indeterminate') {
    const on = checked === true;
    if (on && !processConditions.value.includes(key)) {
        processConditions.value = [...processConditions.value, key];
    } else if (!on) {
        processConditions.value = processConditions.value.filter((item) => item !== key);
    }
}

const templateIcons: Record<string, unknown> = {
    lead_gen: Users, endorse: Star, profile_views: Eye, custom: Settings2,
};
const templateBorderColors: Record<string, string> = {
    lead_gen: 'border-blue-200 hover:border-blue-400 hover:bg-blue-50/60',
    endorse: 'border-yellow-200 hover:border-yellow-400 hover:bg-yellow-50/60',
    profile_views: 'border-purple-200 hover:border-purple-400 hover:bg-purple-50/60',
    custom: 'border-slate-200 hover:border-slate-400 hover:bg-slate-50/60',
};
const templateIconBg: Record<string, string> = {
    lead_gen: 'bg-blue-100 text-blue-600', endorse: 'bg-yellow-100 text-yellow-600',
    profile_views: 'bg-purple-100 text-purple-600', custom: 'bg-slate-100 text-slate-600',
};

const totalSelectedLeads = computed(() =>
    selectedLists.value.reduce((sum, l) => sum + (l.lead_count ?? 0), 0),
);

const stepLabels: Record<Phase, string> = {
    template: 'Template',
    leads: 'Lead lists',
    build: 'Sequence',
    review: 'Launch',
};

function pickTemplate(key: string) {
    selectedType.value = key;
    steps.value = JSON.parse(JSON.stringify(props.templates[key].node_model));
    if (!campaignName.value) campaignName.value = props.templates[key].label;
    phase.value = 'leads';
}

function isListSelected(list: LeadListOption): boolean {
    return selectedLists.value.some((l) => l.list_hash === list.list_hash && l.list_src === list.src);
}

function toggleList(list: LeadListOption) {
    const idx = selectedLists.value.findIndex((l) => l.list_hash === list.list_hash && l.list_src === list.src);
    if (idx >= 0) {
        selectedLists.value.splice(idx, 1);
    } else {
        selectedLists.value.push({
            list_hash: list.list_hash,
            list_src: list.src,
            list_name: list.list_name,
            lead_count: list.total_leads,
        });
    }
}

function removeList(list: AttachedList) {
    selectedLists.value = selectedLists.value.filter(
        (l) => !(l.list_hash === list.list_hash && l.list_src === list.list_src),
    );
}

function validateName(): boolean {
    errors.value = {};
    if (!campaignName.value.trim()) {
        errors.value.name = 'Campaign name is required.';
        return false;
    }
    return true;
}

function goToBuild() {
    if (!validateName()) return;
    if (selectedLists.value.length === 0) {
        errors.value.lists = 'Select at least one lead list.';
        return;
    }
    phase.value = 'build';
}

function goToReview() {
    if (!validateName()) return;
    phase.value = 'review';
}

function payload(status: 'draft' | 'active', activate = false) {
    return {
        name: campaignName.value.trim(),
        sequence_type: selectedType.value,
        node_model: steps.value,
        meta: { process_conditions: processConditions.value },
        status,
        lead_lists: selectedLists.value.map((l) => ({
            list_hash: l.list_hash,
            list_src: l.list_src,
            list_name: l.list_name,
        })),
        activate,
    };
}

function saveDraft() {
    if (!validateName()) return;
    saving.value = true;
    const data = payload('draft');
    if (props.campaign) {
        router.put(`/campaigns/${props.campaign.id}`, data, {
            onError: (e) => { errors.value = e; },
            onFinish: () => { saving.value = false; },
        });
    } else {
        router.post('/campaigns', data, {
            onError: (e) => { errors.value = e; },
            onFinish: () => { saving.value = false; },
        });
    }
}

function launch() {
    if (!validateName()) return;
    if (selectedLists.value.length === 0) {
        errors.value.lists = 'Select at least one lead list before launching.';
        return;
    }
    saving.value = true;
    const data = payload('active', true);
    if (props.campaign) {
        router.put(`/campaigns/${props.campaign.id}`, data, {
            onError: (e) => { errors.value = e; },
            onFinish: () => { saving.value = false; },
        });
    } else {
        router.post('/campaigns', { ...data, status: 'active', activate: true }, {
            onError: (e) => { errors.value = e; },
            onFinish: () => { saving.value = false; },
        });
    }
}

function previewSteps(nodeModel: CampaignStep[]) {
    return nodeModel.filter((s) => s.type !== 'end').slice(0, 4);
}

function stepBadge(step: CampaignStep): string {
    if (step.type === 'delay') return 'border-amber-200 bg-amber-50 text-amber-700';
    if (step.type === 'condition') return 'border-orange-200 bg-orange-50 text-orange-700';
    return 'border-border bg-muted text-muted-foreground';
}

function flatten(nodes: CampaignStep[]): CampaignStep[] {
    const result: CampaignStep[] = [];
    for (const n of nodes) {
        if (n.type !== 'end') result.push(n);
        if (n.branches) {
            result.push(...flatten(n.branches.accepted || []), ...flatten(n.branches.not_accepted || []));
        }
    }
    return result;
}

const flatSteps = computed(() => flatten(steps.value));

const LIST_PAGE_SIZE = 4;
const listSearch = ref('');
const listPage = ref(1);

const filteredLeadLists = computed(() => {
    const q = listSearch.value.trim().toLowerCase();
    if (!q) return props.availableLeadLists;

    return props.availableLeadLists.filter((list) =>
        list.list_name.toLowerCase().includes(q)
        || list.source.toLowerCase().includes(q)
        || list.list_hash.toLowerCase().includes(q),
    );
});

const listTotalPages = computed(() =>
    Math.max(1, Math.ceil(filteredLeadLists.value.length / LIST_PAGE_SIZE)),
);

const paginatedLeadLists = computed(() => {
    const start = (listPage.value - 1) * LIST_PAGE_SIZE;
    return filteredLeadLists.value.slice(start, start + LIST_PAGE_SIZE);
});

function onListSearchInput() {
    listPage.value = 1;
}

function goListPage(page: number) {
    listPage.value = Math.min(Math.max(1, page), listTotalPages.value);
}

watch(phase, (value) => {
    document.body.style.overflow = value === 'build' ? 'hidden' : '';
});

onMounted(() => {
    document.body.style.overflow = phase.value === 'build' ? 'hidden' : '';
});

onUnmounted(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <Head :title="campaign ? 'Edit Campaign' : 'New Campaign'" />

    <!-- Step indicator (hidden in fullscreen sequence editor) -->
    <div v-if="phase !== 'template' && phase !== 'build'" class="border-b border-border bg-card px-4 py-3">
        <div class="mx-auto flex max-w-3xl items-center justify-center gap-2 text-xs">
            <template v-for="(label, key) in stepLabels" :key="key">
                <button
                    v-if="key !== 'template'"
                    type="button"
                    class="rounded-full px-3 py-1 font-medium transition-colors"
                    :class="phase === key ? 'bg-gradient-to-b from-blue-500 to-blue-600 text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
                    @click="key === 'leads' ? (phase = 'leads') : key === 'build' ? goToBuild() : key === 'review' ? goToReview() : null"
                >
                    {{ label }}
                </button>
                <ChevronRight v-if="key !== 'review' && key !== 'template'" class="h-3 w-3 text-muted-foreground" />
            </template>
        </div>
    </div>

    <!-- Template picker -->
    <div v-if="phase === 'template'" class="flex flex-col gap-6 p-6 max-w-2xl mx-auto">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">New Campaign</h1>
                <p class="text-sm text-muted-foreground mt-0.5">Pick a template to start</p>
            </div>
            <a href="/campaigns" class="rounded-lg border border-border bg-card px-3 py-2 text-sm text-muted-foreground hover:bg-muted">Cancel</a>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <button
                v-for="(tpl, key) in templates"
                :key="key"
                type="button"
                class="flex flex-col gap-3 rounded-2xl border-2 bg-card p-5 text-left transition-all cursor-pointer shadow-sm"
                :class="templateBorderColors[key]"
                @click="pickTemplate(key)"
            >
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl shrink-0" :class="templateIconBg[key]">
                        <component :is="templateIcons[key]" class="h-5 w-5" />
                    </div>
                    <div class="font-semibold text-sm">{{ tpl.label }}</div>
                </div>
                <p class="text-xs text-muted-foreground leading-relaxed">{{ tpl.description }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <CampaignStepPreviewChip
                        v-for="step in previewSteps(tpl.node_model)"
                        :key="step.key"
                        :step="step"
                        :badge-class="stepBadge(step)"
                    />
                </div>
            </button>
        </div>
    </div>

    <!-- Lead lists -->
    <div v-else-if="phase === 'leads'" class="mx-auto flex max-w-3xl flex-col gap-5 p-6">
        <div>
            <h1 class="text-xl font-semibold">Select lead lists</h1>
            <p class="text-sm text-muted-foreground">Choose audiences or Sales Navigator lists for this campaign.</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <label class="mb-1 block text-xs font-semibold uppercase text-muted-foreground">Campaign name</label>
            <input v-model="campaignName" type="text" placeholder="e.g. Q3 Lead Gen — CTOs" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary" />
            <p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p>
        </div>

        <div v-if="availableLeadLists.length === 0" class="rounded-xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
            No lead lists yet. Harvest audiences from Competitor Active Followers or import Sales Navigator leads via the extension.
        </div>

        <div v-else class="flex flex-col gap-3">
            <div class="relative">
                <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    v-model="listSearch"
                    type="search"
                    placeholder="Search lists by name or source…"
                    class="w-full rounded-xl border border-border bg-card py-2.5 pl-9 pr-3 text-sm outline-none focus:border-primary"
                    @input="onListSearchInput"
                />
            </div>

            <p v-if="filteredLeadLists.length === 0" class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                No lists match "{{ listSearch }}".
            </p>

            <div v-else class="grid gap-2">
                <button
                    v-for="list in paginatedLeadLists"
                    :key="list.type"
                    type="button"
                    class="flex items-center justify-between rounded-xl border p-4 text-left transition-colors"
                    :class="isListSelected(list) ? 'border-primary bg-primary/5' : 'border-border bg-card hover:border-primary/40'"
                    @click="toggleList(list)"
                >
                    <div>
                        <p class="font-medium text-sm">{{ list.list_name }}</p>
                        <p class="text-xs text-muted-foreground">{{ list.source }} · {{ list.total_leads.toLocaleString() }} leads</p>
                    </div>
                    <Plus v-if="!isListSelected(list)" class="h-4 w-4 text-muted-foreground" />
                    <CheckCircle2 v-else class="h-4 w-4 text-primary" />
                </button>
            </div>

            <div v-if="filteredLeadLists.length > LIST_PAGE_SIZE" class="flex items-center justify-between text-xs text-muted-foreground">
                <span>
                    Page {{ listPage }} of {{ listTotalPages }}
                    · {{ filteredLeadLists.length }} list{{ filteredLeadLists.length !== 1 ? 's' : '' }}
                </span>
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        :disabled="listPage <= 1"
                        class="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 hover:bg-muted disabled:opacity-40"
                        @click="goListPage(listPage - 1)">
                        <ChevronLeft class="h-3 w-3" /> Prev
                    </button>
                    <button
                        type="button"
                        :disabled="listPage >= listTotalPages"
                        class="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 hover:bg-muted disabled:opacity-40"
                        @click="goListPage(listPage + 1)">
                        Next <ChevronRight class="h-3 w-3" />
                    </button>
                </div>
            </div>
        </div>

        <p v-if="errors.lists" class="text-xs text-red-500">{{ errors.lists }}</p>

        <div v-if="selectedLists.length" class="rounded-xl border border-border bg-muted/30 p-4">
            <p class="text-sm font-medium">{{ selectedLists.length }} list(s) selected · {{ totalSelectedLeads.toLocaleString() }} total leads</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <span v-for="list in selectedLists" :key="list.list_src + list.list_hash" class="inline-flex items-center gap-1 rounded-full bg-card border border-border px-2.5 py-1 text-xs">
                    {{ list.list_name }}
                    <button type="button" @click="removeList(list)"><Trash2 class="h-3 w-3 text-muted-foreground hover:text-red-500" /></button>
                </span>
            </div>
        </div>

        <div class="flex justify-between gap-3">
            <button v-if="!campaign" type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted" @click="phase = 'template'">← Templates</button>
            <div v-else />
            <button type="button" class="rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700" @click="goToBuild">
                Continue to sequence →
            </button>
        </div>
    </div>

    <!-- Sequence builder: fullscreen overlay (teleported above sidebar + navbar) -->
    <Teleport to="body">
        <div
            v-if="phase === 'build'"
            class="fixed inset-0 flex flex-col bg-slate-100"
            style="z-index: 9999; width: 100vw; height: 100dvh;"
        >
            <header class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 shadow-sm">
                <AppToolbarButton variant="slate" @click="phase = 'leads'">
                    <ArrowLeft class="h-4 w-4" />
                    Back
                </AppToolbarButton>
                <div class="min-w-0 text-center">
                    <p class="truncate text-sm font-semibold">{{ campaignName || 'Campaign sequence' }}</p>
                    <p class="text-[11px] capitalize text-muted-foreground">{{ selectedType.replace(/_/g, ' ') }}</p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-3 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700"
                    @click="goToReview"
                >
                    Review & launch
                    <ChevronRight class="h-4 w-4" />
                </button>
            </header>

            <div class="flex min-h-0 flex-1 overflow-hidden">
                <div class="min-h-0 min-w-0 flex-1 bg-white">
                    <CampaignFlowCanvas :steps="steps" @steps-changed="steps = $event" />
                </div>

                <aside class="flex w-72 shrink-0 flex-col overflow-hidden border-l border-slate-200 bg-white shadow-[inset_1px_0_0_rgba(15,23,42,0.04)]">
                    <div class="shrink-0 border-b border-border px-4 py-3">
                        <h2 class="text-sm font-semibold">Sequence settings</h2>
                        <p class="text-[11px] capitalize text-muted-foreground">{{ selectedType.replace(/_/g, ' ') }}</p>
                    </div>
                    <div class="shrink-0 border-b border-border px-4 py-4">
                        <label class="text-xs font-semibold uppercase text-muted-foreground">Campaign name</label>
                        <input v-model="campaignName" type="text" class="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary" />
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                        <div class="flex flex-col gap-3">
                            <p class="text-xs font-semibold uppercase text-muted-foreground">Skip lead if</p>
                            <label v-for="cond in PROCESS_CONDITIONS" :key="cond.key" class="flex cursor-pointer items-start gap-2">
                                <Checkbox
                                    class="mt-0.5"
                                    :checked="processConditions.includes(cond.key)"
                                    @update:checked="toggleProcessCondition(cond.key, $event)"
                                />
                                <span class="text-xs text-muted-foreground">{{ cond.label }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="shrink-0 border-t border-border p-4">
                        <p class="mb-2 text-[11px] text-muted-foreground">Click any step on the canvas to configure it.</p>
                        <button type="button" class="w-full rounded-xl border border-border px-4 py-2 text-sm hover:bg-muted" @click="phase = 'leads'">
                            ← Lead lists
                        </button>
                    </div>
                </aside>
            </div>
        </div>
    </Teleport>

    <!-- Review & launch -->
    <div v-if="phase === 'review'" class="mx-auto flex max-w-2xl flex-col gap-5 p-6">
        <div>
            <h1 class="text-xl font-semibold">Review & launch</h1>
            <p class="text-sm text-muted-foreground">Confirm your campaign settings before going live.</p>
        </div>

        <div class="rounded-xl border border-border bg-card divide-y divide-border">
            <div class="p-4">
                <p class="text-xs text-muted-foreground uppercase">Campaign</p>
                <p class="font-semibold">{{ campaignName }}</p>
                <p class="text-sm text-muted-foreground capitalize">{{ selectedType.replace(/_/g, ' ') }}</p>
            </div>
            <div class="p-4">
                <p class="text-xs text-muted-foreground uppercase">Lead lists ({{ selectedLists.length }})</p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="list in selectedLists" :key="list.list_src + list.list_hash" class="flex items-center gap-2">
                        <Layers class="h-3.5 w-3.5 text-muted-foreground" />
                        {{ list.list_name }} <span class="text-muted-foreground">({{ (list.lead_count ?? 0).toLocaleString() }} leads)</span>
                    </li>
                </ul>
            </div>
            <div class="p-4">
                <p class="text-xs text-muted-foreground uppercase">Sequence ({{ flatSteps.length }} steps)</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <CampaignStepPreviewChip
                        v-for="step in flatSteps.slice(0, 8)"
                        :key="step.key"
                        :step="step"
                        :badge-class="stepBadge(step)"
                    />
                    <span v-if="flatSteps.length > 8" class="text-xs text-muted-foreground">+{{ flatSteps.length - 8 }} more</span>
                </div>
            </div>
            <div v-if="processConditions.length" class="p-4">
                <p class="text-xs text-muted-foreground uppercase">Skip conditions</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <span v-for="c in processConditions" :key="c" class="rounded-full bg-muted px-2 py-0.5 text-xs capitalize">{{ c.replace(/_/g, ' ') }}</span>
                </div>
            </div>
        </div>

        <p v-if="errors.lists" class="text-xs text-red-500">{{ errors.lists }}</p>

        <div class="flex flex-wrap gap-3">
            <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted" @click="phase = 'build'">← Edit sequence</button>
            <button type="button" :disabled="saving" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted disabled:opacity-60" @click="saveDraft">
                Save as draft
            </button>
            <button type="button" :disabled="saving" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-60" @click="launch">
                <Rocket class="h-4 w-4" /> {{ saving ? 'Launching…' : 'Launch campaign' }}
            </button>
        </div>
    </div>
</template>
