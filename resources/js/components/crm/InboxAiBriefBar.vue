<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Bot, CalendarCheck, Flame, PauseCircle, Sparkles } from '@lucide/vue';
import AlexAvatar from '@/components/crm/AlexAvatar.vue';

export type InboxBrief = {
    headline: string;
    ai_handled_estimate: number;
    need_you: number;
    hot: number;
    needs_judgment: number;
    low_priority: number;
    meeting_ready: number;
    pricing_inquiry: number;
    pending_approvals: number;
};

export type NurtureBrief = {
    headline: string;
    total: number;
    due_this_week: number;
    overdue: number;
};

defineProps<{
    brief: InboxBrief | null;
    nurtureBrief?: NurtureBrief | null;
    employeeName?: string;
}>();
</script>

<template>
    <div
        v-if="brief"
        class="rounded-xl border border-blue-100 bg-gradient-to-r from-blue-50/90 to-white p-4 shadow-sm dark:border-blue-900/40 dark:from-blue-950/30 dark:to-zinc-900"
    >
        <div class="flex items-start gap-3">
            <AlexAvatar size="sm" :alt="employeeName ?? 'Soci'" class="mt-0.5 shrink-0" />
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 text-xs font-semibold tracking-wide text-blue-700 uppercase dark:text-blue-300">
                    <Bot class="size-3.5" />
                    AI Inbox
                </div>
                <p class="mt-1 text-sm font-medium leading-snug text-foreground">
                    {{ brief.headline }}
                </p>
                <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                        <Sparkles class="size-3" />
                        AI handled ~{{ brief.ai_handled_estimate }}
                    </span>
                    <span
                        v-if="brief.need_you > 0"
                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    >
                        {{ brief.need_you }} need you
                    </span>
                    <span
                        v-if="brief.hot > 0"
                        class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 font-medium text-red-800 dark:bg-red-950 dark:text-red-200"
                    >
                        <Flame class="size-3" />
                        {{ brief.hot }} hot
                    </span>
                    <span
                        v-if="brief.meeting_ready > 0"
                        class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2.5 py-1 font-medium text-violet-800 dark:bg-violet-950 dark:text-violet-200"
                    >
                        <CalendarCheck class="size-3" />
                        {{ brief.meeting_ready }} ready to book
                    </span>
                    <span
                        v-if="brief.pending_approvals > 0"
                        class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 font-medium text-blue-800 dark:bg-blue-950 dark:text-blue-200"
                    >
                        {{ brief.pending_approvals }} awaiting Launch
                    </span>
                    <Link
                        v-if="nurtureBrief && nurtureBrief.total > 0"
                        href="/inbox?tab=nurture"
                        class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2.5 py-1 font-medium text-violet-800 hover:bg-violet-200 dark:bg-violet-950 dark:text-violet-200 dark:hover:bg-violet-900"
                    >
                        <PauseCircle class="size-3" />
                        {{ nurtureBrief.total }} in nurture
                    </Link>
                </div>
                <Link
                    href="/ai-employee?starter=Who%20needs%20my%20attention%3F"
                    class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-blue-700 hover:underline dark:text-blue-300"
                >
                    <Bot class="size-3.5" /> Ask Soci to triage →
                </Link>
            </div>
        </div>
    </div>
</template>
