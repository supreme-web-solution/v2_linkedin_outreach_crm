<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
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
} from '@lucide/vue';
import { ref } from 'vue';
import CheckboxField from '@/components/CheckboxField.vue';
import ListPagination from '@/components/crm/ListPagination.vue';

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

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
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
            body: JSON.stringify({ keyword: keyword.value, limit: 18, date_posted: datePosted.value }),
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
    router.delete(`/inspiration/${id}`, { preserveScroll: true });
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

function copyText(text: string) {
    navigator.clipboard?.writeText(text);
}

function fmt(n: number | undefined): string {
    const v = n ?? 0;
    if (v >= 1000) return (v / 1000).toFixed(1).replace('.0', '') + 'k';
    return String(v);
}
</script>

<template>
    <Head title="Inspiration" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Lightbulb class="h-6 w-6 text-amber-500" />
                <div>
                    <h1 class="text-xl font-semibold text-foreground">Inspiration</h1>
                    <p class="text-sm text-muted-foreground">Discover and remix high-performing LinkedIn posts.</p>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">Saved posts</p>
                <p class="mt-1 text-2xl font-semibold">{{ stats.total_posts.toLocaleString() }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">Favorites</p>
                <p class="mt-1 text-2xl font-semibold">{{ stats.favorites.toLocaleString() }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">Viral (1k+)</p>
                <p class="mt-1 text-2xl font-semibold">{{ stats.viral_posts.toLocaleString() }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">Avg engagement</p>
                <p class="mt-1 text-2xl font-semibold">{{ stats.avg_engagement.toLocaleString() }}</p>
            </div>
        </div>

        <!-- Fetch panel -->
        <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div class="mb-3 flex items-center gap-2">
                <Sparkles class="h-4 w-4 text-primary" />
                <h2 class="text-sm font-semibold">Discover viral posts</h2>
            </div>
            <div v-if="!rapidConfigured" class="mb-3 rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                RAPIDAPI_KEY is not configured. Add it to your <code>.env</code> to enable discovery.
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <label class="flex-1 text-sm" style="min-width: 220px">
                    <span class="mb-1 block font-medium text-foreground">Keyword / topic</span>
                    <input v-model="keyword" type="text" placeholder="e.g. cold outreach, AI sales" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary" @keyup.enter="runFetch" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium text-foreground">Posted</span>
                    <select v-model="datePosted" class="rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary">
                        <option>Past 24 hours</option>
                        <option>Past week</option>
                        <option>Past month</option>
                        <option>Past year</option>
                    </select>
                </label>
                <button type="button" :disabled="fetching || !rapidConfigured" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-60" @click="runFetch">
                    <Loader2 v-if="fetching" class="h-4 w-4 animate-spin" />
                    <Search v-else class="h-4 w-4" />
                    {{ fetching ? 'Fetching…' : 'Fetch posts' }}
                </button>
            </div>
            <p v-if="fetchMsg" class="mt-2 text-sm text-green-600">{{ fetchMsg }}</p>
            <p v-if="fetchError" class="mt-2 text-sm text-red-500">{{ fetchError }}</p>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex flex-1 items-center gap-2 rounded-lg border border-border bg-card px-3 py-2" style="min-width: 220px">
                <Search class="h-4 w-4 text-muted-foreground" />
                <input v-model="search" type="text" placeholder="Search library…" class="w-full bg-transparent text-sm outline-none" @keyup.enter="applyFilters" />
            </div>
            <select v-model="category" class="rounded-lg border border-border bg-card px-3 py-2 text-sm outline-none focus:border-primary" @change="applyFilters">
                <option value="">All categories</option>
                <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
            </select>
            <CheckboxField v-model="favoriteOnly" class="rounded-lg border border-border bg-card px-3 py-2" @update:model-value="applyFilters">
                Favorites only
            </CheckboxField>
        </div>

        <!-- Empty -->
        <div v-if="posts.data.length === 0" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <Lightbulb class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">Your library is empty</p>
            <p class="text-sm text-muted-foreground">Fetch viral posts by keyword above to build your inspiration library.</p>
        </div>

        <!-- Grid -->
        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="post in posts.data" :key="post.id" class="flex flex-col rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-foreground">{{ post.meta?.author_name || 'Unknown' }}</p>
                        <p class="truncate text-xs text-muted-foreground">{{ post.meta?.author_headline || '' }}</p>
                    </div>
                    <button type="button" class="shrink-0 rounded p-1.5 hover:bg-muted" :class="post.is_favorite ? 'text-red-500' : 'text-muted-foreground'" @click="toggleFavorite(post)">
                        <Heart class="h-4 w-4" :fill="post.is_favorite ? 'currentColor' : 'none'" />
                    </button>
                </div>

                <p class="mb-3 line-clamp-6 flex-1 whitespace-pre-line text-sm text-foreground/90">{{ post.content }}</p>

                <div class="mb-3 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                    <span class="inline-flex items-center gap-1"><ThumbsUp class="h-3.5 w-3.5" /> {{ fmt(post.meta?.likes) }}</span>
                    <span class="inline-flex items-center gap-1"><MessageCircle class="h-3.5 w-3.5" /> {{ fmt(post.meta?.comments) }}</span>
                    <span class="inline-flex items-center gap-1"><Share2 class="h-3.5 w-3.5" /> {{ fmt(post.meta?.shares) }}</span>
                    <span class="ml-auto inline-flex items-center gap-1 font-medium text-primary"><TrendingUp class="h-3.5 w-3.5" /> {{ fmt(post.engagement) }}</span>
                </div>

                <div v-if="post.category" class="mb-3">
                    <span class="rounded-full bg-muted px-2 py-0.5 text-xs capitalize text-muted-foreground">{{ post.category }}</span>
                </div>

                <div v-if="remixResult && remixResult.id === post.id" class="mb-3 rounded-lg border border-primary/30 bg-primary/5 p-3">
                    <p class="mb-2 text-xs font-medium text-primary">Remixed version</p>
                    <p class="whitespace-pre-line text-sm text-foreground/90">{{ remixResult.content }}</p>
                    <button type="button" class="mt-2 text-xs font-medium text-primary hover:underline" @click="copyText(remixResult.content)">Copy</button>
                </div>

                <div class="mt-auto flex items-center gap-2 border-t border-border pt-3">
                    <button type="button" :disabled="remixing === post.id" class="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-60" @click="remix(post)">
                        <Loader2 v-if="remixing === post.id" class="h-3.5 w-3.5 animate-spin" />
                        <Repeat v-else class="h-3.5 w-3.5" />
                        Remix
                    </button>
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-muted" @click="copyText(post.content)">Copy</button>
                    <a v-if="post.meta?.post_url" :href="post.meta.post_url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-muted">View</a>
                    <button type="button" class="ml-auto rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-red-500" @click="destroy(post.id)"><Trash2 class="h-4 w-4" /></button>
                </div>
            </div>
        </div>

        <ListPagination v-if="posts.data.length" :paginator="posts" label="posts" />
    </div>
</template>
