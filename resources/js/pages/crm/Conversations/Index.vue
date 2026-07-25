<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, MessageSquare, Send, Settings, Trash2, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import { Button } from '@/components/ui/button';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Conversations', href: '/conversations' },
        ],
    },
});

interface CallFlow {
    batch_id: string | null;
    batch_name: string;
    flow_key: string;
    count: number;
    chats_started?: number;
    ready_to_send: number;
    auto_send_enabled: boolean;
    last_message_at: string | null;
    stages: { engaged: number; scheduling: number; booked: number };
}

const props = defineProps<{
    flows: CallFlow[];
    stats: { flow_count: number; prospect_count: number; ready_to_send: number };
    hasUnipile: boolean;
    hasOrg: boolean;
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);
const deletingFlowKey = ref<string | null>(null);

function flowHref(flow: CallFlow) {
    return `/conversations/flows/${encodeURIComponent(flow.flow_key)}`;
}

function deleteFlow(flow: CallFlow) {
    const label = flow.batch_name;
    if (!confirm(`Delete "${label}" and all ${flow.count} prospect(s) inside it? This removes chats and messages from your CRM. This cannot be undone.`)) {
        return;
    }

    deletingFlowKey.value = flow.flow_key;
    router.delete(`/conversations/flows/${encodeURIComponent(flow.flow_key)}`, {
        preserveScroll: true,
        onFinish: () => { deletingFlowKey.value = null; },
    });
}

function formatWhen(at: string | null) {
    if (!at) return 'No activity yet';
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
    <Head title="Conversations" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <LinkedInPageHeading title="Conversations" show-badge>
                    <template #subtitle>
                        Pick a call flow to open its prospects — reply and approve AI drafts inside each group.
                    </template>
                </LinkedInPageHeading>
            </div>
            <Link href="/calls" class="inline-flex">
                <AppToolbarButton variant="slate">
                    <Settings class="h-4 w-4" /> Call Manager
                </AppToolbarButton>
            </Link>
        </div>

        <p v-if="flashSuccess" class="rounded-lg bg-green-100 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ flashSuccess }}</p>
        <p v-if="flashError" class="rounded-lg bg-red-100 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ flashError }}</p>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400">
            Connect your workspace to manage call conversations.
        </div>

        <div v-if="!hasUnipile" class="flex items-center gap-2 rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-4 py-3 text-sm text-yellow-800 dark:text-yellow-300">
            Connect LinkedIn via <Link href="/integrations" class="underline">Integrations</Link> to send replies.
        </div>

        <div v-if="hasOrg && stats.prospect_count > 0" class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground">
                    <Users class="h-4 w-4" />
                    <span class="text-xs font-medium uppercase">Call flows</span>
                </div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.flow_count }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground">
                    <MessageSquare class="h-4 w-4" />
                    <span class="text-xs font-medium uppercase">Prospects</span>
                </div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.prospect_count }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground">
                    <Send class="h-4 w-4" />
                    <span class="text-xs font-medium uppercase">Ready to send</span>
                </div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.ready_to_send }}</p>
            </div>
        </div>

        <div v-if="!flows.length" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <MessageSquare class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No call flows yet</p>
            <p class="max-w-md text-sm text-muted-foreground">
                Add prospects from <Link href="/calls" class="underline">Call Manager</Link> — each bulk launch becomes a flow here.
            </p>
            <Button size="toolbar" as-child>
                <Link href="/calls">Create a call flow</Link>
            </Button>
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="flow in flows"
                :key="flow.flow_key"
                class="group relative flex flex-col rounded-xl border border-border bg-card p-5 shadow-sm transition hover:border-primary/40 hover:shadow-md"
            >
                <Link :href="flowHref(flow)" class="flex flex-1 flex-col">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-semibold group-hover:text-primary">{{ flow.batch_name }}</h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ flow.count }} in pipeline
                                <span v-if="(flow.chats_started ?? 0) < flow.count"> · {{ flow.chats_started ?? 0 }} chat{{ (flow.chats_started ?? 0) === 1 ? '' : 's' }} started</span>
                            </p>
                        </div>
                        <ArrowRight class="h-4 w-4 shrink-0 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary" />
                    </div>

                    <div class="mt-4 flex flex-wrap gap-1.5">
                        <span v-if="flow.stages.engaged" class="rounded-full bg-blue-500/10 px-2 py-0.5 text-[10px] font-medium text-blue-700">
                            {{ flow.stages.engaged }} engaged
                        </span>
                        <span v-if="flow.stages.scheduling" class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-medium text-amber-800">
                            {{ flow.stages.scheduling }} scheduling
                        </span>
                        <span v-if="flow.stages.booked" class="rounded-full bg-green-500/10 px-2 py-0.5 text-[10px] font-medium text-green-700">
                            {{ flow.stages.booked }} booked
                        </span>
                        <span v-if="flow.ready_to_send" class="rounded-full bg-violet-500/10 px-2 py-0.5 text-[10px] font-medium text-violet-700">
                            {{ flow.ready_to_send }} ready
                        </span>
                        <span v-if="(flow.chats_started ?? 0) < flow.count" class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-medium text-amber-800">
                            {{ flow.count - (flow.chats_started ?? 0) }} awaiting chat
                        </span>
                        <span v-if="flow.auto_send_enabled" class="rounded-full bg-violet-500/10 px-2 py-0.5 text-[10px] font-medium text-violet-700">
                            Auto-send
                        </span>
                    </div>

                    <p class="mt-4 text-[11px] text-muted-foreground">
                        Last activity · {{ formatWhen(flow.last_message_at) }}
                    </p>
                </Link>

                <button
                    type="button"
                    class="absolute right-3 top-3 rounded-lg p-1.5 text-muted-foreground opacity-0 transition hover:bg-red-500/10 hover:text-red-600 group-hover:opacity-100"
                    :class="{ 'opacity-100': deletingFlowKey === flow.flow_key }"
                    :disabled="deletingFlowKey === flow.flow_key"
                    title="Delete flow"
                    @click="deleteFlow(flow)"
                >
                    <Trash2 class="h-4 w-4" />
                </button>
            </div>
        </div>
    </div>
</template>
