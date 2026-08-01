<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, ChevronDown, Phone, Search, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import DailyEnrichmentQuotaBar from '@/components/crm/DailyEnrichmentQuotaBar.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useInitials } from '@/composables/useInitials';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useDailyEnrichmentQuota } from '@/composables/useDailyEnrichmentQuota';
import type { BreadcrumbItem, User } from '@/types';

type AppNotification = {
    id: number;
    module: string;
    identifier: string;
    stat: number;
    meta: Record<string, unknown>;
    created_at: string | null;
};

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const user = computed(() => page.props.auth.user as User);
const { quota: enrichmentQuota } = useDailyEnrichmentQuota();
const notifications = computed(() => (page.props.notifications as AppNotification[] | undefined) ?? []);
const hasNotifications = computed(() => notifications.value.length > 0);
const searchQuery = ref('');
const { getInitials } = useInitials();
const showAvatar = computed(() => Boolean(user.value?.avatar));

function submitSearch() {
    const q = searchQuery.value.trim();
    if (!q) return;
    router.get('/leads', { search: q }, { preserveState: true });
}

function notificationHref(notification: AppNotification): string | null {
    const callId = notification.meta?.call_id;
    if (notification.module === 'calls' && callId != null && callId !== '') {
        return `/calls/${callId}`;
    }

    return null;
}

function notificationSubtitle(notification: AppNotification): string {
    const scheduled = notification.meta?.scheduled_label;
    if (typeof scheduled === 'string' && scheduled.trim() !== '') {
        return scheduled;
    }

    return formatNotificationTime(notification.created_at);
}

function formatNotificationTime(at: string | null): string {
    if (!at) return '';
    try {
        return new Date(at).toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return at;
    }
}
</script>

<template>
    <header
        class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-border/60 bg-white/90 px-6 backdrop-blur-md transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <DailyEnrichmentQuotaBar v-if="enrichmentQuota" :quota="enrichmentQuota" compact />

            <!-- Global search -->
            <form class="relative hidden sm:block" @submit.prevent="submitSearch">
                <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    v-model="searchQuery"
                    type="search"
                    placeholder="Search leads, campaigns…"
                    class="h-9 w-44 rounded-lg border border-border/70 bg-muted/40 pr-3 pl-8 text-sm text-foreground outline-none transition-all placeholder:text-muted-foreground focus:w-64 focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-500/15 lg:w-56"
                />
            </form>

            <!-- Notifications -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="relative flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-black/[0.04] hover:text-foreground dark:hover:bg-white/[0.06]"
                        aria-label="Notifications"
                    >
                        <Bell class="size-[18px]" />
                        <span
                            v-if="hasNotifications"
                            class="absolute top-1.5 right-1.5 size-2 rounded-full bg-blue-500 ring-2 ring-white dark:ring-slate-900"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" :side-offset="8" class="w-80 rounded-xl p-0">
                    <div class="flex items-center justify-between border-b border-border/60 px-4 py-3">
                        <p class="text-sm font-semibold">Notifications</p>
                    </div>
                    <div v-if="!hasNotifications" class="flex flex-col items-center gap-2 px-6 py-10 text-center">
                        <div class="flex size-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-400 to-blue-600 text-white shadow-sm">
                            <Sparkles class="size-4" />
                        </div>
                        <p class="text-sm font-medium text-foreground">You're all caught up</p>
                        <p class="text-xs text-muted-foreground">New replies, connections and outreach events will show up here.</p>
                    </div>
                    <ul v-else class="max-h-80 divide-y divide-border/60 overflow-y-auto">
                        <li v-for="item in notifications" :key="item.id">
                            <component
                                :is="notificationHref(item) ? Link : 'div'"
                                :href="notificationHref(item) ?? undefined"
                                class="flex items-start gap-3 px-4 py-3 text-left transition hover:bg-muted/40"
                            >
                                <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-500/10 text-blue-600">
                                    <Phone v-if="item.module === 'calls'" class="size-3.5" />
                                    <Bell v-else class="size-3.5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-foreground">{{ item.identifier }}</p>
                                    <p class="text-xs text-muted-foreground">{{ notificationSubtitle(item) }}</p>
                                </div>
                            </component>
                        </li>
                    </ul>
                </DropdownMenuContent>
            </DropdownMenu>

            <!-- Account -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg py-1 pr-1.5 pl-1 transition-colors hover:bg-black/[0.04] dark:hover:bg-white/[0.06]"
                    >
                        <Avatar class="h-8 w-8 overflow-hidden rounded-full ring-2 ring-white dark:ring-slate-800">
                            <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
                            <AvatarFallback class="rounded-full bg-gradient-to-br from-blue-400 to-blue-600 text-xs font-semibold text-white">
                                {{ getInitials(user?.name ?? '') }}
                            </AvatarFallback>
                        </Avatar>
                        <ChevronDown class="hidden size-4 text-muted-foreground sm:block" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" :side-offset="8" class="w-56 rounded-lg">
                    <UserMenuContent v-if="user" :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
