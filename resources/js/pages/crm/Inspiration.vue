<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Heart,
    Lightbulb,
    Loader2,
    MessageCircle,
    Repeat,
    Search,
    Share2,
    Sparkles,
    ThumbsUp,
    Trash2,
    TrendingUp,
    ExternalLink,
    Bookmark,
    Flame,
    BarChart3,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ToggleField from '@/components/ToggleField.vue';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import { Button } from '@/components/ui/button';
import { stashInspirationDraft } from '@/lib/contentDraft';
import ListPagination from '@/components/crm/ListPagination.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Inspiration', href: '/inspiration' },
        ],
    },
});

interface InspirationMeta {
    author_name?: string;
    author_headline?: string;
    author_profile_url?: string;
    post_url?: string;
    likes?: number;
    comments?: number;
    shares?: number;
    views?: number;
    posted?: string;
}

interface InspirationPost {
    id: number;
    content: string;
    category: string | null;
    engagement: number;
    is_favorite: boolean;
    meta: InspirationMeta | null;
}

const props = defineProps<{
    posts: {
        data: InspirationPost[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    stats: { total_posts: number; favorites: number; viral_posts: number; avg_engagement: number };
    categories: string[];
    filters: { search: string | null; category: string | null; favorite: boolean; engagement: string | null };
    rapidConfigured: boolean;
}>();

const search = ref(props.filters.search ?? '');
const category = ref(props.filters.category ?? '');
const favoriteOnly = ref(props.filters.favorite ?? false);

const keyword = ref('');
const datePosted = ref('Past month');
const fetching = ref(false);
const fetchMsg = ref('');
const fetchError = ref('');

const remixing = ref<number | null>(null);
const remixResult = ref<{ id: number; content: string; author: string } | null>(null);
const copiedId = ref<number | null>(null);

const selected = ref<Set<number>>(new Set());
const bulkBusy = ref(false);

const allSelected = computed(
    () => props.posts.data.length > 0 && props.posts.data.every((p) => selected.value.has(p.id)),
);
const selectedCount = computed(() => selected.value.size);

watch(
    () => props.posts.data.map((p) => p.id).join(','),
    () => {
        const visible = new Set(props.posts.data.map((p) => p.id));
        selected.value = new Set([...selected.value].filter((id) => visible.has(id)));
    },
);

const avatarPalettes = [
    { bg: '#dbeafe', text: '#1d4ed8' },
    { bg: '#e0f2fe', text: '#0369a1' },
    { bg: '#ccfbf1', text: '#0f766e' },
    { bg: '#fef3c7', text: '#b45309' },
    { bg: '#ffe4e6', text: '#be123c' },
    { bg: '#f1f5f9', text: '#334155' },
];

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

function toggleSelect(id: number) {
    if (selected.value.has(id)) selected.value.delete(id);
    else selected.value.add(id);
    selected.value = new Set(selected.value);
}

function toggleSelectAll() {
    if (allSelected.value) {
        selected.value = new Set();
        return;
    }
    selected.value = new Set(props.posts.data.map((p) => p.id));
}

function applyFilters() {
    router.get(
        '/inspiration',
        {
            search: search.value || undefined,
            category: category.value || undefined,
            favorite: favoriteOnly.value ? 1 : undefined,
        },
        { preserveState: true, replace: true },
    );
}

async function runFetch() {
    if (!keyword.value.trim()) return;
    fetching.value = true;
    fetchMsg.value = '';
    fetchError.value = '';
    try {
        const res = await fetch('/inspiration/fetch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify({ keyword: keyword.value, keep: 18, date_posted: datePosted.value }),
        });
        const data = await res.json();
        if (!res.ok) {
            fetchError.value = data.message || 'Fetch failed.';
            return;
        }
        fetchMsg.value = data.message;
        router.reload({ only: ['posts', 'stats', 'categories'] });
    } catch {
        fetchError.value = 'Network error. Please try again.';
    } finally {
        fetching.value = false;
    }
}

async function toggleFavorite(post: InspirationPost) {
    try {
        const res = await fetch(`/inspiration/${post.id}/favorite`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
        });
        const data = await res.json();
        if (res.ok) post.is_favorite = data.is_favorite;
    } catch {
        /* ignore */
    }
}

function destroy(id: number) {
    if (!confirm('Remove this post from your library?')) return;
    router.delete(`/inspiration/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value.delete(id);
            selected.value = new Set(selected.value);
        },
    });
}

function deleteSelected() {
    if (selected.value.size === 0) return;
    if (!confirm(`Delete ${selected.value.size} selected post(s)?`)) return;

    bulkBusy.value = true;
    router.post(
        '/inspiration/bulk-delete',
        { ids: Array.from(selected.value) },
        {
            preserveScroll: true,
            onFinish: () => {
                bulkBusy.value = false;
            },
            onSuccess: () => {
                selected.value = new Set();
            },
        },
    );
}

async function remix(post: InspirationPost) {
    remixing.value = post.id;
    remixResult.value = null;
    try {
        const res = await fetch(`/inspiration/${post.id}/remix`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify({ tone: 'Neutral and professional' }),
        });
        const data = await res.json();
        if (res.ok) {
            remixResult.value = { id: post.id, content: data.content, author: data.author };
        }
    } catch {
        /* ignore */
    } finally {
        remixing.value = null;
    }
}

function copyText(text: string, id?: number) {
    navigator.clipboard?.writeText(text);
    if (id != null) {
        copiedId.value = id;
        window.setTimeout(() => {
            if (copiedId.value === id) copiedId.value = null;
        }, 1600);
    }
}

function useInContent(content: string) {
    stashInspirationDraft(content);
    router.visit('/content?compose=1');
}

function fmt(n: number | undefined): string {
    const v = n ?? 0;
    if (v >= 1000) return (v / 1000).toFixed(1).replace('.0', '') + 'k';
    return String(v);
}

function initials(name: string | undefined): string {
    const parts = (name || 'Unknown').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[1][0]).toUpperCase();
}

function avatarStyle(name: string | undefined) {
    const s = name || 'Unknown';
    let hash = 0;
    for (let i = 0; i < s.length; i++) hash = (hash + s.charCodeAt(i) * (i + 1)) % 997;
    return avatarPalettes[hash % avatarPalettes.length];
}

function fmtPosted(value: string | undefined): string {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>

<template>
    <Head title="Inspiration" />

    <div class="relative flex min-h-0 flex-1 flex-col">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,_rgba(14,165,233,0.07),_transparent_55%),linear-gradient(180deg,_rgba(248,250,252,0.9)_0%,_transparent_28%)]" />

        <div class="relative flex flex-col gap-5 p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <LinkedInPageHeading title="Inspiration" show-badge>
                    <template #subtitle>
                        Discover high-performing LinkedIn posts, save favorites, and remix them into your own voice.
                    </template>
                </LinkedInPageHeading>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-2xl border border-border/80 bg-card/90 p-4 shadow-sm backdrop-blur-sm">
                    <div class="mb-2 flex h-8 w-8 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
                        <Bookmark class="h-4 w-4" />
                    </div>
                    <p class="text-xs font-medium text-muted-foreground">Saved posts</p>
                    <p class="mt-0.5 text-2xl font-semibold tracking-tight">{{ stats.total_posts.toLocaleString() }}</p>
                </div>
                <div class="rounded-2xl border border-rose-100/80 bg-gradient-to-br from-rose-50/80 to-card p-4 shadow-sm">
                    <div class="mb-2 flex h-8 w-8 items-center justify-center rounded-xl bg-gradient-to-br from-rose-500 to-red-500 text-white shadow-sm shadow-rose-500/25">
                        <Heart class="h-4 w-4" fill="currentColor" />
                    </div>
                    <p class="text-xs font-medium text-muted-foreground">Favorites</p>
                    <p class="mt-0.5 text-2xl font-semibold tracking-tight text-rose-700">{{ stats.favorites.toLocaleString() }}</p>
                </div>
                <div class="rounded-2xl border border-border/80 bg-card/90 p-4 shadow-sm backdrop-blur-sm">
                    <div class="mb-2 flex h-8 w-8 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
                        <Flame class="h-4 w-4" />
                    </div>
                    <p class="text-xs font-medium text-muted-foreground">Viral (1k+)</p>
                    <p class="mt-0.5 text-2xl font-semibold tracking-tight">{{ stats.viral_posts.toLocaleString() }}</p>
                </div>
                <div class="rounded-2xl border border-border/80 bg-card/90 p-4 shadow-sm backdrop-blur-sm">
                    <div class="mb-2 flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                        <BarChart3 class="h-4 w-4" />
                    </div>
                    <p class="text-xs font-medium text-muted-foreground">Avg engagement</p>
                    <p class="mt-0.5 text-2xl font-semibold tracking-tight">{{ stats.avg_engagement.toLocaleString() }}</p>
                </div>
            </div>

            <!-- Fetch panel -->
            <div class="overflow-hidden rounded-2xl border border-sky-200/60 bg-gradient-to-br from-sky-50/90 via-card to-card p-4 shadow-sm sm:p-5">
                <div class="mb-3 flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-blue-600 text-white shadow-sm shadow-sky-500/20">
                        <Sparkles class="h-4 w-4" />
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-foreground">Discover viral posts</h2>
                        <p class="text-xs text-muted-foreground">
                            We scan several result pages, then save the best 18 by engagement.
                        </p>
                    </div>
                </div>
                <div v-if="!rapidConfigured" class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/30 dark:text-amber-300">
                    Discovery API key is not configured. Add <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/50">RAPIDAPI_KEY</code> to your <code>.env</code> to enable fetching.
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <label class="min-w-[220px] flex-1 text-sm">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Keyword / topic</span>
                        <input
                            v-model="keyword"
                            type="text"
                            placeholder="e.g. cold outreach, AI sales"
                            class="w-full rounded-xl border border-border bg-white/90 px-3 py-2.5 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-500/15"
                            @keyup.enter="runFetch"
                        />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Posted</span>
                        <select
                            v-model="datePosted"
                            class="rounded-xl border border-border bg-white/90 px-3 py-2.5 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-500/15"
                        >
                            <option>Past 24 hours</option>
                            <option>Past week</option>
                            <option>Past month</option>
                            <option>Past year</option>
                        </select>
                    </label>
                    <button
                        type="button"
                        :disabled="fetching || !rapidConfigured"
                        class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-sky-500 to-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-sky-950/15 ring-1 ring-inset ring-white/15 transition hover:from-sky-500 hover:to-blue-700 disabled:opacity-60"
                        @click="runFetch"
                    >
                        <Loader2 v-if="fetching" class="h-4 w-4 animate-spin" />
                        <Search v-else class="h-4 w-4" />
                        {{ fetching ? 'Fetching…' : 'Fetch posts' }}
                    </button>
                </div>
                <p v-if="fetchMsg" class="mt-3 text-sm font-medium text-emerald-600">{{ fetchMsg }}</p>
                <p v-if="fetchError" class="mt-3 text-sm font-medium text-red-500">{{ fetchError }}</p>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex min-w-[220px] flex-1 items-center gap-2 rounded-xl border border-border bg-card px-3 py-2.5 shadow-sm">
                    <Search class="h-4 w-4 text-muted-foreground" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search library…"
                        class="w-full bg-transparent text-sm outline-none"
                        @keyup.enter="applyFilters"
                    />
                </div>
                <select
                    v-model="category"
                    class="rounded-xl border border-border bg-card px-3 py-2.5 text-sm shadow-sm outline-none focus:border-primary"
                    @change="applyFilters"
                >
                    <option value="">All categories</option>
                    <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                </select>
                <ToggleField
                    v-model="favoriteOnly"
                    class="rounded-xl border border-border bg-card px-3 py-2.5 shadow-sm"
                    @update:model-value="applyFilters"
                >
                    Favorites only
                </ToggleField>
                <button
                    v-if="posts.data.length"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl border border-border bg-card px-3 py-2.5 text-sm shadow-sm transition hover:bg-muted"
                    @click="toggleSelectAll"
                >
                    <AppSelectionCheckbox :checked="allSelected" />
                    {{ allSelected ? 'Clear page' : 'Select page' }}
                </button>
                <button
                    v-if="selectedCount > 0"
                    type="button"
                    :disabled="bulkBusy"
                    class="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100 disabled:opacity-60"
                    @click="deleteSelected"
                >
                    <Loader2 v-if="bulkBusy" class="h-4 w-4 animate-spin" />
                    <Trash2 v-else class="h-4 w-4" />
                    Delete {{ selectedCount }}
                </button>
            </div>

            <!-- Empty -->
            <div
                v-if="posts.data.length === 0"
                class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-14 text-center"
            >
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-sky-50 text-sky-500">
                    <Lightbulb class="h-7 w-7" />
                </div>
                <p class="text-base font-semibold">Your library is empty</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    Fetch viral posts by keyword above to build your inspiration library, then remix winners into drafts.
                </p>
            </div>

            <!-- Grid -->
            <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="post in posts.data"
                    :key="post.id"
                    class="group relative flex flex-col overflow-hidden rounded-2xl border border-border/80 bg-card shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-sky-200/80 hover:shadow-md"
                    :class="[
                        post.is_favorite ? 'ring-1 ring-rose-200/70' : '',
                        selected.has(post.id) ? 'ring-2 ring-sky-400/70' : '',
                    ]"
                >
                    <div class="flex items-start gap-3 border-b border-border/60 bg-gradient-to-r from-slate-50/80 to-transparent px-4 py-3.5">
                        <button
                            type="button"
                            class="mt-0.5 shrink-0 rounded-md p-0.5 transition hover:bg-white/80"
                            :title="selected.has(post.id) ? 'Deselect' : 'Select'"
                            @click="toggleSelect(post.id)"
                        >
                            <AppSelectionCheckbox :checked="selected.has(post.id)" />
                        </button>
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-xs font-bold tracking-wide"
                            :style="{ backgroundColor: avatarStyle(post.meta?.author_name).bg, color: avatarStyle(post.meta?.author_name).text }"
                        >
                            {{ initials(post.meta?.author_name) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-foreground">
                                {{ post.meta?.author_name || 'Unknown author' }}
                            </p>
                            <p v-if="post.meta?.author_headline" class="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                {{ post.meta.author_headline }}
                            </p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <span
                                    v-if="post.category"
                                    class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600"
                                >
                                    {{ post.category }}
                                </span>
                                <span
                                    v-if="post.meta?.posted"
                                    class="rounded-md bg-white/80 px-1.5 py-0.5 text-[10px] text-muted-foreground ring-1 ring-border/60"
                                >
                                    {{ fmtPosted(post.meta.posted) }}
                                </span>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="favorite-btn shrink-0"
                            :class="post.is_favorite ? 'is-favorite' : ''"
                            :title="post.is_favorite ? 'Remove from favorites' : 'Add to favorites'"
                            :aria-pressed="post.is_favorite"
                            @click="toggleFavorite(post)"
                        >
                            <Heart class="h-4 w-4" :fill="post.is_favorite ? 'currentColor' : 'none'" />
                        </button>
                    </div>

                    <div class="flex flex-1 flex-col px-4 py-3.5">
                        <p class="mb-4 line-clamp-7 flex-1 whitespace-pre-line text-[13px] leading-relaxed text-foreground/90">
                            {{ post.content }}
                        </p>

                        <div class="mb-3 grid grid-cols-4 gap-1.5">
                            <div class="rounded-lg bg-slate-50 px-2 py-1.5 text-center">
                                <ThumbsUp class="mx-auto h-3.5 w-3.5 text-slate-400" />
                                <p class="mt-0.5 text-[11px] font-semibold tabular-nums text-slate-700">{{ fmt(post.meta?.likes) }}</p>
                            </div>
                            <div class="rounded-lg bg-slate-50 px-2 py-1.5 text-center">
                                <MessageCircle class="mx-auto h-3.5 w-3.5 text-slate-400" />
                                <p class="mt-0.5 text-[11px] font-semibold tabular-nums text-slate-700">{{ fmt(post.meta?.comments) }}</p>
                            </div>
                            <div class="rounded-lg bg-slate-50 px-2 py-1.5 text-center">
                                <Share2 class="mx-auto h-3.5 w-3.5 text-slate-400" />
                                <p class="mt-0.5 text-[11px] font-semibold tabular-nums text-slate-700">{{ fmt(post.meta?.shares) }}</p>
                            </div>
                            <div class="rounded-lg bg-sky-50 px-2 py-1.5 text-center">
                                <TrendingUp class="mx-auto h-3.5 w-3.5 text-sky-500" />
                                <p class="mt-0.5 text-[11px] font-semibold tabular-nums text-sky-700">{{ fmt(post.engagement) }}</p>
                            </div>
                        </div>

                        <div
                            v-if="remixResult && remixResult.id === post.id"
                            class="mb-3 rounded-xl border border-sky-200/70 bg-gradient-to-br from-sky-50 to-white p-3"
                        >
                            <p class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-sky-700">
                                <Repeat class="h-3.5 w-3.5" /> Remixed version
                            </p>
                            <p class="whitespace-pre-line text-sm leading-relaxed text-foreground/90">{{ remixResult.content }}</p>
                            <div class="mt-2.5 flex flex-wrap gap-2">
                                <Button variant="success" size="sm" @click="useInContent(remixResult.content)">Use remix</Button>
                                <button
                                    type="button"
                                    class="text-xs font-medium text-sky-700 hover:underline"
                                    @click="copyText(remixResult.content)"
                                >
                                    Copy
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-border/70 bg-slate-50/60 px-4 py-3">
                        <Button variant="success" size="sm" @click="useInContent(post.content)">Use</Button>
                        <button
                            type="button"
                            :disabled="remixing === post.id"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-b from-sky-500 to-blue-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm shadow-sky-950/15 ring-1 ring-inset ring-white/15 transition hover:from-sky-500 hover:to-blue-700 disabled:opacity-60"
                            @click="remix(post)"
                        >
                            <Loader2 v-if="remixing === post.id" class="h-3.5 w-3.5 animate-spin" />
                            <Repeat v-else class="h-3.5 w-3.5" />
                            Remix
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-white px-3 py-1.5 text-xs font-medium transition hover:bg-muted"
                            @click="copyText(post.content, post.id)"
                        >
                            {{ copiedId === post.id ? 'Copied' : 'Copy' }}
                        </button>
                        <a
                            v-if="post.meta?.post_url"
                            :href="post.meta.post_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 rounded-lg border border-border bg-white px-3 py-1.5 text-xs font-medium transition hover:bg-muted"
                        >
                            <ExternalLink class="h-3 w-3" /> View
                        </a>
                        <button
                            type="button"
                            class="ml-auto rounded-lg p-1.5 text-muted-foreground transition hover:bg-rose-50 hover:text-rose-600"
                            title="Remove from library"
                            @click="destroy(post.id)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                </article>
            </div>

            <ListPagination v-if="posts.data.length" :paginator="posts" label="posts" />
        </div>
    </div>
</template>

<style scoped>
.favorite-btn {
    display: inline-flex;
    height: 2.25rem;
    width: 2.25rem;
    align-items: center;
    justify-content: center;
    border-radius: 0.75rem;
    border: 1px solid rgb(226 232 240);
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    color: rgb(148 163 184);
    transition:
        color 180ms ease,
        border-color 180ms ease,
        background 180ms ease,
        transform 180ms ease,
        box-shadow 180ms ease;
}

.favorite-btn:hover {
    color: rgb(244 63 94);
    border-color: rgb(254 205 211);
    background: linear-gradient(180deg, #fff1f2 0%, #ffe4e6 100%);
    transform: scale(1.04);
}

.favorite-btn.is-favorite {
    border-color: transparent;
    background: linear-gradient(145deg, #fb7185 0%, #e11d48 55%, #be123c 100%);
    color: #fff;
    box-shadow: 0 4px 12px -2px rgba(225, 29, 72, 0.35);
}

.favorite-btn.is-favorite:hover {
    background: linear-gradient(145deg, #fda4af 0%, #e11d48 50%, #9f1239 100%);
    color: #fff;
    transform: scale(1.05);
}
</style>
