<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Bell, Calendar, Clock, Loader2, Phone, Plus, Search, Send, Settings, Sparkles, Users } from '@lucide/vue';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import CheckboxField from '@/components/CheckboxField.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { computed, ref, watch } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Call Manager', href: '/calls' },
        ],
    },
});

interface CallRow {
    id: number;
    prospect_name: string | null;
    prospect_headline: string | null;
    connection_id: string | null;
    status: string;
    pipeline_stage: string;
    pending_message: string | null;
    scheduled_send_at: string | null;
    scheduled_call_at: string | null;
    ready_to_send: boolean;
    has_conversation: boolean;
}

interface LeadList {
    id: number;
    list_id: string;
    list_name: string;
    total_leads: number;
    source: string;
    src: 'aud' | 'sn';
}

interface LeadRow {
    id: number;
    name: string;
    headline: string | null;
    profileid: string | null;
    profile_url: string | null;
}

const props = defineProps<{
    pipeline: {
        engaged: CallRow[];
        scheduling: CallRow[];
        booked: CallRow[];
    };
    upcoming: CallRow[];
    dueReminders: Array<{ id: number; message: string; send_at: string; call_id: number | null; prospect_name: string | null }>;
    stats: { in_pipeline: number; booked: number; ready_to_send: number; calls_today: number };
    settings: {
        calendar_url: string;
        booking_message: string;
        auto_send_suggestions: boolean;
        reminder_hours_before: number[];
    };
    hasOrg: boolean;
    hasUnipile: boolean;
    leadLists: LeadList[];
    conversations: Array<{ id: number; provider_chat_id: string | null; last_message_at: string | null }>;
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const showCampaignModal = ref(false);
const showCreateModal = ref(false);
const showSettingsModal = ref(false);
const showRemindersModal = ref(false);

const createForm = useForm({
    conversation_id: '' as string | number,
    prospect_name: '',
    pending_message: '',
});

const campaignForm = useForm({
    list_id: '',
    src: 'aud' as 'aud' | 'sn',
    batch_name: '',
    pending_message: '',
    run: true,
    select_all: false,
    lead_ids: [] as number[],
});

const selectedListKey = ref('');
const leadSearch = ref('');
const leadsLoading = ref(false);
const leadsError = ref('');
const listLeads = ref<LeadRow[]>([]);
const leadsTotal = ref(0);
const leadsPage = ref(1);
const leadsLastPage = ref(1);
const selectedLeadIds = ref<Set<number>>(new Set());
const selectAllInList = ref(false);

watch(selectedListKey, (key) => {
    selectedLeadIds.value = new Set();
    selectAllInList.value = false;
    listLeads.value = [];
    leadsPage.value = 1;

    if (!key) {
        campaignForm.list_id = '';
        return;
    }

    const list = props.leadLists.find((l) => `${l.src}:${l.list_id}` === key);
    if (!list) return;

    campaignForm.list_id = list.list_id;
    campaignForm.src = list.src;
    void loadLeads(1);
});

function resetCampaignModal() {
    selectedListKey.value = '';
    selectedLeadIds.value = new Set();
    selectAllInList.value = false;
    leadSearch.value = '';
    listLeads.value = [];
    campaignForm.reset();
    campaignForm.run = true;
}

function openCampaignModal() {
    resetCampaignModal();
    showCampaignModal.value = true;
}

function closeCampaignModal() {
    showCampaignModal.value = false;
    resetCampaignModal();
}

function openCreateModal() {
    createForm.reset();
    showCreateModal.value = true;
}

function closeCreateModal() {
    showCreateModal.value = false;
    createForm.reset();
}

function submitCreate() {
    createForm.transform((data) => ({
        ...data,
        conversation_id: data.conversation_id ? Number(data.conversation_id) : undefined,
    })).post('/calls', {
        preserveScroll: true,
        onSuccess: () => closeCreateModal(),
    });
}

async function loadLeads(pageNum = 1) {
    if (!campaignForm.list_id) return;

    leadsLoading.value = true;
    leadsError.value = '';

    try {
        const params = new URLSearchParams({
            src: campaignForm.src,
            page: String(pageNum),
        });
        if (leadSearch.value.trim()) {
            params.set('search', leadSearch.value.trim());
        }

        const res = await fetch(`/calls/lead-lists/${encodeURIComponent(campaignForm.list_id)}/leads?${params}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!res.ok) throw new Error('Could not load leads.');

        const json = await res.json();
        listLeads.value = json.data ?? [];
        leadsTotal.value = json.total ?? 0;
        leadsPage.value = json.current_page ?? 1;
        leadsLastPage.value = json.last_page ?? 1;
    } catch {
        leadsError.value = 'Failed to load leads for this list.';
        listLeads.value = [];
    } finally {
        leadsLoading.value = false;
    }
}

function searchLeads() {
    selectAllInList.value = false;
    selectedLeadIds.value = new Set();
    void loadLeads(1);
}

function toggleLead(id: number) {
    selectAllInList.value = false;
    const next = new Set(selectedLeadIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedLeadIds.value = next;
}

function togglePageSelection() {
    selectAllInList.value = false;
    const pageIds = listLeads.value.map((l) => l.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selectedLeadIds.value.has(id));

    const next = new Set(selectedLeadIds.value);
    if (allSelected) pageIds.forEach((id) => next.delete(id));
    else pageIds.forEach((id) => next.add(id));
    selectedLeadIds.value = next;
}

function toggleSelectAllInList() {
    selectAllInList.value = !selectAllInList.value;
    if (selectAllInList.value) selectedLeadIds.value = new Set();
}

const pageAllSelected = computed(() => {
    if (!listLeads.value.length) return false;
    return listLeads.value.every((l) => selectedLeadIds.value.has(l.id));
});

const selectedCount = computed(() => (selectAllInList.value ? leadsTotal.value : selectedLeadIds.value.size));

const selectedList = computed(() => {
    if (!selectedListKey.value) return null;
    return props.leadLists.find((l) => `${l.src}:${l.list_id}` === selectedListKey.value) ?? null;
});

function submitCampaign() {
    campaignForm.lead_ids = [...selectedLeadIds.value];
    campaignForm.select_all = selectAllInList.value;
    campaignForm.post('/calls/from-leads', {
        preserveScroll: true,
        onSuccess: () => closeCampaignModal(),
    });
}

const settingsForm = useForm({
    calendar_url: props.settings.calendar_url,
    booking_message: props.settings.booking_message,
    auto_send_suggestions: props.settings.auto_send_suggestions,
    reminder_hours_before: props.settings.reminder_hours_before ?? [24, 1],
});

const stageMeta: Record<string, { label: string; color: string }> = {
    engaged: { label: 'Engaged', color: 'border-blue-200 bg-blue-50/50' },
    scheduling: { label: 'Scheduling', color: 'border-amber-200 bg-amber-50/50' },
    booked: { label: 'Booked', color: 'border-green-200 bg-green-50/50' },
};

function saveSettings() {
    settingsForm.post('/calls/settings', {
        preserveScroll: true,
        onSuccess: () => { showSettingsModal.value = false; },
    });
}

function displayName(c: CallRow) {
    return c.prospect_name || c.connection_id || `Call #${c.id}`;
}

function conversationLabel(c: (typeof props.conversations)[0]) {
    const id = c.provider_chat_id ? `${c.provider_chat_id.slice(0, 24)}…` : `Thread #${c.id}`;
    const date = c.last_message_at?.slice(0, 10) ?? 'no activity';
    return `${id} · ${date}`;
}

function formatReminderTime(at: string | null) {
    if (!at) return '';
    try {
        return new Date(at).toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return at.slice(0, 16);
    }
}
</script>

<template>
    <Head title="Call Manager" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Call Manager</h1>
                <p class="text-sm text-muted-foreground">Track call booking from LinkedIn — AI suggests replies, you approve in the pipeline.</p>
            </div>
            <div v-if="hasOrg" class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-3 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700"
                    @click="openCampaignModal"
                >
                    <Send class="h-4 w-4" /> Create &amp; start chats
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium hover:bg-muted/50"
                    @click="openCreateModal"
                >
                    <Plus class="h-4 w-4" /> Track one prospect
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium hover:bg-muted/50"
                    @click="showSettingsModal = true"
                >
                    <Settings class="h-4 w-4" /> Settings
                </button>
                <button
                    type="button"
                    class="relative inline-flex items-center justify-center rounded-lg border border-border bg-card p-2 text-sm font-medium hover:bg-muted/50"
                    :class="dueReminders.length ? 'border-orange-500/40 text-orange-600' : 'text-muted-foreground'"
                    title="Upcoming reminders"
                    @click="showRemindersModal = true"
                >
                    <Bell class="h-4 w-4" />
                    <span
                        v-if="dueReminders.length"
                        class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-orange-500 px-1 text-[10px] font-semibold text-white"
                    >
                        {{ dueReminders.length > 9 ? '9+' : dueReminders.length }}
                    </span>
                </button>
            </div>
        </div>

        <p v-if="flashSuccess" class="rounded-lg bg-green-100 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ flashSuccess }}</p>
        <p v-if="flashError" class="rounded-lg bg-red-100 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ flashError }}</p>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400">
            Connect your workspace to manage call booking.
        </div>

        <div v-else-if="!hasUnipile" class="rounded-xl border border-orange-500/30 bg-orange-500/10 p-4 text-sm text-orange-800 dark:text-orange-300">
            Connect LinkedIn under <Link href="/integrations" class="font-medium underline">Integrations</Link> so Unipile can sync chats and send messages.
        </div>

        <div v-if="hasOrg" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground"><Users class="h-4 w-4" /><span class="text-xs font-medium uppercase">In pipeline</span></div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.in_pipeline }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground"><Calendar class="h-4 w-4" /><span class="text-xs font-medium uppercase">Booked</span></div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.booked }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground"><Send class="h-4 w-4" /><span class="text-xs font-medium uppercase">Ready to send</span></div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.ready_to_send }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground"><Clock class="h-4 w-4" /><span class="text-xs font-medium uppercase">Calls today</span></div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.calls_today }}</p>
            </div>
        </div>

        <div v-if="hasOrg && upcoming.length" class="rounded-xl border border-border bg-card shadow-sm">
            <div class="border-b border-border px-4 py-3">
                <h2 class="text-sm font-semibold">Upcoming booked calls</h2>
            </div>
            <ul class="divide-y divide-border">
                <li v-for="c in upcoming" :key="c.id">
                    <Link :href="`/calls/${c.id}`" class="flex items-center justify-between px-4 py-3 text-sm hover:bg-muted/30">
                        <span class="font-medium">{{ displayName(c) }}</span>
                        <span class="text-muted-foreground">{{ c.scheduled_call_at?.slice(0, 16) }}</span>
                    </Link>
                </li>
            </ul>
        </div>

        <div v-if="hasOrg" class="grid gap-4 lg:grid-cols-3">
            <div v-for="(calls, stage) in pipeline" :key="stage" class="rounded-xl border shadow-sm" :class="stageMeta[stage]?.color ?? 'border-border bg-card'">
                <div class="border-b border-border/60 px-4 py-3">
                    <h2 class="text-sm font-semibold">{{ stageMeta[stage]?.label ?? stage }} ({{ calls.length }})</h2>
                </div>
                <div v-if="!calls.length" class="p-6 text-center text-sm text-muted-foreground">No prospects here yet.</div>
                <ul v-else class="divide-y divide-border/60">
                    <li v-for="c in calls" :key="c.id">
                        <Link :href="`/calls/${c.id}`" class="block px-4 py-3 hover:bg-background/60">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium">{{ displayName(c) }}</p>
                                    <p v-if="c.prospect_headline" class="line-clamp-1 text-xs text-muted-foreground">{{ c.prospect_headline }}</p>
                                </div>
                                <Sparkles v-if="c.pending_message" class="h-4 w-4 shrink-0 text-violet-500" />
                            </div>
                            <p v-if="c.pending_message" class="mt-1 line-clamp-2 text-xs text-muted-foreground">{{ c.pending_message }}</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span v-if="c.ready_to_send" class="rounded-full bg-violet-500/10 px-2 py-0.5 text-[10px] font-medium text-violet-700">Ready to send</span>
                                <span v-if="!c.has_conversation" class="rounded-full bg-yellow-500/10 px-2 py-0.5 text-[10px] font-medium text-yellow-700">No chat linked</span>
                            </div>
                        </Link>
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="hasOrg && !stats.in_pipeline" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <Phone class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No active call prospects</p>
            <p class="max-w-md text-sm text-muted-foreground">Start from a lead list or track a single prospect. Replies sync via Unipile — AI drafts your next message in the pipeline.</p>
            <div class="mt-2 flex flex-wrap justify-center gap-2">
                <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground" @click="openCampaignModal">
                    <Send class="h-4 w-4" /> Create &amp; start chats
                </button>
                <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-muted/50" @click="openCreateModal">
                    <Plus class="h-4 w-4" /> Track one prospect
                </button>
            </div>
        </div>
    </div>

    <!-- Campaign modal -->
    <Dialog v-model:open="showCampaignModal">
        <DialogContent class="flex max-h-[90vh] flex-col sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Create &amp; start chats</DialogTitle>
                <DialogDescription>
                    Pick a lead list, select profiles, and open LinkedIn threads.
                    <Link v-if="leadLists.length === 0" href="/leads" class="text-primary underline">Pull leads</Link>
                    <span v-else>Lists come from <Link href="/leads" class="text-primary underline">Leads</Link>.</span>
                </DialogDescription>
            </DialogHeader>

            <form v-if="leadLists.length" class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto py-1" @submit.prevent="submitCampaign">
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Lead list</span>
                    <select v-model="selectedListKey" class="rounded-lg border border-border bg-background px-3 py-2">
                        <option value="">Choose a list…</option>
                        <option v-for="list in leadLists" :key="`${list.src}:${list.list_id}`" :value="`${list.src}:${list.list_id}`">
                            {{ list.list_name }} · {{ list.source }} ({{ list.total_leads }})
                        </option>
                    </select>
                </label>

                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Campaign name (optional)</span>
                    <input v-model="campaignForm.batch_name" type="text" class="rounded-lg border border-border bg-background px-3 py-2" placeholder="Q2 outreach calls" />
                </label>

                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Opening message</span>
                    <textarea v-model="campaignForm.pending_message" rows="3" class="rounded-lg border border-border bg-background px-3 py-2" :placeholder="settings.booking_message || 'Uses your booking template if left blank'" />
                </label>

                <CheckboxField v-model="campaignForm.run" :disabled="!hasUnipile">
                    Start LinkedIn chats now via Unipile
                    <span v-if="!hasUnipile" class="text-xs text-muted-foreground">(connect LinkedIn first)</span>
                </CheckboxField>

                <div v-if="selectedList" class="rounded-lg border border-border bg-muted/20">
                    <div class="flex flex-wrap items-center gap-2 border-b border-border px-3 py-2">
                        <button type="button" class="inline-flex items-center gap-1 text-xs font-medium" :class="pageAllSelected ? 'text-primary' : 'text-muted-foreground'" @click="togglePageSelection">
                            <AppSelectionCheckbox :checked="pageAllSelected" size="sm" /> Select page
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 text-xs font-medium" :class="selectAllInList ? 'text-primary' : 'text-muted-foreground'" @click="toggleSelectAllInList">
                            <AppSelectionCheckbox :checked="selectAllInList" size="sm" /> Select all {{ selectedList.total_leads }}
                        </button>
                        <span class="ml-auto text-xs text-muted-foreground">{{ selectedCount }} selected</span>
                    </div>

                    <div class="flex gap-2 border-b border-border px-3 py-2">
                        <div class="relative flex-1">
                            <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                            <input
                                v-model="leadSearch"
                                type="search"
                                placeholder="Search leads…"
                                class="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm"
                                @keydown.enter.prevent="searchLeads"
                            />
                        </div>
                        <button type="button" class="rounded-lg border border-border px-3 py-2 text-sm hover:bg-muted/50" @click="searchLeads">Search</button>
                    </div>

                    <div v-if="leadsLoading" class="flex items-center justify-center gap-2 p-8 text-sm text-muted-foreground">
                        <Loader2 class="h-4 w-4 animate-spin" /> Loading leads…
                    </div>
                    <p v-else-if="leadsError" class="p-4 text-sm text-red-600">{{ leadsError }}</p>
                    <ul v-else-if="listLeads.length" class="max-h-52 divide-y divide-border overflow-y-auto">
                        <li v-for="lead in listLeads" :key="lead.id">
                            <button
                                type="button"
                                class="flex w-full items-start gap-3 px-3 py-2.5 text-left text-sm hover:bg-muted/30"
                                :class="selectAllInList || selectedLeadIds.has(lead.id) ? 'bg-primary/5' : ''"
                                @click="toggleLead(lead.id)"
                            >
                                <AppSelectionCheckbox :checked="selectAllInList || selectedLeadIds.has(lead.id)" class="mt-0.5" />
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium">{{ lead.name }}</p>
                                    <p v-if="lead.headline" class="line-clamp-1 text-xs text-muted-foreground">{{ lead.headline }}</p>
                                    <p v-if="!lead.profileid" class="text-xs text-orange-600">Missing profile ID — skipped</p>
                                </div>
                            </button>
                        </li>
                    </ul>
                    <p v-else class="p-6 text-center text-sm text-muted-foreground">No leads in this list.</p>

                    <div v-if="leadsLastPage > 1" class="flex items-center justify-between border-t border-border px-3 py-2 text-xs">
                        <button type="button" class="text-primary disabled:opacity-40" :disabled="leadsPage <= 1 || leadsLoading" @click="loadLeads(leadsPage - 1)">Previous</button>
                        <span class="text-muted-foreground">Page {{ leadsPage }} of {{ leadsLastPage }}</span>
                        <button type="button" class="text-primary disabled:opacity-40" :disabled="leadsPage >= leadsLastPage || leadsLoading" @click="loadLeads(leadsPage + 1)">Next</button>
                    </div>
                </div>
            </form>

            <p v-else class="py-6 text-center text-sm text-muted-foreground">
                No lead lists yet. <Link href="/leads" class="text-primary underline">Pull leads</Link> first.
            </p>

            <DialogFooter class="gap-2 sm:gap-0">
                <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50" @click="closeCampaignModal">Cancel</button>
                <button
                    v-if="leadLists.length"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    :disabled="campaignForm.processing || !selectedList || selectedCount === 0"
                    @click="submitCampaign"
                >
                    <Loader2 v-if="campaignForm.processing" class="h-4 w-4 animate-spin" />
                    <Send v-else class="h-4 w-4" />
                    {{ campaignForm.run ? 'Create & start chats' : 'Add to pipeline' }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Track one prospect modal -->
    <Dialog v-model:open="showCreateModal">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Track one prospect</DialogTitle>
                <DialogDescription>Add a single prospect to the call pipeline — optionally link an existing conversation.</DialogDescription>
            </DialogHeader>

            <form class="grid gap-3" @submit.prevent="submitCreate">
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">LinkedIn conversation (optional)</span>
                    <select v-model="createForm.conversation_id" class="rounded-lg border border-border bg-background px-3 py-2">
                        <option value="">None — track by name only</option>
                        <option v-for="c in conversations" :key="c.id" :value="c.id">
                            {{ conversationLabel(c) }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Prospect name</span>
                    <input v-model="createForm.prospect_name" type="text" class="rounded-lg border border-border bg-background px-3 py-2" placeholder="Jane Doe" />
                </label>
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Opening message (optional)</span>
                    <textarea v-model="createForm.pending_message" rows="3" class="rounded-lg border border-border bg-background px-3 py-2" placeholder="Uses your booking template if left blank" />
                </label>

                <DialogFooter class="gap-2 pt-2 sm:gap-0">
                    <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50" @click="closeCreateModal">Cancel</button>
                    <button type="submit" class="rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="createForm.processing">
                        <Loader2 v-if="createForm.processing" class="mr-2 inline h-4 w-4 animate-spin" />
                        Add to pipeline
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Settings modal -->
    <Dialog v-model:open="showSettingsModal">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Booking settings</DialogTitle>
                <DialogDescription>Calendar link and message templates for call booking outreach.</DialogDescription>
            </DialogHeader>

            <form class="grid gap-3" @submit.prevent="saveSettings">
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Calendar URL</span>
                    <input v-model="settingsForm.calendar_url" type="url" placeholder="https://calendly.com/you/15min" class="rounded-lg border border-border bg-background px-3 py-2" />
                </label>
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Booking message template</span>
                    <textarea v-model="settingsForm.booking_message" rows="3" class="rounded-lg border border-border bg-background px-3 py-2" />
                    <span class="text-xs text-muted-foreground">Use <code>{calendar_url}</code> as placeholder.</span>
                </label>
                <CheckboxField v-model="settingsForm.auto_send_suggestions">
                    Auto-send AI suggestions via Unipile
                </CheckboxField>

                <DialogFooter class="gap-2 pt-2 sm:gap-0">
                    <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50" @click="showSettingsModal = false">Cancel</button>
                    <button type="submit" class="rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="settingsForm.processing">Save settings</button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Reminders modal -->
    <Dialog v-model:open="showRemindersModal">
        <DialogContent class="flex max-h-[85vh] flex-col sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Bell class="h-4 w-4 text-orange-500" />
                    Upcoming reminders
                    <span v-if="dueReminders.length" class="rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-normal text-orange-700">
                        {{ dueReminders.length }}
                    </span>
                </DialogTitle>
                <DialogDescription>
                    Follow-up reminders scheduled for prospects in your pipeline.
                </DialogDescription>
            </DialogHeader>

            <div v-if="!dueReminders.length" class="py-8 text-center text-sm text-muted-foreground">
                No upcoming reminders.
            </div>
            <ul v-else class="min-h-0 flex-1 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                <li v-for="r in dueReminders" :key="r.id" class="px-4 py-3 text-sm">
                    <Link
                        v-if="r.call_id"
                        :href="`/calls/${r.call_id}`"
                        class="block hover:bg-muted/30 -mx-4 px-4 py-1"
                        @click="showRemindersModal = false"
                    >
                        <p class="font-medium">{{ r.prospect_name ?? 'Prospect' }}</p>
                        <p class="mt-0.5 line-clamp-2 text-muted-foreground">{{ r.message }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Due {{ formatReminderTime(r.send_at) }}</p>
                    </Link>
                    <div v-else>
                        <p class="font-medium">{{ r.prospect_name ?? 'Prospect' }}</p>
                        <p class="mt-0.5 line-clamp-2 text-muted-foreground">{{ r.message }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Due {{ formatReminderTime(r.send_at) }}</p>
                    </div>
                </li>
            </ul>

            <DialogFooter>
                <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50" @click="showRemindersModal = false">
                    Close
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
