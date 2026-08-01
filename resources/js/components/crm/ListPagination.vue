<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    paginator: {
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        total?: number;
        from?: number | null;
        to?: number | null;
        links?: PageLink[];
    };
    label?: string;
}>();

const numberedLinks = computed((): PageLink[] => {
    const links = props.paginator.links ?? [];

    if (links.length > 0) {
        return links.filter((link) => {
            const label = link.label.replace(/<[^>]*>/g, '').trim();
            return label === '...' || /^\d+$/.test(label);
        });
    }

    const pages: PageLink[] = [];
    for (let page = 1; page <= props.paginator.last_page; page++) {
        pages.push({
            url: pageUrl(page),
            label: String(page),
            active: page === props.paginator.current_page,
        });
    }

    return pages;
});

function pageUrl(page: number): string | null {
    const template = props.paginator.next_page_url ?? props.paginator.prev_page_url;
    if (!template) {
        return null;
    }

    try {
        const url = new URL(template, window.location.origin);
        url.searchParams.set('page', String(page));
        return `${url.pathname}${url.search}`;
    } catch {
        return null;
    }
}

function navClass(enabled: boolean): string {
    return [
        'inline-flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm font-medium transition-colors',
        enabled
            ? 'text-foreground hover:bg-muted'
            : 'pointer-events-none text-muted-foreground/40',
    ].join(' ');
}

function pageClass(active: boolean): string {
    if (active) {
        return 'inline-flex h-8 min-w-8 items-center justify-center rounded-md bg-gradient-to-b from-blue-500 to-blue-600 px-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15';
    }

    return 'inline-flex h-8 min-w-8 items-center justify-center rounded-md border border-border bg-background px-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted';
}
</script>

<template>
    <div
        v-if="paginator.last_page > 1 || (paginator.total ?? 0) > 0"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-muted/20 px-4 py-3 text-sm"
    >
        <span class="text-muted-foreground">
            <template v-if="paginator.from && paginator.to && paginator.total">
                Showing {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total.toLocaleString() }}
            </template>
            <template v-else>
                Page {{ paginator.current_page }} of {{ paginator.last_page }}
                <span v-if="paginator.total !== undefined"> · {{ paginator.total.toLocaleString() }} {{ label ?? 'items' }}</span>
            </template>
        </span>

        <nav
            v-if="paginator.last_page > 1"
            class="inline-flex items-center gap-1 rounded-lg border border-border bg-card p-1 shadow-sm"
            aria-label="Pagination"
        >
            <Link
                v-if="paginator.prev_page_url"
                :href="paginator.prev_page_url"
                :class="navClass(true)"
                preserve-scroll
                aria-label="Previous page"
            >
                <ChevronLeft class="h-4 w-4" />
            </Link>
            <span v-else :class="navClass(false)" aria-hidden="true">
                <ChevronLeft class="h-4 w-4" />
            </span>

            <template v-for="(link, index) in numberedLinks" :key="`${link.label}-${index}`">
                <span
                    v-if="link.label === '...'"
                    class="inline-flex h-8 min-w-8 items-center justify-center px-1 text-sm text-muted-foreground"
                >
                    …
                </span>
                <Link
                    v-else-if="link.url && !link.active"
                    :href="link.url"
                    :class="pageClass(false)"
                    preserve-scroll
                >
                    {{ link.label }}
                </Link>
                <span
                    v-else
                    :class="pageClass(link.active)"
                    :aria-current="link.active ? 'page' : undefined"
                >
                    {{ link.label }}
                </span>
            </template>

            <Link
                v-if="paginator.next_page_url"
                :href="paginator.next_page_url"
                :class="navClass(true)"
                preserve-scroll
                aria-label="Next page"
            >
                <ChevronRight class="h-4 w-4" />
            </Link>
            <span v-else :class="navClass(false)" aria-hidden="true">
                <ChevronRight class="h-4 w-4" />
            </span>
        </nav>
    </div>
</template>
