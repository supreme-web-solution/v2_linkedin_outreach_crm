<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn } from '@/lib/utils';
import type { NavGroup, NavItem } from '@/types';

const props = defineProps<{
    groups: NavGroup[];
}>();

const page = usePage();
const { isCurrentOrParentUrl } = useCurrentUrl();
const openItems = ref<Record<string, boolean>>({});

// Active state is resolved purely from booleans (page.url vs item.href) and
// applied via explicit :class bindings. The base sidebar-button component
// ships with its own `hover:`/`active:` (mouse-press) pseudo-class styles
// baked in; we forcefully neutralize those with `!` (important) so an item
// can NEVER render as "selected" just from a hover or a stray mouse-press,
// only when it is genuinely the active route.
function isChildActive(child: NavItem): boolean {
    return Boolean(child.href) && isCurrentOrParentUrl(child.href!);
}

function hasActiveChild(item: NavItem): boolean {
    return item.children?.some((child) => isChildActive(child)) ?? false;
}

function isItemActive(item: NavItem): boolean {
    if (item.href) {
        return isCurrentOrParentUrl(item.href);
    }

    return hasActiveChild(item);
}

function isOpen(item: NavItem): boolean {
    return openItems.value[item.title] ?? hasActiveChild(item);
}

function setOpen(item: NavItem, value: boolean): void {
    openItems.value = { ...openItems.value, [item.title]: value };
}

watch(
    () => page.url,
    () => {
        const next = { ...openItems.value };
        for (const group of props.groups) {
            for (const item of group.items) {
                if (item.children?.length && hasActiveChild(item)) {
                    next[item.title] = true;
                }
            }
        }
        openItems.value = next;
    },
    { immediate: true },
);

const inactiveRow =
    'text-sidebar-foreground/75 hover:bg-black/[0.04]! hover:text-sidebar-foreground active:bg-black/[0.06]! dark:hover:bg-white/[0.06]! dark:active:bg-white/[0.08]!';
const activeRow = 'bg-sidebar-accent! text-sidebar-accent-foreground font-bold shadow-none ring-1 ring-primary/10';

const inactiveSubRow =
    'text-sidebar-foreground/70 hover:bg-black/[0.04]! hover:text-sidebar-foreground active:bg-black/[0.06]! dark:hover:bg-white/[0.06]! dark:active:bg-white/[0.08]!';
const activeSubRow = 'bg-sidebar-accent! text-sidebar-accent-foreground font-semibold';

function rowClass(active: boolean): string {
    return cn('h-10 rounded-xl px-2 text-[13px] font-semibold transition-colors', active ? activeRow : inactiveRow);
}

function subRowClass(active: boolean): string {
    return cn('h-9 rounded-lg text-[13px] font-medium transition-colors', active ? activeSubRow : inactiveSubRow);
}

function iconWrapClass(active: boolean, size = 'size-8'): string {
    return cn(
        'flex shrink-0 items-center justify-center rounded-lg transition-colors',
        size,
        active ? 'bg-primary/15 text-primary' : 'text-sidebar-foreground/70',
    );
}
</script>

<template>
    <SidebarGroup
        v-for="group in groups"
        :key="group.label"
        class="px-3 py-0"
    >
        <SidebarGroupLabel
            class="mb-1 h-auto px-2 pt-4 pb-1.5 text-[11px] font-semibold tracking-wider text-muted-foreground/70 uppercase first:pt-2"
        >
            {{ group.label }}
        </SidebarGroupLabel>
        <SidebarMenu class="gap-1">
            <template v-for="item in group.items" :key="item.title">
                <Collapsible
                    v-if="item.children?.length"
                    :open="isOpen(item)"
                    class="group/collapsible"
                    @update:open="(value) => setOpen(item, value)"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :is-active="isItemActive(item)"
                                :tooltip="item.title"
                                :class="rowClass(isItemActive(item))"
                            >
                                <span :class="iconWrapClass(isItemActive(item))">
                                    <component :is="item.icon" class="size-[18px] stroke-[2]" />
                                </span>
                                <span>{{ item.title }}</span>
                                <ChevronRight
                                    class="ml-auto size-4 shrink-0 text-sidebar-foreground/50 transition-transform group-data-[state=open]/collapsible:rotate-90"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub class="mt-1 mb-0.5 gap-1 border-sidebar-border/70">
                                <SidebarMenuSubItem
                                    v-for="child in item.children"
                                    :key="child.title"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="isChildActive(child)"
                                        :class="subRowClass(isChildActive(child))"
                                    >
                                        <Link :href="child.href!" class="flex w-full items-center gap-2.5">
                                            <span :class="iconWrapClass(isChildActive(child), 'size-7')">
                                                <component
                                                    v-if="child.icon"
                                                    :is="child.icon"
                                                    class="size-4 stroke-[2]"
                                                />
                                            </span>
                                            <span class="truncate">{{ child.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>

                <SidebarMenuItem v-else-if="item.href">
                    <SidebarMenuButton
                        as-child
                        :is-active="isItemActive(item)"
                        :tooltip="item.title"
                        :class="rowClass(isItemActive(item))"
                    >
                        <Link :href="item.href" class="flex w-full items-center gap-2.5">
                            <span :class="iconWrapClass(isItemActive(item))">
                                <component :is="item.icon" class="size-[18px] stroke-[2]" />
                            </span>
                            <span class="truncate">{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
