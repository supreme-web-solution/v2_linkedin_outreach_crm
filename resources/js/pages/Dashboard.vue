<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BarChart3,
    LayoutGrid,
    Loader2,
    Megaphone,
    MessageSquare,
    Phone,
    Rocket,
    Sparkles,
    Upload,
    Users2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AlexOnboardingModal from '@/components/crm/AlexOnboardingModal.vue';
import AlexAvatar from '@/components/crm/AlexAvatar.vue';
import { Button } from '@/components/ui/button';
import type { User } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
    },
});

const props = defineProps<{
    stats: {
        leads: number;
        linkedin_leads: number;
        imported_leads: number;
        campaigns: number;
        linkedin_campaigns?: number;
        outreach_campaigns?: number;
        conversations: number;
        calls: number;
        messages_sent: number;
        unread_conversations: number;
    };
    recentActivity: Array<{ module: string; identifier: string; stat: number; created_at: string }>;
    organization: { id: number; name: string } | null;
    hasOrg: boolean;
    onboarding: {
        show: boolean;
        completed: boolean;
        step?: string;
        goal?: string | null;
        goal_label?: string | null;
        starter_prompt?: string | null;
        employee_name: string;
        goal_options: Array<{ key: string; label: string }>;
        connections?: Array<{
            key: string;
            label: string;
            connected: boolean;
            required: boolean;
            kind: string;
        }>;
        whatsapp_command?: { linked: boolean; configured: boolean };
        ready?: boolean;
    };
}>();

const executeBusy = ref(false);

const page = usePage();
const user = computed(() => page.props.auth.user as User);
const firstName = computed(() => user.value?.name?.split(' ')[0] ?? 'there');

const todayLabel = new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
}).format(new Date());

const campaignCardHref = computed(() => {
    const linkedin = props.stats.linkedin_campaigns ?? 0;
    const outreach = props.stats.outreach_campaigns ?? 0;
    if (outreach > 0 && linkedin === 0) return '/outreach';
    if (linkedin > 0 && outreach === 0) return '/campaigns';
    // Both (or neither): prefer multi-channel hub; LinkedIn campaigns stay in the sidebar.
    return outreach >= linkedin ? '/outreach' : '/campaigns';
});

const statCards = [
    {
        href: '/leads',
        label: 'Total contacts',
        valueKey: 'leads' as const,
        sublabelKey: null as null,
        icon: Users2,
        gradient: 'from-blue-400 to-blue-600',
        ring: 'ring-blue-500/10',
    },
    {
        href: '/outreach',
        label: 'Campaigns',
        valueKey: 'campaigns' as const,
        sublabelKey: null,
        icon: Megaphone,
        gradient: 'from-sky-400 to-sky-600',
        ring: 'ring-sky-500/10',
    },
    {
        href: '/conversations',
        label: 'Inbox threads',
        valueKey: 'conversations' as const,
        sublabelKey: null,
        icon: MessageSquare,
        gradient: 'from-violet-400 to-violet-600',
        ring: 'ring-violet-500/10',
    },
    {
        href: '/calls',
        label: 'In pipeline',
        valueKey: 'calls' as const,
        sublabelKey: null,
        icon: Phone,
        gradient: 'from-emerald-400 to-emerald-600',
        ring: 'ring-emerald-500/10',
    },
];

const quickActions = [
    { href: '/leads', label: 'View Leads', icon: Users2 },
    { href: '/outreach/create', label: 'New outreach', icon: Upload },
    { href: '/campaigns', label: 'Campaigns', icon: Megaphone },
    { href: '/conversations', label: 'Conversations', icon: MessageSquare },
    { href: '/analytics', label: 'Analytics', icon: BarChart3 },
];

function leadsSublabel(): string {
    const parts: string[] = [];
    if (props.stats.linkedin_leads > 0) parts.push(`${props.stats.linkedin_leads.toLocaleString()} LinkedIn`);
    if (props.stats.imported_leads > 0) parts.push(`${props.stats.imported_leads.toLocaleString()} imported`);
    return parts.join(' · ') || 'Import or sync lists to get started';
}

function campaignsSublabel(): string {
    const linkedin = props.stats.linkedin_campaigns ?? 0;
    const outreach = props.stats.outreach_campaigns ?? 0;
    const parts: string[] = [];
    if (linkedin > 0) parts.push(`${linkedin.toLocaleString()} LinkedIn`);
    if (outreach > 0) parts.push(`${outreach.toLocaleString()} multi-channel`);
    return parts.join(' · ') || 'Create a LinkedIn or multi-channel campaign';
}

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
    );
}

async function letAiExecute(): Promise<void> {
    executeBusy.value = true;
    try {
        const res = await fetch('/ai-employee/execute-plan', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (data.redirect) {
            router.visit(data.redirect);
        }
    } finally {
        executeBusy.value = false;
    }
}
</script>

<template>
    <Head title="Dashboard" />

    <AlexOnboardingModal v-if="hasOrg && !onboarding.completed" :onboarding="onboarding" />

    <div class="flex flex-col gap-6 p-4 sm:p-5 md:p-6 lg:p-8">
        <div v-if="!hasOrg" class="rounded-2xl border border-yellow-500/40 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400">
            <strong>No organisation linked yet.</strong>
            Connect the Socifusion extension to this account to auto-create your workspace, or use the extension to sign in.
        </div>

        <!-- Welcome banner -->
        <div class="relative overflow-hidden rounded-3xl border border-blue-900/5 bg-gradient-to-br from-blue-600 via-blue-600 to-sky-500 p-5 shadow-lg shadow-blue-900/10 sm:p-6 md:p-8">
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-16 -right-10 h-64 w-64 rounded-full bg-white/10 blur-3xl" />
                <div class="absolute -bottom-20 left-1/3 h-56 w-56 rounded-full bg-sky-300/20 blur-3xl" />
                <div
                    class="absolute inset-0 bg-[linear-gradient(to_right,#ffffff0d_1px,transparent_1px),linear-gradient(to_bottom,#ffffff0d_1px,transparent_1px)] bg-[size:28px_28px]"
                />
            </div>

            <div class="relative z-10 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex items-start gap-4">
                    <div
                        class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-white/15 text-white shadow-inner ring-1 ring-inset ring-white/25 backdrop-blur-sm sm:size-14"
                    >
                        <LayoutGrid class="size-6 stroke-[1.75] sm:size-7" />
                    </div>
                    <div>
                        <p class="flex items-center gap-1.5 text-sm text-blue-100">
                            <Sparkles class="size-3.5" />
                            {{ todayLabel }}
                        </p>
                        <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl md:text-3xl">
                            Hey, {{ firstName }}
                        </h1>
                        <p v-if="organization" class="mt-1 text-sm text-blue-100">
                            Workspace:
                            <span class="font-medium text-white">{{ organization.name }}</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            <Link
                v-for="card in statCards"
                :key="card.label"
                :href="card.valueKey === 'campaigns' ? campaignCardHref : card.href"
                class="group relative overflow-hidden rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg sm:p-5"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            {{ card.label }}
                        </p>
                        <p class="mt-2 text-2xl font-bold text-foreground sm:text-3xl">
                            {{ stats[card.valueKey].toLocaleString() }}
                        </p>
                        <p v-if="card.valueKey === 'leads'" class="mt-1 truncate text-xs text-muted-foreground">
                            {{ leadsSublabel() }}
                        </p>
                        <p v-else-if="card.valueKey === 'campaigns'" class="mt-1 truncate text-xs text-muted-foreground">
                            {{ campaignsSublabel() }}
                        </p>
                    </div>
                    <div
                        :class="[
                            'flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br text-white shadow-sm ring-4 transition-transform group-hover:scale-105 sm:size-11',
                            card.gradient,
                            card.ring,
                        ]"
                    >
                        <component :is="card.icon" class="size-4 stroke-[1.75] sm:size-5" />
                    </div>
                </div>
                <div class="mt-3 flex items-center gap-1 text-sm font-medium text-primary opacity-0 transition group-hover:opacity-100 sm:mt-4">
                    View
                    <ArrowRight class="size-4" />
                </div>
            </Link>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-border/60 bg-card shadow-sm lg:col-span-2">
                <div class="flex items-center justify-between border-b border-border/60 px-4 py-4 sm:px-5">
                    <div>
                        <h2 class="text-base font-semibold text-foreground">Recent Activity</h2>
                        <p class="text-sm text-muted-foreground">Latest events across your workspace</p>
                    </div>
                </div>
                <div v-if="recentActivity.length === 0" class="flex flex-col items-center gap-2 p-8 text-center text-sm text-muted-foreground sm:p-10">
                    <MessageSquare class="h-8 w-8 text-muted-foreground/40" />
                    <p>No activity recorded yet.</p>
                    <p class="text-xs">Connect the extension or launch outreach to start capturing events.</p>
                </div>
                <ul v-else class="divide-y divide-border/60">
                    <li
                        v-for="item in recentActivity"
                        :key="item.created_at + item.identifier"
                        class="flex flex-col gap-1 px-4 py-3 text-sm sm:flex-row sm:items-center sm:gap-3 sm:px-5 sm:py-3.5"
                    >
                        <span
                            class="inline-flex h-7 w-fit min-w-[4.5rem] items-center justify-center rounded-full bg-gradient-to-b from-blue-500 to-blue-600 px-2.5 text-xs font-semibold text-white shadow-sm"
                        >
                            {{ item.module }}
                        </span>
                        <span class="flex-1 text-foreground">{{ item.identifier }}</span>
                        <span class="text-xs text-muted-foreground">{{ item.created_at }}</span>
                    </li>
                </ul>
            </div>

            <div class="rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:p-5">
                <h2 class="text-base font-semibold text-foreground">Quick Actions</h2>
                <p class="mt-1 text-sm text-muted-foreground">Jump into your most-used tools</p>

                <div class="mt-4 rounded-xl border border-blue-500/20 bg-gradient-to-br from-blue-50 to-white p-3 dark:from-blue-950/30 dark:to-card">
                    <div class="flex items-start gap-3">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
                            <Rocket class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold">Let AI Execute</p>
                            <p class="text-muted-foreground text-xs leading-relaxed">
                                One tap — Alex pauses struggling campaigns and sends follow-ups to hot inbox threads.
                            </p>
                            <Button
                                size="sm"
                                class="mt-2 h-8 text-xs"
                                :disabled="executeBusy"
                                @click="letAiExecute"
                            >
                                <Loader2 v-if="executeBusy" class="mr-1 size-3 animate-spin" />
                                <AlexAvatar v-else size="xs" class="mr-1 inline-flex" />
                                Stage plan
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid gap-2">
                    <Link
                        v-for="action in quickActions"
                        :key="action.href"
                        :href="action.href"
                        class="group flex items-center gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-3 text-sm font-medium transition hover:border-blue-500/30 hover:bg-blue-500/5 hover:text-blue-600"
                    >
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-blue-400 to-blue-600 text-white shadow-sm transition-transform group-hover:scale-105"
                        >
                            <component :is="action.icon" class="size-4 stroke-[1.75]" />
                        </div>
                        {{ action.label }}
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
