<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BarChart3,
    Bot,
    Building2,
    Calendar,
    ChartNoAxesCombined,
    FileText,
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
    Radar,
    Share2,
    Sparkles,
    TrendingUp,
    UserCog,
    Users,
    Users2,
    Vault,
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
    { title: 'Command Center', href: '/ai-employee', icon: Sparkles },
    { title: 'Tutorials', href: '/tutorials', icon: GraduationCap },
    { title: 'Calendar', href: '/calendar', icon: Calendar },
    { title: 'Analytics', href: '/analytics', icon: BarChart3 },
];

const outreachItems = computed<NavItem[]>(() => {
    const unread = (page.props.inboxUnreadCount as number) ?? 0;

    return [
        { title: 'Leads', href: '/leads', icon: Users2 },
        {
            title: 'Social Outreach',
            icon: Share2,
            badge: unread > 0 ? unread : undefined,
            children: [
                { title: 'LinkedIn Outreach', href: '/campaigns', icon: Megaphone },
                { title: 'Multi-Channel', href: '/outreach', icon: Layers },
                { title: 'Unified Inbox', href: '/inbox', icon: Inbox, badge: unread > 0 ? unread : undefined },
            ],
        },
        { title: 'Auto-Responses', href: '/auto-responses', icon: Bot },
        {
            title: 'Call Inbox',
            icon: Phone,
            children: [
                { title: 'Call Manager', href: '/calls', icon: Phone },
                { title: 'Conversations', href: '/conversations', icon: MessageSquare },
            ],
        },
    ];
});

const contentItems: NavItem[] = [
    { title: 'Outreach Templates', href: '/ai-messages', icon: PenLine },
    {
        title: 'Content Studio',
        icon: FileText,
        children: [
            { title: 'AI Content Creation', href: '/content', icon: FileText },
            { title: 'Inspiration', href: '/inspiration', icon: Lightbulb },
        ],
    },
];

const audienceItems: NavItem[] = [
    {
        title: 'Competitor Active Followers',
        href: '/competitor-followers',
        icon: TrendingUp,
    },
];

const bonusNavItems = computed<NavItem[]>(() => {
    if (!hasAny(['Bundle'])) return [];
    return [
        { title: 'Upsell Unlimited', href: '/bonus/upsell-unlimited', icon: Sparkles },
        { title: 'DFY Agency Setup', href: '/bonus/market-agency-setup', icon: Building2 },
        { title: 'DFY Campaign', href: '/bonus/dfy-campaign', icon: Megaphone },
        { title: 'Coaching Program', href: '/bonus/coach-program', icon: GraduationCap },
        { title: 'Unlimited Traffic', href: '/bonus/unlimited-traffic', icon: Radar },
        { title: 'Team', href: '/team', icon: UserCog },
    ];
});

const upgradeNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];
    if (isReseller.value) {
        items.push({ title: 'Reseller', href: '/reseller/users', icon: Users });
    }
    if (hasAny(['AffiliateCampaignVault', 'Bundle'])) {
        items.push({ title: 'Affiliate Campaign Vault', href: '/affiliate-campaign-vault', icon: Vault });
    }
    if (hasAny(['ProfitMultiplier', 'Bundle'])) {
        items.push({ title: 'Profit Multiplier', href: '/profit-multiplier', icon: ChartNoAxesCombined });
    }
    return items;
});

const adminNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Integrations', href: '/integrations', icon: Link2 },
    ];
    if (isPlatformAdmin.value) {
        items.push({ title: 'Users', href: '/admin/users', icon: Users });
        items.push({ title: 'Soci errors', href: '/admin/ai-errors', icon: AlertTriangle });
    }
    return items;
});

const navGroups = computed<NavGroup[]>(() => {
    const groups: NavGroup[] = [
        { label: 'Overview', items: overviewItems },
        { label: 'Outreach', items: outreachItems.value },
        { label: 'Content', items: contentItems },
        { label: 'Audience', items: audienceItems },
    ];

    if (upgradeNavItems.value.length > 0) {
        groups.push({ label: 'Upgrades', items: upgradeNavItems.value });
    }

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
