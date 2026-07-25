<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, Loader2, MessageSquare, Send, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import ListPagination from '@/components/crm/ListPagination.vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Conversations', href: '/conversations' },
            { title: 'Flow', href: '#' },
        ],
    },
});

const props = defineProps<{
    prospects: {
        data: Array<{
            call_id: number;
            conversation_id: number | null;
            chat_started: boolean;
            prospect_name: string | null;
            prospect_headline: string | null;
            pipeline_stage: string;
            ready_to_send: boolean;
            pending_message_preview: string | null;
            last_message_preview: string | null;
            messages_count: number;
            conversation_status: string | null;
            last_message_at: string | null;
            updated_at: string | null;
        }>;
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        from?: number | null;
        to?: number | null;
    };
    hasUnipile: boolean;
    flow: {
        flow_key: string;
        batch_id: string | null;
        batch_name: string;
        is_aggregate: boolean;
        count: number;
        chats_started: number;
        ready_to_send: number;
        auto_send_enabled: boolean;
        stages: { engaged: number; scheduling: number; booked: number };
    };
    flowSettings?: {
        calendar_url?: string;
        booking_message?: string;
        auto_send_suggestions?: boolean;
        reminder_hours_before?: number[];
    } | null;
    filters: {
        search: string | null;
        status: string | null;
        stage: string | null;
    };
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const search = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? 'all');
const stageFilter = ref(props.filters?.stage ?? 'all');
const launchingFlow = ref(false);
const deletingFlow = ref(false);
const deletingProspectId = ref<number | null>(null);

const flowBaseUrl = computed(() => `/conversations/flows/${encodeURIComponent(props.flow.flow_key)}`);
const chatsNotStarted = computed(() => Math.max(0, props.flow.count - props.flow.chats_started));

function applyFilters() {
    router.get(flowBaseUrl.value, {
        search: search.value || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
        stage: stageFilter.value !== 'all' ? stageFilter.value : undefined,
    }, { preserveState: true, replace: true });
}

function displayName(prospect: (typeof props.prospects.data)[0]) {
    return prospect.prospect_name || `Prospect #${prospect.call_id}`;
}

function launchAllInFlow() {
    launchingFlow.value = true;
    router.post(`/calls/flows/${encodeURIComponent(props.flow.flow_key)}/launch-chats`, {}, {
        preserveScroll: true,
        onFinish: () => { launchingFlow.value = false; },
    });
}

function deleteFlow() {
    if (props.flow.is_aggregate) return;

    if (!confirm(`Delete "${props.flow.batch_name}" and all ${props.flow.count} prospect(s)? This removes chats and messages from your CRM. This cannot be undone.`)) {
        return;
    }

    deletingFlow.value = true;
    router.delete(`/conversations/flows/${encodeURIComponent(props.flow.flow_key)}`, {
        onFinish: () => { deletingFlow.value = false; },
    });
}

function deleteProspect(prospect: (typeof props.prospects.data)[0]) {
    const name = displayName(prospect);
    if (!confirm(`Remove "${name}" and their chat from this flow? This cannot be undone.`)) {
        return;
    }

    deletingProspectId.value = prospect.call_id;
    router.delete(`/conversations/prospects/${prospect.call_id}`, {
        preserveScroll: true,
        onFinish: () => { deletingProspectId.value = null; },
    });
}

const stageLabels: Record<string, string> = {
    engaged: 'Engaged',
    scheduling: 'Scheduling',
    booked: 'Booked',
};

const stageBadgeClass: Record<string, string> = {
    engaged: 'bg-blue-500/10 text-blue-700',
    scheduling: 'bg-amber-500/10 text-amber-800',
    booked: 'bg-green-500/10 text-green-700',
};
</script>

<template>
    <Head :title="`${flow.batch_name} · Conversations`" />

    <div class="flex flex-col gap-4 p-4">
        <Link href="/conversations" class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft class="h-4 w-4" /> All call flows
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <LinkedInPageHeading :title="flow.batch_name" show-badge>
                    <template #subtitle>
                        {{ flow.count }} in pipeline
                        <span v-if="flow.chats_started < flow.count"> · {{ flow.chats_started }} chat{{ flow.chats_started === 1 ? '' : 's' }} started</span>
                        <span v-if="flow.ready_to_send"> · {{ flow.ready_to_send }} ready to send</span>
                    </template>
                </LinkedInPageHeading>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-if="chatsNotStarted > 0 && hasUnipile && !flow.is_aggregate"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    :disabled="launchingFlow"
                    @click="launchAllInFlow"
                >
                    <Loader2 v-if="launchingFlow" class="h-4 w-4 animate-spin" />
                    <Send v-else class="h-4 w-4" />
                    Start {{ chatsNotStarted }} chat{{ chatsNotStarted === 1 ? '' : 's' }}
                </button>
                <button
                    v-if="!flow.is_aggregate && flow.count > 0"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 disabled:opacity-50 dark:border-red-900/40 dark:hover:bg-red-950/30"
                    :disabled="deletingFlow"
                    @click="deleteFlow"
                >
                    <Loader2 v-if="deletingFlow" class="h-4 w-4 animate-spin" />
                    <Trash2 v-else class="h-4 w-4" />
                    Delete flow
                </button>
            </div>
        </div>

        <p v-if="flashSuccess" class="rounded-lg bg-green-100 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ flashSuccess }}</p>
        <p v-if="flashError" class="rounded-lg bg-red-100 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ flashError }}</p>

        <div v-if="chatsNotStarted > 0" class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
            <span v-if="!hasUnipile">
                {{ chatsNotStarted }} prospect{{ chatsNotStarted === 1 ? '' : 's' }} waiting — connect LinkedIn via
                <Link href="/integrations" class="underline">Integrations</Link> to start chats.
            </span>
            <span v-else>
                {{ chatsNotStarted }} prospect{{ chatsNotStarted === 1 ? '' : 's' }} without a chat yet — open each one to edit and send your opening message.
            </span>
        </div>

        <div v-if="!hasUnipile && flow.chats_started > 0" class="flex items-center gap-2 rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-4 py-3 text-sm text-yellow-800 dark:text-yellow-300">
            Connect LinkedIn via <Link href="/integrations" class="underline">Integrations</Link> to send replies.
        </div>

        <div v-if="flowSettings && !flow.is_aggregate" class="rounded-xl border border-dashed border-border bg-muted/20 px-4 py-3 text-sm">
            <p class="font-medium">Flow settings (snapshot from launch)</p>
            <p v-if="flowSettings.booking_message" class="mt-1 line-clamp-3 whitespace-pre-wrap text-muted-foreground">{{ flowSettings.booking_message }}</p>
            <Link href="/calls" class="mt-2 inline-block text-xs text-primary hover:underline">Edit defaults for new flows in Call Manager →</Link>
        </div>

        <div class="flex flex-wrap gap-2">
            <span class="rounded-full bg-blue-500/10 px-2.5 py-1 text-xs font-medium text-blue-700">{{ flow.stages.engaged }} engaged</span>
            <span class="rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-800">{{ flow.stages.scheduling }} scheduling</span>
            <span class="rounded-full bg-green-500/10 px-2.5 py-1 text-xs font-medium text-green-700">{{ flow.stages.booked }} booked</span>
        </div>

        <ListSearchBar v-model="search" placeholder="Search prospects in this flow…" @search="applyFilters">
            <template #filters>
                <select v-model="stageFilter" class="rounded-lg border border-border bg-card px-3 py-2 text-sm" @change="applyFilters">
                    <option value="all">All stages</option>
                    <option value="engaged">Engaged</option>
                    <option value="scheduling">Scheduling</option>
                    <option value="booked">Booked</option>
                </select>
                <select v-model="statusFilter" class="rounded-lg border border-border bg-card px-3 py-2 text-sm" @change="applyFilters">
                    <option value="all">All chat statuses</option>
                    <option value="active">Active</option>
                    <option value="read">Read</option>
                    <option value="archived">Archived</option>
                </select>
            </template>
        </ListSearchBar>

        <div v-if="prospects.data.length === 0" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <MessageSquare class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No prospects match your filters</p>
            <p class="max-w-md text-sm text-muted-foreground">
                <span v-if="stageFilter !== 'all' || search || statusFilter !== 'all'">Try clearing your filters.</span>
                <span v-else-if="flow.count > 0">Prospects exist in Call Manager but may belong to another team member.</span>
                <span v-else>Add prospects from <Link href="/calls" class="text-primary underline">Call Manager</Link>.</span>
            </p>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Prospect</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Chat</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Stage</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Message</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="prospect in prospects.data" :key="prospect.call_id" class="transition hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ displayName(prospect) }}</p>
                            <p v-if="prospect.prospect_headline" class="line-clamp-1 text-xs text-muted-foreground">{{ prospect.prospect_headline }}</p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <span
                                v-if="prospect.chat_started"
                                class="rounded-full bg-green-500/10 px-2 py-0.5 text-[10px] font-medium text-green-700"
                            >
                                Chat started
                            </span>
                            <span v-else class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-medium text-amber-800">
                                Not started
                            </span>
                            <p v-if="prospect.chat_started && prospect.messages_count" class="mt-1 text-muted-foreground">{{ prospect.messages_count }} messages</p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-medium capitalize"
                                :class="stageBadgeClass[prospect.pipeline_stage] ?? 'bg-muted text-muted-foreground'"
                            >
                                {{ stageLabels[prospect.pipeline_stage] ?? prospect.pipeline_stage }}
                            </span>
                            <span v-if="prospect.ready_to_send" class="ml-1 rounded-full bg-violet-500/10 px-1.5 py-0.5 text-[10px] text-violet-700">Ready</span>
                        </td>
                        <td class="max-w-xs px-4 py-3 text-xs text-muted-foreground">
                            <span v-if="prospect.last_message_preview" class="line-clamp-2">{{ prospect.last_message_preview }}</span>
                            <span v-else-if="prospect.pending_message_preview" class="line-clamp-2 italic">Draft: {{ prospect.pending_message_preview }}</span>
                            <span v-else>—</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <Link
                                    :href="`/calls/${prospect.call_id}`"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground transition hover:bg-muted/50 hover:text-primary"
                                    title="Open prospect"
                                >
                                    <ExternalLink class="h-4 w-4" />
                                </Link>
                                <button
                                    type="button"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground transition hover:bg-red-500/10 hover:text-red-600 disabled:opacity-50"
                                    :disabled="deletingProspectId === prospect.call_id"
                                    title="Remove prospect"
                                    @click="deleteProspect(prospect)"
                                >
                                    <Loader2 v-if="deletingProspectId === prospect.call_id" class="h-4 w-4 animate-spin" />
                                    <Trash2 v-else class="h-4 w-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <ListPagination :paginator="prospects" label="prospects" />
        </div>
    </div>
</template>
