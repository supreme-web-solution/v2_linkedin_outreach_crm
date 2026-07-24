<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Bot,
    FileText,
    Gift,
    GraduationCap,
    LayoutGrid,
    Layers,
    Lightbulb,
    Link2,
    Megaphone,
    MessageSquare,
    Inbox,
    PenLine,
    Phone,
    Share2,
    TrendingUp,
    UserCog,
    Users,
    Users2,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavGroup, NavItem } from '@/types';

const page = usePage();
const entitlements = computed(() => (page.props.entitlements as string[]) ?? []);
const isPlatformAdmin = computed(() => Boolean(page.props.isPlatformAdmin));
const isReseller = computed(() => Boolean(page.props.isReseller));

function hasAny(keys: string[]): boolean {
    if (isPlatformAdmin.value) return true;
    return keys.some((k) => entitlements.value.includes(k));
}

const overviewItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Tutorials', href: '/tutorials', icon: GraduationCap },
    { title: 'Analytics', href: '/analytics', icon: BarChart3 },
];

const outreachItems: NavItem[] = [
    { title: 'Leads', href: '/leads', icon: Users2 },
    {
        title: 'Social Outreach',
        icon: Share2,
        children: [
            { title: 'LinkedIn Outreach', href: '/campaigns', icon: Megaphone },
            { title: 'Multi-Channel', href: '/outreach', icon: Layers },
            { title: 'Unified Inbox', href: '/inbox', icon: Inbox },
        ],
    },
    { title: 'Call Manager', href: '/calls', icon: Phone },
    { title: 'Conversations', href: '/conversations', icon: MessageSquare },
    { title: 'Auto-Responses', href: '/auto-responses', icon: Bot },
];

const contentItems: NavItem[] = [
    { title: 'AI Messages', href: '/ai-messages', icon: PenLine },
    { title: 'AI Content Creation', href: '/content', icon: FileText },
    { title: 'Inspiration', href: '/inspiration', icon: Lightbulb },
];

const audienceItems: NavItem[] = [
    {
        title: 'Competitor Active Followers',
        href: '/competitor-followers',
        icon: TrendingUp,
    },
];

const bonusNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];
    if (hasAny(['OTO2', 'OTO8', 'Bundle'])) {
        items.push({ title: 'Upsell Unlimited', href: '/bonus/upsell-unlimited', icon: Gift });
    }
    if (hasAny(['OTO3', 'OTO8', 'Bundle'])) {
        items.push({ title: 'DFY Agency Setup', href: '/bonus/market-agency-setup', icon: Gift });
    }
    if (hasAny(['OTO4', 'OTO8', 'Bundle'])) {
        items.push({ title: 'DFY Campaign', href: '/bonus/dfy-campaign', icon: Gift });
    }
    if (hasAny(['OTO7', 'OTO8', 'Bundle'])) {
        items.push({ title: 'Coaching Program', href: '/bonus/coach-program', icon: Gift });
    }
    if (hasAny(['OTO8', 'Bundle'])) {
        items.push({ title: 'Unlimited Traffic', href: '/bonus/unlimited-traffic', icon: Gift });
        items.push({ title: 'Team', href: '/team', icon: UserCog });
    }
    return items;
});

const adminNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Integrations', href: '/integrations', icon: Link2 },
    ];
    if (isReseller.value) {
        items.push({ title: 'Reseller', href: '/reseller/users', icon: Users });
    }
    if (isPlatformAdmin.value) {
        items.push({ title: 'Users', href: '/admin/users', icon: Users });
    }
    return items;
});

const navGroups = computed<NavGroup[]>(() => {
    const groups: NavGroup[] = [
        { label: 'Overview', items: overviewItems },
        { label: 'Outreach', items: outreachItems },
        { label: 'Content', items: contentItems },
        { label: 'Audience', items: audienceItems },
    ];

    if (bonusNavItems.value.length > 0) {
        groups.push({ label: 'Bonus', items: bonusNavItems.value });
    }

    if (adminNavItems.value.length > 0) {
        groups.push({ label: 'Admin', items: adminNavItems.value });
    }

    return groups;
});

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader class="border-b border-sidebar-border/50 px-4 py-4">
            <Link
                :href="dashboard()"
                class="flex items-center gap-3 rounded-lg outline-hidden ring-sidebar-ring transition-opacity hover:opacity-90 focus-visible:ring-2"
            >
                <AppLogo />
            </Link>
        </SidebarHeader>

        <SidebarContent class="gap-0 px-1 pb-2">
            <NavMain :groups="navGroups" />
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border/50 p-3">
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
