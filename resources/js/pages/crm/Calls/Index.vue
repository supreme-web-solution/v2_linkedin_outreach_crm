<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Bell, Calendar, ChevronRight, Clock, Loader2, Phone, Plus, Search, Send, Settings, Sparkles, Users } from '@lucide/vue';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import { Button } from '@/components/ui/button';
import SearchableSelect from '@/components/crm/SearchableSelect.vue';
import ToggleField from '@/components/ToggleField.vue';
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
    batch_id: string | null;
    batch_name: string | null;
}

interface CallFlow {
    batch_id: string | null;
    batch_name: string;
    flow_key?: string;
    count: number;
    ready_to_send: number;
    auto_send_enabled: boolean;
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
    pipelineTotals: { engaged: number; scheduling: number; booked: number };
    pipelineLimit: number;
    upcoming: CallRow[];
    upcomingTotal?: number;
    dueReminders: Array<{ id: number; message: string; send_at: string; call_id: number | null; prospect_name: string | null }>;
    stats: { in_pipeline: number; booked: number; ready_to_send: number; calls_today: number };
    settings: {
        calendar_url: string;
        booking_message: string;
        auto_send_suggestions: boolean;
        reminder_hours_before: number[];
        calendar_id?: string;
        call_duration_minutes?: number;
        use_unipile_calendar?: boolean;
        use_app_booking_link?: boolean;
        booking_days_ahead?: number;
        booking_hours_start?: number;
        booking_hours_end?: number;
        calendar_timezone?: string;
    };
    hasOrg: boolean;
    hasUnipile: boolean;
    hasCalendarIntegration?: boolean;
    calendarOptions?: Array<{ id: string; name: string; primary: boolean }>;
    leadLists: LeadList[];
    conversations: Array<{
        id: number;
        prospect_name: string | null;
        prospect_headline: string | null;
        provider_chat_id: string | null;
        last_message_at: string | null;
    }>;
    flows?: CallFlow[];
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const showCampaignModal = ref(false);
const campaignWizardStep = ref(1);
const showCreateModal = ref(false);
const showSettingsModal = ref(false);
const settingsWizardStep = ref(1);
const showRemindersModal = ref(false);
const showUpcomingModal = ref(false);
const upcomingModalItems = ref<CallRow[]>([]);
const upcomingModalPage = ref(0);
const upcomingModalHasMore = ref(false);
const upcomingModalLoading = ref(false);
const upcomingModalTotal = ref(0);

const upcomingPreview = computed(() => props.upcoming.slice(0, 4));
const upcomingTotal = computed(() => props.upcomingTotal ?? props.upcoming.length);
const hasMoreUpcoming = computed(() => upcomingTotal.value > upcomingPreview.value.length);

watch(showSettingsModal, (open) => {
    if (open) {
        settingsWizardStep.value = 1;
    }
});

const settingsStepDescription = computed(() => {
    if (settingsWizardStep.value === 1) {
        if (props.hasCalendarIntegration && settingsForm.use_app_booking_link) {
            return 'Each prospect gets a unique in-app booking page with your live availability.';
        }
        return 'Paste your Calendly or external calendar link for prospects to book.';
    }
    if (settingsWizardStep.value === 2) {
        return 'Sync booked calls to Google Calendar or Outlook and set availability windows.';
    }
    return 'Opening message template and AI reply behavior for new flows.';
});

const createForm = useForm({
    conversation_id: '' as string | number,
    prospect_name: '',
    pending_message: '',
});

const campaignForm = useForm({
    batch_name: '',
    pending_message: '',
    run: true,
    auto_send_suggestions: props.settings.auto_send_suggestions,
    lists: [] as Array<{ list_id: string; src: 'aud' | 'sn'; select_all: boolean; lead_ids: number[] }>,
});

interface ListSelection {
    selectAll: boolean;
    leadIds: Set<number>;
}

const selectedListKeys = ref<Set<string>>(new Set());
const activeListKey = ref('');
const listSelections = ref<Record<string, ListSelection>>({});
const leadSearch = ref('');
const leadsLoading = ref(false);
const leadsError = ref('');
const listLeads = ref<LeadRow[]>([]);
const leadsTotal = ref(0);
const leadsPage = ref(1);
const leadsLastPage = ref(1);
const selectAllInList = ref(false);

function listKeyFor(list: LeadList) {
    return `${list.src}:${list.list_id}`;
}

function selectionFor(key: string): ListSelection {
    if (!listSelections.value[key]) {
        listSelections.value[key] = { selectAll: false, leadIds: new Set() };
    }
    return listSelections.value[key];
}

function syncSelectedListKeys(keys: string[]) {
    const prev = selectedListKeys.value;
    const next = new Set(keys);

    for (const key of prev) {
        if (!next.has(key)) {
            delete listSelections.value[key];
        }
    }

    for (const key of next) {
        if (!prev.has(key)) {
            selectionFor(key);
        }
    }

    selectedListKeys.value = next;

    if (!activeListKey.value || !next.has(activeListKey.value)) {
        activeListKey.value = keys[0] ?? '';
    }

    syncActiveList();
}

const selectedListKeysModel = computed({
    get: () => [...selectedListKeys.value],
    set: (keys: string[]) => syncSelectedListKeys(keys),
});

const leadListOptions = computed(() =>
    props.leadLists.map((list) => ({
        value: listKeyFor(list),
        label: list.list_name,
        sublabel: `${list.source} · ${list.total_leads} leads`,
    })),
);

function truncateText(text: string, max = 56) {
    const trimmed = text.trim();
    if (trimmed.length <= max) return trimmed;
    return `${trimmed.slice(0, max - 1)}…`;
}

const conversationOptions = computed(() =>
    props.conversations.map((c) => ({
        value: String(c.id),
        label: truncateText(c.prospect_name?.trim() || 'Unknown contact', 40),
        sublabel: c.prospect_headline
            ? truncateText(c.prospect_headline, 52)
            : (c.last_message_at?.slice(0, 10) ?? undefined),
    })),
);

const createConversationId = computed({
    get: () => (createForm.conversation_id ? String(createForm.conversation_id) : ''),
    set: (value: string) => {
        createForm.conversation_id = value ? Number(value) : '';
    },
});

function setActiveList(key: string) {
    activeListKey.value = key;
    syncActiveList();
}

function syncActiveList() {
    selectAllInList.value = false;
    listLeads.value = [];
    leadsPage.value = 1;

    if (!activeListKey.value) {
        return;
    }

    const sel = listSelections.value[activeListKey.value];
    selectAllInList.value = sel?.selectAll ?? false;
    void loadLeads(1);
}

watch(() => createForm.conversation_id, (id) => {
    if (!id) return;
    const conv = props.conversations.find((c) => String(c.id) === String(id));
    if (conv?.prospect_name) {
        createForm.prospect_name = conv.prospect_name;
    }
});

function resetCampaignModal() {
    campaignWizardStep.value = 1;
    selectedListKeys.value = new Set();
    activeListKey.value = '';
    listSelections.value = {};
    selectAllInList.value = false;
    leadSearch.value = '';
    listLeads.value = [];
    campaignForm.reset();
    campaignForm.run = true;
    campaignForm.auto_send_suggestions = props.settings.auto_send_suggestions;
}

function openCampaignModal() {
    resetCampaignModal();
    showCampaignModal.value = true;
}

function closeCampaignModal() {
    showCampaignModal.value = false;
    resetCampaignModal();
}

function goCampaignStep2() {
    if (!campaignForm.batch_name.trim()) return;
    campaignWizardStep.value = 2;
    if (selectedListKeys.value.size && activeListKey.value) {
        syncActiveList();
    }
}

function goCampaignStep1() {
    campaignWizardStep.value = 1;
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
    if (!activeListKey.value) return;

    const list = props.leadLists.find((l) => listKeyFor(l) === activeListKey.value);
    if (!list) return;

    leadsLoading.value = true;
    leadsError.value = '';

    try {
        const params = new URLSearchParams({
            src: list.src,
            page: String(pageNum),
        });
        if (leadSearch.value.trim()) {
            params.set('search', leadSearch.value.trim());
        }

        const res = await fetch(`/calls/lead-lists/${encodeURIComponent(list.list_id)}/leads?${params}`, {
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
    if (activeListKey.value) {
        selectionFor(activeListKey.value).selectAll = false;
        selectAllInList.value = false;
    }
    void loadLeads(1);
}

function toggleLead(id: number) {
    if (!activeListKey.value) return;
    const sel = selectionFor(activeListKey.value);
    sel.selectAll = false;
    selectAllInList.value = false;
    const next = new Set(sel.leadIds);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    sel.leadIds = next;
}

function togglePageSelection() {
    if (!activeListKey.value) return;
    const sel = selectionFor(activeListKey.value);
    sel.selectAll = false;
    selectAllInList.value = false;
    const pageIds = listLeads.value.map((l) => l.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => sel.leadIds.has(id));

    const next = new Set(sel.leadIds);
    if (allSelected) pageIds.forEach((id) => next.delete(id));
    else pageIds.forEach((id) => next.add(id));
    sel.leadIds = next;
}

function toggleSelectAllInList() {
    if (!activeListKey.value) return;
    const sel = selectionFor(activeListKey.value);
    sel.selectAll = !sel.selectAll;
    selectAllInList.value = sel.selectAll;
    if (sel.selectAll) sel.leadIds = new Set();
}

const pageAllSelected = computed(() => {
    if (!activeListKey.value || !listLeads.value.length) return false;
    const sel = listSelections.value[activeListKey.value];
    if (!sel || sel.selectAll) return false;
    return listLeads.value.every((l) => sel.leadIds.has(l.id));
});

const selectedCount = computed(() => {
    let total = 0;
    for (const key of selectedListKeys.value) {
        const list = props.leadLists.find((l) => listKeyFor(l) === key);
        const sel = listSelections.value[key];
        if (!list || !sel) continue;
        total += sel.selectAll ? list.total_leads : sel.leadIds.size;
    }
    return total;
});

const activeList = computed(() => {
    if (!activeListKey.value) return null;
    return props.leadLists.find((l) => listKeyFor(l) === activeListKey.value) ?? null;
});

const activeSelection = computed(() => {
    if (!activeListKey.value) return null;
    return listSelections.value[activeListKey.value] ?? null;
});

function isLeadSelected(id: number) {
    const sel = activeSelection.value;
    if (!sel) return false;
    return sel.selectAll || sel.leadIds.has(id);
}

function submitCampaign() {
    if (!campaignForm.batch_name.trim()) return;

    campaignForm.lists = [...selectedListKeys.value].map((key) => {
        const colon = key.indexOf(':');
        const src = key.slice(0, colon) as 'aud' | 'sn';
        const listId = key.slice(colon + 1);
        const sel = selectionFor(key);
        return {
            list_id: listId,
            src,
            select_all: sel.selectAll,
            lead_ids: [...sel.leadIds],
        };
    });

    campaignForm.post('/calls/from-leads', {
        preserveScroll: true,
        onSuccess: () => closeCampaignModal(),
    });
}

function resolveOpeningMessage(override?: string) {
    const link = props.hasCalendarIntegration && props.settings.use_app_booking_link
        ? `${window.location.origin}/book/[prospect-link]`
        : (props.settings.calendar_url?.trim() || '[your calendar link]');
    const custom = (override ?? '').trim();
    if (custom) {
        return custom.includes('{calendar_url}')
            ? custom.replaceAll('{calendar_url}', link)
            : custom;
    }
    const template = props.settings.booking_message?.trim()
        || 'Would you be open to a quick 15-minute call? Here is my calendar: {calendar_url}';
    return template.replaceAll('{calendar_url}', link);
}

const campaignOpeningPreview = computed(() => resolveOpeningMessage(campaignForm.pending_message));
const createOpeningPreview = computed(() => resolveOpeningMessage(createForm.pending_message));
const showCampaignTemplatePreview = computed(() => !campaignForm.pending_message.trim());
const showCreateTemplatePreview = computed(() => !createForm.pending_message.trim());

const flows = computed(() => props.flows ?? []);

function flowConversationsHref(flow: CallFlow) {
    const key = flow.flow_key ?? (flow.batch_id ?? 'individual');
    return `/conversations/flows/${encodeURIComponent(key)}`;
}

function stageConversationsHref(stage: string) {
    return `/conversations/flows/all?stage=${encodeURIComponent(stage)}`;
}

const settingsForm = useForm({
    calendar_url: props.settings.calendar_url,
    booking_message: props.settings.booking_message,
    auto_send_suggestions: props.settings.auto_send_suggestions,
    reminder_hours_before: props.settings.reminder_hours_before ?? [24, 1],
    calendar_id: props.settings.calendar_id ?? '',
    call_duration_minutes: props.settings.call_duration_minutes ?? 30,
    use_unipile_calendar: props.settings.use_unipile_calendar ?? true,
    use_app_booking_link: props.settings.use_app_booking_link ?? true,
    booking_days_ahead: props.settings.booking_days_ahead ?? 14,
    booking_hours_start: props.settings.booking_hours_start ?? 9,
    booking_hours_end: props.settings.booking_hours_end ?? 17,
});

const calendarSelectOptions = computed(() =>
    (props.calendarOptions ?? []).map((c) => ({
        value: c.id,
        label: c.primary ? `${c.name} (primary)` : c.name,
    })),
);

const settingsTemplatePreview = computed(() => {
    const template = settingsForm.booking_message?.trim()
        || 'Would you be open to a quick 15-minute call? Here is my calendar: {calendar_url}';
    const link = props.hasCalendarIntegration && settingsForm.use_app_booking_link
        ? `${window.location.origin}/book/[prospect-link]`
        : (settingsForm.calendar_url?.trim() || '[calendar link]');
    return template.replaceAll('{calendar_url}', link);
});

const showManualCalendarUrl = computed(() => {
    return !props.hasCalendarIntegration || !settingsForm.use_app_booking_link;
});

const bookingLinksStepValid = computed(() => {
    if (!showManualCalendarUrl.value) {
        return true;
    }
    return !!settingsForm.calendar_url?.trim();
});

const stageMeta: Record<string, { label: string; color: string }> = {
    engaged: { label: 'Engaged', color: 'border-blue-200 bg-blue-50/50' },
    scheduling: { label: 'Scheduling', color: 'border-amber-200 bg-amber-50/50' },
    booked: { label: 'Booked', color: 'border-green-200 bg-green-50/50' },
};

function saveSettings() {
    if (!bookingLinksStepValid.value) {
        settingsWizardStep.value = 1;
        settingsForm.setError('calendar_url', 'Add your Calendly or calendar link, or turn on in-app booking links.');
        return;
    }

    settingsForm.post('/calls/settings', {
        preserveScroll: true,
        onSuccess: () => { showSettingsModal.value = false; },
    });
}

function nextSettingsStep() {
    if (settingsWizardStep.value === 1 && !bookingLinksStepValid.value) {
        settingsForm.setError('calendar_url', 'Add your Calendly or calendar link, or turn on in-app booking links.');
        return;
    }

    settingsForm.clearErrors('calendar_url');

    if (settingsWizardStep.value < 3) {
        settingsWizardStep.value += 1;
    }
}

function prevSettingsStep() {
    if (settingsWizardStep.value > 1) {
        settingsWizardStep.value -= 1;
    }
}

function displayName(c: CallRow) {
    return c.prospect_name || c.connection_id || `Call #${c.id}`;
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

function formatCallTime(at: string | null) {
    return formatReminderTime(at);
}

async function fetchUpcomingPage(page: number, append = false) {
    upcomingModalLoading.value = true;
    try {
        const response = await fetch(`/calls/upcoming?page=${page}&per_page=10`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) {
            throw new Error('Failed to load upcoming calls');
        }
        const payload = await response.json() as {
            data: CallRow[];
            total: number;
            has_more: boolean;
        };
        upcomingModalTotal.value = payload.total ?? 0;
        upcomingModalHasMore.value = Boolean(payload.has_more);
        upcomingModalPage.value = page;
        upcomingModalItems.value = append
            ? [...upcomingModalItems.value, ...(payload.data ?? [])]
            : (payload.data ?? []);
    } finally {
        upcomingModalLoading.value = false;
    }
}

function openUpcomingModal() {
    showUpcomingModal.value = true;
    upcomingModalItems.value = [];
    upcomingModalPage.value = 0;
    void fetchUpcomingPage(1, false);
}

function loadMoreUpcoming() {
    if (upcomingModalLoading.value || !upcomingModalHasMore.value) {
        return;
    }
    void fetchUpcomingPage(upcomingModalPage.value + 1, true);
}

watch(showUpcomingModal, (open) => {
    if (!open) {
        upcomingModalItems.value = [];
        upcomingModalPage.value = 0;
        upcomingModalHasMore.value = false;
    }
});
</script>

<template>
    <Head title="Call Manager" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <LinkedInPageHeading title="Call Manager" show-badge>
                    <template #subtitle>
                        Lightweight overview — open
                        <Link href="/conversations" class="font-medium text-primary underline">Conversations</Link>
                        to message prospects grouped by call flow.
                    </template>
                </LinkedInPageHeading>
            </div>
            <div v-if="hasOrg" class="flex flex-wrap gap-2">
                <AppToolbarButton @click="openCampaignModal">
                    <Send class="h-4 w-4" /> Create &amp; start chats
                </AppToolbarButton>
                <AppToolbarButton variant="violet" @click="openCreateModal">
                    <Plus class="h-4 w-4" /> Track one prospect
                </AppToolbarButton>
                <AppToolbarButton variant="slate" @click="showSettingsModal = true">
                    <Settings class="h-4 w-4" /> Settings
                </AppToolbarButton>
                <Button
                    variant="amber"
                    size="toolbar"
                    class="relative"
                    :class="dueReminders.length ? '' : 'opacity-90'"
                    title="Upcoming reminders"
                    @click="showRemindersModal = true"
                >
                    <Bell class="h-4 w-4" />
                    <span
                        v-if="dueReminders.length"
                        class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-white px-1 text-[10px] font-semibold text-amber-600"
                    >
                        {{ dueReminders.length > 9 ? '9+' : dueReminders.length }}
                    </span>
                </Button>
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

        <div v-if="hasOrg && flows.length" class="rounded-xl border border-border bg-card shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-3">
                <div>
                    <h2 class="text-sm font-semibold">Call flows</h2>
                    <p class="text-xs text-muted-foreground">
                        Bulk launches become flows. Messages and grouping live on Conversations.
                    </p>
                </div>
                <Link href="/conversations" class="text-xs font-medium text-primary underline">Open Conversations →</Link>
            </div>
            <div class="flex flex-wrap gap-2 p-3">
                <Link
                    v-for="flow in flows"
                    :key="flow.flow_key ?? flow.batch_id ?? '__individual__'"
                    :href="flowConversationsHref(flow)"
                    class="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-primary"
                >
                    {{ flow.batch_name }}
                    <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px]">{{ flow.count }}</span>
                    <span v-if="flow.ready_to_send" class="rounded-full bg-violet-500/10 px-1.5 py-0.5 text-[10px] text-violet-700">{{ flow.ready_to_send }} ready</span>
                </Link>
            </div>
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

        <div v-if="hasOrg && upcomingPreview.length" class="rounded-xl border border-border bg-card shadow-sm">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <h2 class="text-sm font-semibold">Upcoming booked calls</h2>
                <button
                    v-if="hasMoreUpcoming"
                    type="button"
                    class="text-xs font-medium text-primary hover:underline"
                    @click="openUpcomingModal"
                >
                    See all ({{ upcomingTotal ?? upcoming.length }})
                </button>
            </div>
            <ul class="divide-y divide-border">
                <li v-for="c in upcomingPreview" :key="c.id">
                    <Link :href="`/calls/${c.id}`" class="flex items-center justify-between px-4 py-3 text-sm hover:bg-muted/30">
                        <span class="font-medium">{{ displayName(c) }}</span>
                        <span class="text-muted-foreground">{{ formatCallTime(c.scheduled_call_at) }}</span>
                    </Link>
                </li>
            </ul>
        </div>

        <div v-if="hasOrg" class="grid gap-4 lg:grid-cols-3">
            <div v-for="(calls, stage) in pipeline" :key="stage" class="rounded-xl border shadow-sm" :class="stageMeta[stage]?.color ?? 'border-border bg-card'">
                <div class="flex items-center justify-between gap-2 border-b border-border/60 px-4 py-3">
                    <h2 class="text-sm font-semibold">
                        {{ stageMeta[stage]?.label ?? stage }}
                        ({{ pipelineTotals[stage as keyof typeof pipelineTotals] ?? 0 }})
                    </h2>
                    <Link
                        v-if="(pipelineTotals[stage as keyof typeof pipelineTotals] ?? 0) > calls.length"
                        :href="stageConversationsHref(String(stage))"
                        class="shrink-0 text-[10px] font-medium text-primary hover:underline"
                    >
                        View all →
                    </Link>
                </div>
                <div v-if="!calls.length" class="p-6 text-center text-sm text-muted-foreground">No prospects here yet.</div>
                <ul v-else class="divide-y divide-border/60">
                    <li v-for="c in calls" :key="c.id">
                        <Link :href="`/calls/${c.id}`" class="block px-4 py-3 hover:bg-background/60">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium">{{ displayName(c) }}</p>
                                    <p v-if="c.prospect_headline" class="line-clamp-1 text-xs text-muted-foreground">{{ c.prospect_headline }}</p>
                                    <p v-if="c.batch_name" class="mt-1 text-[10px] font-medium text-primary/80">{{ c.batch_name }}</p>
                                </div>
                                <Sparkles v-if="c.pending_message" class="h-4 w-4 shrink-0 text-violet-500" />
                            </div>
                            <p v-if="c.pending_message" class="mt-1 line-clamp-2 text-xs text-muted-foreground">{{ c.pending_message }}</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span v-if="c.ready_to_send" class="rounded-full bg-violet-500/10 px-2 py-0.5 text-[10px] font-medium text-violet-700">Ready to send</span>
                                <span v-if="!c.has_conversation" class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-medium text-amber-800">Chat not started</span>
                            </div>
                        </Link>
                    </li>
                </ul>
                <p v-if="(pipelineTotals[stage as keyof typeof pipelineTotals] ?? 0) > pipelineLimit" class="border-t border-border/60 px-4 py-2 text-center text-[10px] text-muted-foreground">
                    Latest {{ pipelineLimit }} shown —
                    <Link :href="stageConversationsHref(String(stage))" class="text-primary hover:underline">see all in Conversations</Link>
                </p>
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
        <DialogContent class="!flex h-[min(85vh,720px)] max-h-[90vh] flex-col overflow-hidden sm:max-w-2xl">
            <DialogHeader class="shrink-0">
                <DialogTitle>Create &amp; start chats</DialogTitle>
                <DialogDescription>
                    <template v-if="campaignWizardStep === 1">
                        Name your call flow and choose how to open the conversation. You’ll pick prospects on the next step.
                    </template>
                    <template v-else>
                        Select lead lists and choose who to add to
                        <span class="font-medium text-foreground">{{ campaignForm.batch_name.trim() || 'this flow' }}</span>.
                        <Link href="/leads" class="text-primary underline">Pull more leads</Link> if needed.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="leadLists.length" class="flex shrink-0 items-center gap-2 border-b border-border pb-3 text-xs">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium"
                    :class="campaignWizardStep === 1 ? 'bg-primary/10 text-primary' : 'text-muted-foreground'"
                >
                    <span class="flex h-4 w-4 items-center justify-center rounded-full text-[10px]" :class="campaignWizardStep === 1 ? 'bg-primary text-primary-foreground' : 'bg-muted'">1</span>
                    Flow details
                </span>
                <ChevronRight class="h-3.5 w-3.5 text-muted-foreground" />
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium"
                    :class="campaignWizardStep === 2 ? 'bg-primary/10 text-primary' : 'text-muted-foreground'"
                >
                    <span class="flex h-4 w-4 items-center justify-center rounded-full text-[10px]" :class="campaignWizardStep === 2 ? 'bg-primary text-primary-foreground' : 'bg-muted'">2</span>
                    Pick prospects
                </span>
            </div>

            <form
                v-if="leadLists.length"
                class="flex min-h-0 flex-1 flex-col gap-4 overflow-hidden py-1"
                @submit.prevent="submitCampaign"
            >
                <!-- Step 1: flow name, message, settings -->
                <div v-if="campaignWizardStep === 1" class="min-h-0 flex-1 overflow-y-auto">
                    <div class="grid gap-4">
                    <label class="grid gap-1 text-sm">
                        <span class="font-medium">Flow name <span class="text-red-500">*</span></span>
                        <input v-model="campaignForm.batch_name" type="text" required class="rounded-lg border border-border bg-background px-3 py-2" placeholder="Q2 discovery calls" />
                        <span class="text-xs text-muted-foreground">Groups these prospects together — like a mini campaign.</span>
                    </label>

                    <label class="grid gap-1 text-sm">
                        <span class="font-medium">Opening message</span>
                        <textarea v-model="campaignForm.pending_message" rows="4" class="rounded-lg border border-border bg-background px-3 py-2" placeholder="Leave blank to use your booking template from Settings" />
                    </label>

                    <div v-if="showCampaignTemplatePreview" class="rounded-lg border border-dashed border-primary/30 bg-primary/5 px-3 py-2.5 text-sm">
                        <p class="text-xs font-medium uppercase tracking-wide text-primary">Will send (from Settings template)</p>
                        <p class="mt-1 whitespace-pre-wrap text-muted-foreground">{{ campaignOpeningPreview }}</p>
                        <button type="button" class="mt-2 text-xs font-medium text-primary hover:underline" @click="showCampaignModal = false; showSettingsModal = true">
                            Edit default template in Settings →
                        </button>
                    </div>
                    <p v-else class="text-xs text-muted-foreground">Custom opening message for this flow only.</p>

                    <ToggleField
                        v-model="campaignForm.auto_send_suggestions"
                        description="When on, AI draft replies send automatically for every prospect in this flow. Turn off to review each chat before sending."
                    >
                        AI auto-send for this flow
                    </ToggleField>
                    <p v-if="campaignForm.auto_send_suggestions" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                        Replies go out without manual approval. You can change this later on the flow page in Conversations.
                    </p>

                    <ToggleField
                        v-model="campaignForm.run"
                        :disabled="!hasUnipile"
                        :description="hasUnipile
                            ? ''
                            : 'Connect LinkedIn under Integrations first.'"
                    >
                        Start LinkedIn chats now
                    </ToggleField>
                    </div>
                </div>

                <!-- Step 2: lists + prospect picker (full height) -->
                <div v-else class="flex min-h-0 flex-1 flex-col gap-3">
                    <div class="relative z-[60] grid min-w-0 shrink-0 gap-1 text-sm">
                        <span class="font-medium">Lead lists</span>
                        <p class="text-xs text-muted-foreground">Search and select one or more lists, then pick prospects from each.</p>
                        <SearchableSelect
                            v-model="selectedListKeysModel"
                            multiple
                            :options="leadListOptions"
                            placeholder="Search lead lists…"
                            search-placeholder="Search lists…"
                            empty-text="No lists match your search"
                            panel-max-height-class="max-h-72"
                        />
                        <p v-if="selectedListKeys.size" class="text-xs text-muted-foreground">
                            {{ selectedListKeys.size }} list{{ selectedListKeys.size === 1 ? '' : 's' }} · {{ selectedCount }} prospect{{ selectedCount === 1 ? '' : 's' }} selected
                        </p>
                    </div>

                    <div
                        v-if="selectedListKeys.size"
                        class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-muted/20"
                    >
                        <div class="shrink-0 border-b border-border bg-muted/30 px-3 py-2">
                            <p class="text-xs font-medium">Select prospects</p>
                            <p class="text-[11px] text-muted-foreground">Choose who to add from each list.</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-1 border-b border-border px-2 py-2">
                            <button
                                v-for="key in [...selectedListKeys]"
                                :key="key"
                                type="button"
                                class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                                :class="activeListKey === key ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted/50'"
                                @click="setActiveList(key)"
                            >
                                {{ leadLists.find((l) => listKeyFor(l) === key)?.list_name ?? 'List' }}
                            </button>
                        </div>
                        <div v-if="activeList" class="flex shrink-0 flex-wrap items-center gap-2 border-b border-border px-3 py-2">
                            <button type="button" class="inline-flex items-center gap-1 text-xs font-medium" :class="pageAllSelected ? 'text-primary' : 'text-muted-foreground'" @click="togglePageSelection">
                                <AppSelectionCheckbox :checked="pageAllSelected" size="sm" /> Select page
                            </button>
                            <button type="button" class="inline-flex items-center gap-1 text-xs font-medium" :class="selectAllInList ? 'text-primary' : 'text-muted-foreground'" @click="toggleSelectAllInList">
                                <AppSelectionCheckbox :checked="selectAllInList" size="sm" /> Select all {{ activeList.total_leads }} in this list
                            </button>
                            <span class="ml-auto text-xs text-muted-foreground">{{ selectedCount }} total selected</span>
                        </div>

                        <div class="flex shrink-0 gap-2 border-b border-border px-3 py-2">
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

                        <div v-if="leadsLoading" class="flex flex-1 items-center justify-center gap-2 p-8 text-sm text-muted-foreground">
                            <Loader2 class="h-4 w-4 animate-spin" /> Loading leads…
                        </div>
                        <p v-else-if="leadsError" class="p-4 text-sm text-red-600">{{ leadsError }}</p>
                        <ul v-else-if="listLeads.length" class="min-h-0 flex-1 divide-y divide-border overflow-y-auto">
                            <li v-for="lead in listLeads" :key="lead.id">
                                <button
                                    type="button"
                                    class="flex w-full items-start gap-3 px-3 py-2.5 text-left text-sm hover:bg-muted/30"
                                    :class="isLeadSelected(lead.id) ? 'bg-primary/5' : ''"
                                    @click="toggleLead(lead.id)"
                                >
                                    <AppSelectionCheckbox :checked="isLeadSelected(lead.id)" class="mt-0.5" />
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium">{{ lead.name }}</p>
                                        <p v-if="lead.headline" class="line-clamp-2 text-xs text-muted-foreground">{{ lead.headline }}</p>
                                        <p v-if="!lead.profileid" class="text-xs text-orange-600">Missing profile ID — skipped</p>
                                    </div>
                                </button>
                            </li>
                        </ul>
                        <p v-else class="flex flex-1 items-center justify-center p-6 text-center text-sm text-muted-foreground">No leads in this list.</p>

                        <div v-if="leadsLastPage > 1" class="flex shrink-0 items-center justify-between border-t border-border px-3 py-2 text-xs">
                            <button type="button" class="text-primary disabled:opacity-40" :disabled="leadsPage <= 1 || leadsLoading" @click="loadLeads(leadsPage - 1)">Previous</button>
                            <span class="text-muted-foreground">Page {{ leadsPage }} of {{ leadsLastPage }}</span>
                            <button type="button" class="text-primary disabled:opacity-40" :disabled="leadsPage >= leadsLastPage || leadsLoading" @click="loadLeads(leadsPage + 1)">Next</button>
                        </div>
                    </div>

                    <div v-else class="flex min-h-0 flex-1 items-center justify-center rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                        Select at least one lead list above to browse and pick prospects.
                    </div>
                </div>
            </form>

            <p v-else class="py-6 text-center text-sm text-muted-foreground">
                No lead lists yet. <Link href="/leads" class="text-primary underline">Pull leads</Link> first.
            </p>

            <DialogFooter class="shrink-0 gap-2 sm:gap-0">
                <template v-if="leadLists.length && campaignWizardStep === 1">
                    <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50" @click="closeCampaignModal">Cancel</button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="!campaignForm.batch_name.trim()"
                        @click="goCampaignStep2"
                    >
                        Next
                        <ChevronRight class="h-4 w-4" />
                    </button>
                </template>

                <template v-else-if="leadLists.length && campaignWizardStep === 2">
                    <p v-if="selectedListKeys.size && selectedCount === 0" class="mr-auto text-xs text-muted-foreground">
                        Select at least one prospect to continue.
                    </p>
                    <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50" @click="goCampaignStep1">Back</button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="campaignForm.processing || selectedListKeys.size === 0 || selectedCount === 0"
                        @click="submitCampaign"
                    >
                        <Loader2 v-if="campaignForm.processing" class="h-4 w-4 animate-spin" />
                        <Send v-else class="h-4 w-4" />
                        {{ campaignForm.run ? 'Create & start chats' : 'Add to pipeline' }}
                    </button>
                </template>

                <button v-else type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50" @click="closeCampaignModal">Close</button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Track one prospect modal -->
    <Dialog v-model:open="showCreateModal">
        <DialogContent class="overflow-visible sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Track one prospect</DialogTitle>
                <DialogDescription>Add a single prospect to the call pipeline — optionally link an existing conversation.</DialogDescription>
            </DialogHeader>

            <form class="grid min-w-0 gap-3 overflow-hidden" @submit.prevent="submitCreate">
                <label class="grid min-w-0 gap-1 text-sm">
                    <span class="font-medium">LinkedIn conversation (optional)</span>
                    <SearchableSelect
                        v-model="createConversationId"
                        :options="conversationOptions"
                        placeholder="None — track by name only"
                        search-placeholder="Search conversations…"
                        empty-text="No conversations match"
                        allow-clear
                        clear-label="None — track by name only"
                    />
                </label>
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Prospect name</span>
                    <input v-model="createForm.prospect_name" type="text" class="rounded-lg border border-border bg-background px-3 py-2" placeholder="Jane Doe" />
                </label>
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Opening message (optional)</span>
                    <textarea v-model="createForm.pending_message" rows="3" class="rounded-lg border border-border bg-background px-3 py-2" placeholder="Leave blank to use your booking template from Settings" />
                </label>

                <div v-if="showCreateTemplatePreview" class="rounded-lg border border-dashed border-primary/30 bg-primary/5 px-3 py-2.5 text-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-primary">Will use (from Settings template)</p>
                    <p class="mt-1 whitespace-pre-wrap text-muted-foreground">{{ createOpeningPreview }}</p>
                </div>

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
        <DialogContent class="!flex h-[min(85vh,640px)] max-h-[90vh] flex-col overflow-hidden sm:max-w-lg">
            <DialogHeader class="shrink-0">
                <DialogTitle>Default booking settings</DialogTitle>
                <DialogDescription>{{ settingsStepDescription }}</DialogDescription>
            </DialogHeader>

            <div class="flex shrink-0 items-center gap-1.5 border-b border-border pb-3 text-xs">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium transition"
                    :class="settingsWizardStep === 1 ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:text-foreground'"
                    @click="settingsWizardStep = 1"
                >
                    <span class="flex h-4 w-4 items-center justify-center rounded-full text-[10px]" :class="settingsWizardStep === 1 ? 'bg-primary text-primary-foreground' : 'bg-muted'">1</span>
                    Booking links
                </button>
                <ChevronRight class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium transition"
                    :class="settingsWizardStep === 2 ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:text-foreground'"
                    @click="settingsWizardStep = 2"
                >
                    <span class="flex h-4 w-4 items-center justify-center rounded-full text-[10px]" :class="settingsWizardStep === 2 ? 'bg-primary text-primary-foreground' : 'bg-muted'">2</span>
                    Calendar
                </button>
                <ChevronRight class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium transition"
                    :class="settingsWizardStep === 3 ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:text-foreground'"
                    @click="settingsWizardStep = 3"
                >
                    <span class="flex h-4 w-4 items-center justify-center rounded-full text-[10px]" :class="settingsWizardStep === 3 ? 'bg-primary text-primary-foreground' : 'bg-muted'">3</span>
                    Messages
                </button>
            </div>

            <form class="flex min-h-0 flex-1 flex-col overflow-hidden" @submit.prevent="saveSettings">
                <div class="min-h-0 flex-1 overflow-y-auto py-1">
                    <!-- Step 1: Booking links -->
                    <div v-show="settingsWizardStep === 1" class="grid gap-3">
                        <div v-if="hasCalendarIntegration" class="rounded-lg border border-border bg-muted/20 p-3 space-y-3">
                            <ToggleField
                                v-model="settingsForm.use_app_booking_link"
                                description="Each prospect gets a unique page with your real availability."
                            >
                                Use in-app booking links
                            </ToggleField>
                            <div v-if="settingsForm.use_app_booking_link" class="rounded-md border border-dashed border-primary/30 bg-primary/5 px-3 py-2 text-xs text-muted-foreground">
                                Opening messages will include a personal <strong class="text-foreground">/book/…</strong> link per prospect when the template uses <code>{calendar_url}</code>.
                            </div>
                        </div>
                        <p v-else class="rounded-lg border border-dashed border-border px-3 py-2.5 text-xs text-muted-foreground">
                            Connect
                            <Link href="/integrations" class="text-primary underline">Google Calendar or Outlook</Link>
                            to enable in-app booking links with live availability.
                        </p>

                        <label v-if="showManualCalendarUrl" class="grid gap-1 text-sm">
                            <span class="font-medium">Calendar URL</span>
                            <input
                                v-model="settingsForm.calendar_url"
                                type="url"
                                placeholder="https://calendly.com/you/15min"
                                class="rounded-lg border border-border bg-background px-3 py-2"
                                :class="settingsForm.errors.calendar_url ? 'border-red-500' : ''"
                            />
                            <span v-if="settingsForm.errors.calendar_url" class="text-xs text-red-600">{{ settingsForm.errors.calendar_url }}</span>
                            <span v-else class="text-xs text-muted-foreground">
                                Used in messages as <code>{calendar_url}</code>. Bookings on this link are not tracked automatically — copy the booked time into Call Manager.
                            </span>
                        </label>
                    </div>

                    <!-- Step 2: Calendar sync & availability -->
                    <div v-show="settingsWizardStep === 2" class="grid gap-3">
                        <div v-if="hasCalendarIntegration" class="space-y-3">
                            <div class="flex items-start gap-2 rounded-lg border border-border bg-muted/20 p-3">
                                <Calendar class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                <p class="text-xs text-muted-foreground">
                                    Bookings create real calendar events via Unipile. Enable <strong>calendar event.created</strong> webhooks in Unipile so external bookings auto-update Call Manager.
                                </p>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-3">
                                <label class="grid gap-1 text-sm">
                                    <span class="font-medium">Hours start</span>
                                    <input v-model.number="settingsForm.booking_hours_start" type="number" min="0" max="23" class="rounded-lg border border-border bg-background px-3 py-2" />
                                </label>
                                <label class="grid gap-1 text-sm">
                                    <span class="font-medium">Hours end</span>
                                    <input v-model.number="settingsForm.booking_hours_end" type="number" min="1" max="24" class="rounded-lg border border-border bg-background px-3 py-2" />
                                </label>
                                <label class="grid gap-1 text-sm">
                                    <span class="font-medium">Days ahead</span>
                                    <input v-model.number="settingsForm.booking_days_ahead" type="number" min="1" max="30" class="rounded-lg border border-border bg-background px-3 py-2" />
                                </label>
                            </div>

                            <ToggleField
                                v-model="settingsForm.use_unipile_calendar"
                                description="Create Google Calendar or Outlook events when a call is booked."
                            >
                                Sync booked calls to calendar
                            </ToggleField>

                            <template v-if="settingsForm.use_unipile_calendar">
                                <label class="grid gap-1 text-sm">
                                    <span class="font-medium">Target calendar</span>
                                    <select v-model="settingsForm.calendar_id" class="rounded-lg border border-border bg-background px-3 py-2">
                                        <option value="">Primary calendar (auto)</option>
                                        <option v-for="opt in calendarSelectOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </select>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    <span class="font-medium">Default call length (minutes)</span>
                                    <input v-model.number="settingsForm.call_duration_minutes" type="number" min="15" max="240" step="15" class="rounded-lg border border-border bg-background px-3 py-2" />
                                </label>
                            </template>
                        </div>
                        <p v-else class="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                            Connect
                            <Link href="/integrations" class="text-primary underline">Google Calendar or Outlook</Link>
                            under Integrations to configure calendar sync and availability.
                        </p>
                    </div>

                    <!-- Step 3: Messages & AI -->
                    <div v-show="settingsWizardStep === 3" class="grid gap-3">
                        <label class="grid gap-1 text-sm">
                            <span class="font-medium">Booking message template</span>
                            <textarea v-model="settingsForm.booking_message" rows="4" class="rounded-lg border border-border bg-background px-3 py-2" />
                            <span class="text-xs text-muted-foreground">Used when opening message is left blank. Insert <code>{calendar_url}</code> where the link should appear.</span>
                        </label>
                        <div class="rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-xs text-muted-foreground">
                            <p class="font-medium text-foreground">Preview for new flows</p>
                            <p class="mt-1 whitespace-pre-wrap">{{ settingsTemplatePreview }}</p>
                        </div>
                        <ToggleField
                            v-model="settingsForm.auto_send_suggestions"
                            description="When on, AI draft replies send automatically on inbound messages — only for flows created with this setting enabled."
                        >
                            Auto-send AI replies for new flows
                        </ToggleField>
                        <p v-if="settingsForm.auto_send_suggestions" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                            Review manually if unsure — this applies only to new flows created after saving.
                        </p>
                    </div>
                </div>

                <DialogFooter class="shrink-0 gap-2 border-t border-border pt-3 sm:gap-0">
                    <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50" @click="showSettingsModal = false">Cancel</button>
                    <div class="flex flex-1 flex-wrap justify-end gap-2">
                        <button
                            v-if="settingsWizardStep > 1"
                            type="button"
                            class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50"
                            @click="prevSettingsStep"
                        >
                            Back
                        </button>
                        <button
                            type="submit"
                            class="rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-muted/50 disabled:opacity-50"
                            :disabled="settingsForm.processing"
                        >
                            <Loader2 v-if="settingsForm.processing" class="mr-1.5 inline h-4 w-4 animate-spin" />
                            Save
                        </button>
                        <button
                            v-if="settingsWizardStep < 3"
                            type="button"
                            class="rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground"
                            @click="nextSettingsStep"
                        >
                            Next
                        </button>
                    </div>
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

    <!-- Upcoming booked calls modal -->
    <Dialog v-model:open="showUpcomingModal">
        <DialogContent class="flex max-h-[85vh] flex-col sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Calendar class="h-4 w-4 text-primary" />
                    Upcoming booked calls
                    <span v-if="upcomingModalTotal" class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-normal text-primary">
                        {{ upcomingModalTotal }}
                    </span>
                </DialogTitle>
                <DialogDescription>
                    All scheduled calls — loaded in batches as you scroll.
                </DialogDescription>
            </DialogHeader>

            <div v-if="upcomingModalLoading && !upcomingModalItems.length" class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                <Loader2 class="h-4 w-4 animate-spin" />
                Loading…
            </div>
            <div v-else-if="!upcomingModalItems.length" class="py-8 text-center text-sm text-muted-foreground">
                No upcoming booked calls.
            </div>
            <ul v-else class="min-h-0 flex-1 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                <li v-for="c in upcomingModalItems" :key="c.id">
                    <Link
                        :href="`/calls/${c.id}`"
                        class="flex items-center justify-between px-4 py-3 text-sm hover:bg-muted/30"
                        @click="showUpcomingModal = false"
                    >
                        <span class="font-medium">{{ displayName(c) }}</span>
                        <span class="shrink-0 text-muted-foreground">{{ formatCallTime(c.scheduled_call_at) }}</span>
                    </Link>
                </li>
            </ul>

            <DialogFooter class="flex-col gap-2 sm:flex-row sm:justify-between">
                <button
                    v-if="upcomingModalHasMore"
                    type="button"
                    class="rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-muted/50 disabled:opacity-50"
                    :disabled="upcomingModalLoading"
                    @click="loadMoreUpcoming"
                >
                    <Loader2 v-if="upcomingModalLoading" class="mr-1 inline h-3.5 w-3.5 animate-spin" />
                    Load more
                </button>
                <span v-else-if="upcomingModalItems.length" class="text-xs text-muted-foreground">
                    Showing all {{ upcomingModalItems.length }} of {{ upcomingModalTotal }}
                </span>
                <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm hover:bg-muted/50 sm:ml-auto" @click="showUpcomingModal = false">
                    Close
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
