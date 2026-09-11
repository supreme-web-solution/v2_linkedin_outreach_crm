<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Bot, CalendarClock, Loader2, PauseCircle, Play } from '@lucide/vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { Button } from '@/components/ui/button';
import { computed, ref } from 'vue';

export type NurtureItem = {
    outreach_lead_id: number;
    conversation_id: number | null;
    prospect_name: string;
    channel: string | null;
    channel_label: string | null;
    campaign_id: number | null;
    campaign_name: string;
    reason: string;
    follow_up_at: string | null;
    follow_up_days: number;
    days_until_follow_up: number | null;
    status: 'scheduled' | 'due_soon' | 'overdue';
    inbox_url: string | null;
    campaign_url: string | null;
    alex_starter: string;
};

export type NurtureQueue = {
    items: NurtureItem[];
    counts: { total: number; due_this_week: number; overdue: number };
    summary: string;
    nurture_brief: { headline: string; total: number; due_this_week: number; overdue: number };
};

const props = defineProps<{
    queue: NurtureQueue | null;
}>();

const resumingId = ref<number | null>(null);

const items = computed(() => props.queue?.items ?? []);
const summary = computed(() => props.queue?.summary ?? 'Nobody is in nurture right now.');

function formatFollowUp(at: string | null): string {
    if (!at) return 'No date set';
    return new Date(at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function statusLabel(item: NurtureItem): string {
    if (item.status === 'overdue') return 'Overdue';
    if (item.status === 'due_soon') {
        const days = item.days_until_follow_up;
        if (days === 0) return 'Due today';
        if (days === 1) return 'Due tomorrow';
        return `Due in ${days} days`;
    }
    const days = item.days_until_follow_up;
    if (days === null) return 'Scheduled';
    return `Follow up in ${days} days`;
}

function statusClass(item: NurtureItem): string {
    if (item.status === 'overdue') {
        return 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200';
    }
    if (item.status === 'due_soon') {
        return 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200';
    }
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
}

function resumeLead(leadId: number) {
    resumingId.value = leadId;
    router.post(
        `/inbox/nurture/${leadId}/resume`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                resumingId.value = null;
            },
        },
    );
}

function askAlex(starter: string) {
    window.location.href = `/ai-employee?starter=${encodeURIComponent(starter)}`;
}
</script>

<template>
    <div class="space-y-4">
        <div class="rounded-xl border border-violet-100 bg-gradient-to-r from-violet-50/80 to-white p-4 dark:border-violet-900/40 dark:from-violet-950/20 dark:to-zinc-900">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-200">
                    <PauseCircle class="size-4.5" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-foreground">{{ queue?.nurture_brief?.headline ?? summary }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ summary }}</p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Prospects who said “maybe later” — outreach is paused until their follow-up date.
                    </p>
                </div>
            </div>
        </div>

        <div v-if="!items.length" class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center text-muted-foreground">
            <PauseCircle class="size-10 opacity-40" />
            <p class="text-sm">No one in nurture yet.</p>
            <p class="max-w-sm text-xs">
                When Soci moves a lead to nurture from the inbox, they’ll appear here with their follow-up date.
            </p>
            <Button size="sm" variant="outline" class="mt-2 rounded-full" as-child>
                <Link href="/ai-employee?starter=Who%20needs%20my%20attention%3F" class="inline-flex items-center gap-1.5">
                    <Bot class="size-3.5" /> Ask Soci to triage inbox
                </Link>
            </Button>
        </div>

        <div v-else class="space-y-3">
            <article
                v-for="item in items"
                :key="item.outreach_lead_id"
                class="rounded-xl border border-border bg-card p-4 shadow-sm"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <OutreachChannelIcon
                            v-if="item.channel"
                            :channel="item.channel"
                            :size="24"
                            class="mt-0.5 size-6 shrink-0"
                        />
                        <div class="min-w-0">
                            <h2 class="font-semibold text-foreground">{{ item.prospect_name }}</h2>
                            <p class="text-xs text-muted-foreground">
                                {{ item.channel_label ?? 'Outreach' }}
                                <span v-if="item.campaign_name"> · {{ item.campaign_name }}</span>
                            </p>
                        </div>
                    </div>
                    <span
                        class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium"
                        :class="statusClass(item)"
                    >
                        <CalendarClock class="size-3" />
                        {{ statusLabel(item) }}
                    </span>
                </div>

                <p class="mt-3 text-sm text-muted-foreground line-clamp-2">{{ item.reason }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Follow up {{ formatFollowUp(item.follow_up_at) }}
                    <span v-if="item.follow_up_days"> · {{ item.follow_up_days }}-day nurture</span>
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Button
                        v-if="item.inbox_url"
                        size="sm"
                        variant="secondary"
                        class="h-8 rounded-full text-xs"
                        as-child
                    >
                        <Link :href="item.inbox_url">Open thread</Link>
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 rounded-full text-xs"
                        :disabled="resumingId === item.outreach_lead_id"
                        @click="resumeLead(item.outreach_lead_id)"
                    >
                        <Loader2 v-if="resumingId === item.outreach_lead_id" class="mr-1 size-3 animate-spin" />
                        <Play v-else class="mr-1 size-3" />
                        Resume outreach
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        class="h-8 rounded-full text-xs text-violet-700 hover:text-violet-800 dark:text-violet-300"
                        @click="askAlex(item.alex_starter)"
                    >
                        <Bot class="mr-1 size-3" />
                        Ask Soci
                    </Button>
                </div>
            </article>
        </div>

        <p v-if="items.length" class="text-xs text-muted-foreground">
            Soci can also follow up automatically when nurture dates arrive — say
            <button type="button" class="font-medium text-primary hover:underline" @click="askAlex('Who is due for nurture follow-up?')">
                “Who is due for nurture follow-up?”
            </button>
            in Command Center.
        </p>
    </div>
</template>
