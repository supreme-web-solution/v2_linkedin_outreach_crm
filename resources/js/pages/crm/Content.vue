<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { AlertCircle, ChevronLeft, Clock, Copy, Edit2, FileText, Image as ImageIcon, Lightbulb, Loader2, PenLine, Rocket, Save, Send, Sparkles, Trash2, Type, Upload, Video, X } from '@lucide/vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import LinkedInPageHeading from '@/components/crm/LinkedInPageHeading.vue';
import ToggleField from '@/components/ToggleField.vue';
import { INSPIRATION_DRAFT_KEY } from '@/lib/contentDraft';
import ListPagination from '@/components/crm/ListPagination.vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Content', href: '/content' }] },
});

type Post = {
    id: number;
    content: string;
    status: string;
    provider: string | null;
    scheduled_at: string | null;
    published_at: string | null;
    created_at: string;
    meta?: Record<string, unknown>;
};

const props = defineProps<{
    posts: {
        data: Post[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        current_page: number;
        last_page: number;
        from?: number | null;
        to?: number | null;
    };
    filters: { search: string | null; status: string | null };
    contentStats: { total: number; published: number; scheduled: number; draft: number };
    hasOrg: boolean;
    hasLinkedIn: boolean;
    aiConfigured?: boolean;
}>();

const page = usePage();
const postSearch = ref(props.filters?.search ?? '');
const postStatusFilter = ref(props.filters?.status ?? 'all');
const selected = ref<Set<number>>(new Set());
const bulkBusy = ref(false);

const selectablePosts = computed(() => props.posts.data);
const allSelected = computed(
    () => selectablePosts.value.length > 0 && selectablePosts.value.every((p) => selected.value.has(p.id)),
);
const selectedCount = computed(() => selected.value.size);

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
    selected.value = new Set(selectablePosts.value.map((p) => p.id));
}

watch(
    () => props.posts.data.map((p) => p.id).join(','),
    () => {
        const visible = new Set(props.posts.data.map((p) => p.id));
        selected.value = new Set([...selected.value].filter((id) => visible.has(id)));
    },
);

function applyPostFilters() {
    router.get('/content', {
        search: postSearch.value || undefined,
        status: postStatusFilter.value !== 'all' ? postStatusFilter.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true, only: ['posts', 'filters', 'contentStats'] });
}

const panel = ref<'list' | 'compose'>('list');
const editingId = ref<number | null>(null);
const fromInspiration = ref(false);
const composeDraftHandled = ref(false);
const contentTextareaRef = ref<HTMLTextAreaElement | null>(null);
const imageInputRef = ref<HTMLInputElement | null>(null);
const videoInputRef = ref<HTMLInputElement | null>(null);

const hasActiveFilters = computed(
    () => Boolean(postSearch.value.trim()) || postStatusFilter.value !== 'all',
);

const form = useForm({
    content: '',
    hashtags: '',
    scheduled_at: '',
    action: 'draft' as 'draft' | 'publish' | 'schedule',
    post_type: 'text' as 'text' | 'image' | 'video',
    images: [] as File[],
    video: null as File | null,
    ai_image_url: '',
    ai_image_path: '',
});

const aiTopic = ref('');
const aiGenerateImage = ref(false);
const aiStyle = ref<'professional' | 'casual' | 'motivational' | 'educational' | 'storytelling'>('professional');
const aiLength = ref<'short' | 'medium' | 'long'>('medium');
const aiTone = ref<'professional' | 'casual' | 'motivational' | 'educational' | 'storytelling'>('professional');
const aiLoading = ref(false);
const aiError = ref('');

const charMax = 3000;
const charCount = computed(() => form.content.length);

const imagePreviews = computed(() => form.images.map((f) => URL.createObjectURL(f)));

const selectedVideoName = computed(() => form.video?.name ?? null);
const hasUploadedImages = computed(() => form.images.length > 0 || Boolean(form.ai_image_url));

const statusMap: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-600',
    scheduled: 'bg-blue-50 text-blue-600',
    ready_to_publish: 'bg-yellow-50 text-yellow-700',
    published: 'bg-green-50 text-green-600',
    failed: 'bg-red-50 text-red-600',
};

const csrf = computed(() => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '');

function fmtDate(v: string | null) {
    if (!v) return '-';
    return new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function preview(text: string, n = 120) {
    return text.length > n ? `${text.slice(0, n)}...` : text;
}

function openCreate() {
    panel.value = 'compose';
    editingId.value = null;
    fromInspiration.value = false;
    form.reset();
    form.post_type = 'text';
}

function closeCompose() {
    panel.value = 'list';
    editingId.value = null;
    fromInspiration.value = false;
    composeDraftHandled.value = false;
}

function openComposeWithDraft(content: string, inspired = false) {
    panel.value = 'compose';
    editingId.value = null;
    fromInspiration.value = inspired;
    form.reset();
    form.post_type = 'text';
    form.action = 'draft';
    applyContentAndHashtags(content);
}

function urlHasComposeFlag(): boolean {
    if (typeof window === 'undefined') {
        return String(page.url).includes('compose=1');
    }

    const fromPageUrl = new URLSearchParams(String(page.url).split('?')[1] ?? '').get('compose') === '1';
    const fromLocation = new URLSearchParams(window.location.search).get('compose') === '1';

    return fromPageUrl || fromLocation;
}

function cleanComposeQueryFromUrl() {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);
    if (!url.searchParams.has('compose')) {
        return;
    }

    url.searchParams.delete('compose');
    const next = `${url.pathname}${url.search}${url.hash}`;
    window.history.replaceState(window.history.state, '', next);
}

async function applyInspirationDraftFromUrl() {
    if (!urlHasComposeFlag()) {
        return;
    }

    if (composeDraftHandled.value && panel.value === 'compose') {
        cleanComposeQueryFromUrl();
        return;
    }

    const draft = sessionStorage.getItem(INSPIRATION_DRAFT_KEY);
    if (draft) {
        openComposeWithDraft(draft, true);
        sessionStorage.removeItem(INSPIRATION_DRAFT_KEY);
    } else if (panel.value === 'list') {
        openCreate();
    }

    composeDraftHandled.value = true;
    cleanComposeQueryFromUrl();

    await nextTick();
    contentTextareaRef.value?.focus();
    contentTextareaRef.value?.setSelectionRange(0, 0);
}

onMounted(() => {
    void applyInspirationDraftFromUrl();
});

watch(() => page.url, () => {
    void applyInspirationDraftFromUrl();
});

function openEdit(post: Post) {
    panel.value = 'compose';
    editingId.value = post.id;
    fromInspiration.value = false;
    form.hashtags = '';
    form.scheduled_at = post.scheduled_at ? post.scheduled_at.slice(0, 16) : '';
    form.post_type = (post.meta?.post_type as 'text' | 'image' | 'video') ?? 'text';
    form.images = [];
    form.video = null;
    form.ai_image_url = (post.meta?.ai_image_url as string) ?? '';
    form.ai_image_path = (post.meta?.ai_image_path as string) ?? '';
    form.action = 'draft';
    applyContentAndHashtags(post.content ?? '');
}

function onImageChange(e: Event) {
    const files = (e.target as HTMLInputElement).files;
    form.images = files ? Array.from(files) : [];
    if (form.images.length) {
        form.post_type = 'image';
    }
}

function onVideoChange(e: Event) {
    const files = (e.target as HTMLInputElement).files;
    form.video = files?.[0] ?? null;
    if (form.video) {
        form.post_type = 'video';
    }
}

function openImagePicker() {
    imageInputRef.value?.click();
}

function openVideoPicker() {
    videoInputRef.value?.click();
}

function clearImages() {
    form.images = [];
    if (imageInputRef.value) {
        imageInputRef.value.value = '';
    }
}

function clearVideo() {
    form.video = null;
    if (videoInputRef.value) {
        videoInputRef.value.value = '';
    }
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Split trailing hashtag lines from post body for the separate hashtags field. */
function extractHashtagsFromText(text: string): { body: string; hashtags: string } {
    const trimmed = text.trim();
    if (!trimmed) {
        return { body: '', hashtags: '' };
    }

    const lines = trimmed.split(/\r\n|\r|\n/);
    const hashLines: string[] = [];
    const bodyLines: string[] = [];

    for (const line of lines) {
        const lineTrimmed = line.trim();
        const isHashtagOnlyLine = lineTrimmed !== ''
            && /^#\w+/u.test(lineTrimmed)
            && !lineTrimmed.replace(/#\w+/gu, '').trim();

        if (isHashtagOnlyLine) {
            hashLines.push(lineTrimmed);
        } else {
            bodyLines.push(line);
        }
    }

    let body = bodyLines.join('\n').trim();
    let hashtags = hashLines.join(' ').trim();

    if (!hashtags) {
        const trailing = body.match(/(?:\s|^)((?:#\w+\s*){2,})\s*$/u);
        if (trailing) {
            hashtags = trailing[1].trim();
            body = body.slice(0, body.length - trailing[0].length).trim();
        }
    }

    return { body, hashtags };
}

function stripMarkdownFormatting(text: string): string {
    return text
        .replace(/\*\*(.+?)\*\*/gs, '$1')
        .replace(/__(.+?)__/gs, '$1')
        .replace(/\*\*/g, '')
        .replace(/^#{1,6}\s+/gm, '')
        .trim();
}

function applyContentAndHashtags(content: string, hashtags = '') {
    const cleaned = stripMarkdownFormatting(content);
    if (hashtags.trim()) {
        form.content = cleaned;
        form.hashtags = stripMarkdownFormatting(hashtags);
        return;
    }

    const split = extractHashtagsFromText(cleaned);
    form.content = split.body;
    form.hashtags = split.hashtags;
}

function setAction(action: 'draft' | 'publish' | 'schedule') {
    form.action = action;
}

const submitLabel = computed(() => {
    if (form.processing) {
        return 'Saving…';
    }
    if (editingId.value) {
        return form.action === 'publish' ? 'Update & publish' : form.action === 'schedule' ? 'Update schedule' : 'Update draft';
    }
    if (form.action === 'publish') {
        return 'Publish to LinkedIn';
    }
    if (form.action === 'schedule') {
        return 'Schedule post';
    }
    return 'Save draft';
});

function actionCardClass(action: 'draft' | 'publish' | 'schedule'): string {
    const active = form.action === action;
    const base = 'flex flex-col items-start gap-1 rounded-xl border p-3 text-left transition-all';
    if (!active) {
        return `${base} border-border bg-background hover:border-primary/40 hover:bg-muted/40`;
    }
    if (action === 'publish') {
        return `${base} border-blue-500 bg-gradient-to-b from-blue-50 to-blue-100/80 ring-2 ring-blue-500/30 dark:from-blue-950/40 dark:to-blue-900/20`;
    }
    if (action === 'schedule') {
        return `${base} border-violet-500 bg-gradient-to-b from-violet-50 to-violet-100/80 ring-2 ring-violet-500/30 dark:from-violet-950/40 dark:to-violet-900/20`;
    }
    return `${base} border-slate-400 bg-gradient-to-b from-slate-50 to-slate-100/80 ring-2 ring-slate-400/30 dark:from-slate-900/40 dark:to-slate-800/20`;
}

const aiChipClass = 'rounded-lg bg-gradient-to-b from-slate-600 to-slate-700 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm shadow-slate-950/15 ring-1 ring-inset ring-white/10 hover:from-slate-500 hover:to-slate-700 disabled:opacity-60';
const postTypeClass = (type: string) =>
    form.post_type === type
        ? 'border-blue-500 bg-gradient-to-b from-blue-500 to-blue-600 text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15'
        : 'border-border bg-card text-foreground hover:bg-muted/60';

function submitCompose() {
    const opts = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeCompose();
        },
    };

    if (editingId.value) {
        form.put(`/content/${editingId.value}`, opts);
    } else {
        form.post('/content', opts);
    }
}

function publishNow(id: number) {
    router.post(`/content/${id}/publish`);
}

function deletePost(id: number) {
    if (!confirm('Delete this post?')) return;
    router.delete(`/content/${id}`, {
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
    router.post('/content/bulk-delete', { ids: Array.from(selected.value) }, {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
        },
        onSuccess: () => {
            selected.value = new Set();
        },
    });
}

function duplicatePost(id: number) {
    router.post(`/content/${id}/duplicate`, {}, { preserveScroll: true });
}

async function apiPost(url: string, body: Record<string, unknown>) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf.value,
        },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message ?? 'Request failed');
    return data;
}

async function generateAi() {
    if (!aiTopic.value.trim()) return;
    aiLoading.value = true;
    aiError.value = '';
    try {
        const data = await apiPost('/content/ai/generate', {
            topic: aiTopic.value,
            style: aiStyle.value,
            length: aiLength.value,
            generate_image: aiGenerateImage.value,
        });
        applyContentAndHashtags(data.content ?? '', data.hashtags ?? '');

        if (data.url && data.path) {
            form.ai_image_url = data.url;
            form.ai_image_path = data.path;
            form.post_type = 'image';
        } else if (aiGenerateImage.value) {
            form.ai_image_url = '';
            form.ai_image_path = '';
        }

        if (data.image_error) {
            aiError.value = data.image_error;
        }
    } catch (e) {
        aiError.value = e instanceof Error ? e.message : 'AI generation failed.';
    } finally {
        aiLoading.value = false;
    }
}

async function improve(action: string) {
    if (!form.content.trim()) return;
    aiLoading.value = true;
    aiError.value = '';
    try {
        const data = await apiPost('/content/ai/improve', { content: form.content, action });
        applyContentAndHashtags(data.content ?? form.content, data.hashtags ?? '');
    } catch (e) {
        aiError.value = e instanceof Error ? e.message : 'Improve failed.';
    } finally {
        aiLoading.value = false;
    }
}

async function rewrite(mode: 'shorten' | 'expand') {
    if (!form.content.trim()) return;
    aiLoading.value = true;
    aiError.value = '';
    try {
        const data = await apiPost('/content/ai/rewrite', { content: form.content, tone: aiTone.value, mode });
        applyContentAndHashtags(data.content ?? form.content, data.hashtags ?? '');
    } catch (e) {
        aiError.value = e instanceof Error ? e.message : 'Rewrite failed.';
    } finally {
        aiLoading.value = false;
    }
}

</script>

<template>
    <Head title="Content Creator" />

    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center justify-between border-b border-border px-6 py-4">
            <LinkedInPageHeading title="Content Creator" show-badge>
                <template #subtitle>
                    Create, schedule, and publish LinkedIn posts with AI.
                </template>
            </LinkedInPageHeading>
            <AppToolbarButton v-if="panel === 'list'" @click="openCreate">
                <PenLine class="h-4 w-4" /> Create New Post
            </AppToolbarButton>
            <AppToolbarButton v-else variant="slate" @click="closeCompose">
                <ChevronLeft class="h-4 w-4" /> Back to Posts
            </AppToolbarButton>
        </div>

        <div v-if="!hasOrg" class="m-6 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-700">
            Connect your workspace first.
        </div>

        <template v-else-if="panel === 'list'">
            <div class="px-6 py-4">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="(val, key) in { Total: contentStats.total, Published: contentStats.published, Scheduled: contentStats.scheduled, Draft: contentStats.draft }" :key="key" class="rounded-xl border border-border bg-card p-3 text-center">
                        <div class="text-2xl font-bold">{{ val }}</div>
                        <div class="text-xs text-muted-foreground">{{ key }}</div>
                    </div>
                </div>

                <div v-if="!hasLinkedIn" class="mt-3 rounded-lg border border-orange-300/40 bg-orange-50 px-4 py-2.5 text-sm text-orange-700">
                    <AlertCircle class="mr-1 inline h-4 w-4" /> LinkedIn not connected. Publish will fail until connected.
                </div>

                <div class="mt-4">
                    <ListSearchBar v-model="postSearch" placeholder="Search posts…" @search="applyPostFilters">
                        <template #filters>
                            <select
                                v-model="postStatusFilter"
                                class="rounded-lg border border-border bg-card px-3 py-2 text-sm"
                                @change="applyPostFilters"
                            >
                                <option value="all">All statuses</option>
                                <option value="draft">Draft</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="ready_to_publish">Ready</option>
                                <option value="published">Published</option>
                                <option value="failed">Failed</option>
                            </select>
                        </template>
                    </ListSearchBar>
                </div>

                <div v-if="posts.data.length === 0" class="mt-4 flex flex-col items-center gap-4 rounded-xl border border-dashed border-border p-14 text-center">
                    <FileText class="h-10 w-10 text-muted-foreground/40" />
                    <div>
                        <p class="font-semibold">{{ hasActiveFilters ? 'No posts match this view' : 'No posts yet' }}</p>
                        <p class="mt-1 max-w-md text-sm text-muted-foreground">
                            <template v-if="hasActiveFilters">
                                Try a different search term or status filter.
                            </template>
                            <template v-else>
                                Write your first LinkedIn post with AI — save as draft, schedule it, or publish when ready.
                            </template>
                        </p>
                    </div>
                    <AppToolbarButton v-if="!hasActiveFilters" @click="openCreate">
                        <PenLine class="h-4 w-4" /> Create your first post
                    </AppToolbarButton>
                    <p v-if="!hasActiveFilters" class="text-xs text-muted-foreground">
                        Need ideas?
                        <Link href="/inspiration" class="font-medium text-primary hover:underline">Browse Inspiration</Link>
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        Need ideas?
                        <Link href="/inspiration" class="font-medium text-primary hover:underline">Browse Inspiration</Link>
                    </p>
                </div>

                <div v-if="selectedCount > 0" class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm">
                    <span class="font-medium">{{ selectedCount }} selected</span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-white px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"
                        :disabled="bulkBusy"
                        @click="deleteSelected"
                    >
                        <Trash2 class="h-3.5 w-3.5" /> Delete selected
                    </button>
                    <button type="button" class="text-xs text-muted-foreground hover:text-foreground" @click="selected = new Set()">
                        Clear
                    </button>
                </div>

                <div v-if="posts.data.length" class="mt-4 overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <table class="w-full text-sm">
                        <thead class="border-b border-border bg-muted/40">
                            <tr>
                                <th class="w-10 px-3 py-3">
                                    <button
                                        type="button"
                                        class="rounded p-0.5 hover:bg-muted disabled:opacity-40"
                                        :disabled="selectablePosts.length === 0"
                                        title="Select all on this page"
                                        @click="toggleSelectAll"
                                    >
                                        <AppSelectionCheckbox :checked="allSelected" />
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Content</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Date</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="post in posts.data"
                                :key="post.id"
                                class="transition hover:bg-muted/30"
                                :class="selected.has(post.id) ? 'bg-primary/[0.03]' : ''"
                            >
                                <td class="px-3 py-3">
                                    <button
                                        type="button"
                                        class="rounded p-0.5 hover:bg-muted"
                                        title="Select post"
                                        @click="toggleSelect(post.id)"
                                    >
                                        <AppSelectionCheckbox :checked="selected.has(post.id)" />
                                    </button>
                                </td>
                                <td class="max-w-lg px-4 py-3">
                                    <div class="font-medium text-foreground">{{ preview(post.content, 90) }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusMap[post.status] ?? 'bg-slate-100 text-slate-600'">{{ post.status }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">
                                    {{ fmtDate(post.scheduled_at || post.published_at || post.created_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-0.5">
                                        <button
                                            v-if="['draft','failed'].includes(post.status)"
                                            type="button"
                                            title="Edit"
                                            class="rounded p-1.5 hover:bg-muted"
                                            @click="openEdit(post)"
                                        >
                                            <Edit2 class="h-4 w-4" />
                                        </button>
                                        <button
                                            type="button"
                                            title="Duplicate as draft"
                                            class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                            @click="duplicatePost(post.id)"
                                        >
                                            <Copy class="h-4 w-4" />
                                        </button>
                                        <button
                                            v-if="['draft','failed','scheduled'].includes(post.status)"
                                            type="button"
                                            title="Publish now"
                                            class="rounded p-1.5 text-blue-600 hover:bg-blue-50"
                                            @click="publishNow(post.id)"
                                        >
                                            <Send class="h-4 w-4" />
                                        </button>
                                        <button
                                            type="button"
                                            title="Delete"
                                            class="rounded p-1.5 text-red-500 hover:bg-red-50"
                                            @click="deletePost(post.id)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ListPagination v-if="posts.data.length" :paginator="posts" label="posts" />
            </div>
        </template>

        <template v-else>
            <div class="grid grid-cols-1 gap-4 p-6 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-1">
                    <div class="rounded-xl border border-blue-200/60 bg-gradient-to-br from-blue-50/80 to-card p-4 shadow-sm dark:border-blue-900/40 dark:from-blue-950/20">
                        <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-blue-700 dark:text-blue-300">
                            <Sparkles class="h-4 w-4" /> AI Assistant
                        </div>
                        <div class="space-y-2">
                            <input v-model="aiTopic" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20" placeholder="Topic or idea" />
                            <div class="grid grid-cols-2 gap-2">
                                <select v-model="aiStyle" class="rounded-lg border border-border bg-background px-2 py-2 text-sm">
                                    <option value="professional">Professional</option>
                                    <option value="casual">Casual</option>
                                    <option value="motivational">Motivational</option>
                                    <option value="educational">Educational</option>
                                    <option value="storytelling">Storytelling</option>
                                </select>
                                <select v-model="aiLength" class="rounded-lg border border-border bg-background px-2 py-2 text-sm">
                                    <option value="short">Short</option>
                                    <option value="medium">Medium</option>
                                    <option value="long">Long</option>
                                </select>
                            </div>
                            <ToggleField v-model="aiGenerateImage" :disabled="aiLoading || !aiConfigured" description="Generate a related image.">
                                Also generate a related image 
                            </ToggleField>
                            <AppToolbarButton class="w-full" :disabled="aiLoading || !aiConfigured || !aiTopic.trim()" @click="generateAi">
                                <Loader2 v-if="aiLoading" class="h-4 w-4 animate-spin" />
                                <Sparkles v-else class="h-4 w-4" />
                                Generate AI Content
                            </AppToolbarButton>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" :disabled="aiLoading || !form.content.trim()" :class="aiChipClass" @click="improve('make_viral')">Make Viral</button>
                                <button type="button" :disabled="aiLoading || !form.content.trim()" :class="aiChipClass" @click="improve('add_hook')">Add Hook</button>
                                <button type="button" :disabled="aiLoading || !form.content.trim()" :class="aiChipClass" @click="improve('add_cta')">Add CTA</button>
                                <button type="button" :disabled="aiLoading || !form.content.trim()" :class="aiChipClass" @click="improve('bullet_points')">Bullets</button>
                                <button type="button" :disabled="aiLoading || !form.content.trim()" :class="aiChipClass" @click="rewrite('shorten')">Shorten</button>
                                <button type="button" :disabled="aiLoading || !form.content.trim()" :class="aiChipClass" @click="rewrite('expand')">Expand</button>
                            </div>
                            <p v-if="aiError" class="text-xs text-red-500">{{ aiError }}</p>
                            <p v-if="!aiConfigured" class="text-xs text-orange-600">AI is not available right now. Contact your administrator.</p>
                            <p class="pt-1 text-xs text-muted-foreground">
                                Need a starting point?
                                <Link href="/inspiration" class="font-medium text-primary hover:underline">Browse Inspiration</Link>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="space-y-4 lg:col-span-2">
                    <div v-if="fromInspiration" class="flex items-center gap-2 rounded-xl border border-amber-300/50 bg-gradient-to-r from-amber-50 to-orange-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800/40 dark:from-amber-950/30 dark:text-amber-200">
                        <Lightbulb class="h-4 w-4 shrink-0" />
                        <span>Loaded from <strong>Inspiration</strong> — edit below, then choose how to publish.</span>
                    </div>

                    <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                        <div class="mb-3 text-sm font-semibold">Post type</div>
                        <div class="grid grid-cols-3 gap-2">
                            <button type="button" :class="postTypeClass('text')" class="inline-flex items-center justify-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-medium transition" @click="form.post_type = 'text'">
                                <Type class="h-4 w-4" /> Text
                            </button>
                            <button type="button" :class="postTypeClass('image')" class="inline-flex items-center justify-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-medium transition" @click="form.post_type = 'image'">
                                <ImageIcon class="h-4 w-4" /> Image
                            </button>
                            <button type="button" :class="postTypeClass('video')" class="inline-flex items-center justify-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-medium transition" @click="form.post_type = 'video'">
                                <Video class="h-4 w-4" /> Video
                            </button>
                        </div>

                        <div v-if="form.post_type === 'image'" class="mt-4 space-y-3">
                            <input
                                ref="imageInputRef"
                                type="file"
                                multiple
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="hidden"
                                @change="onImageChange"
                            />

                            <button
                                type="button"
                                class="flex w-full flex-col items-center gap-2 rounded-xl border-2 border-dashed border-blue-300/70 bg-gradient-to-b from-blue-50/80 to-background px-4 py-8 text-center transition hover:border-blue-400 hover:bg-blue-50 dark:border-blue-800/60 dark:from-blue-950/30 dark:hover:bg-blue-950/40"
                                @click="openImagePicker"
                            >
                                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-500/10 text-blue-600">
                                    <Upload class="h-6 w-6" />
                                </span>
                                <span class="text-sm font-semibold text-foreground">Click to upload images</span>
                                <span class="text-xs text-muted-foreground">JPG, PNG, or WebP · up to 10 files</span>
                            </button>

                            <div v-if="hasUploadedImages" class="rounded-lg border border-border bg-muted/20 p-3">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <p class="text-xs font-medium text-foreground">
                                        {{ form.images.length ? `${form.images.length} file(s) selected` : 'AI-generated image attached' }}
                                    </p>
                                    <button
                                        v-if="form.images.length"
                                        type="button"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-red-600 hover:underline"
                                        @click="clearImages"
                                    >
                                        <X class="h-3 w-3" /> Clear
                                    </button>
                                </div>
                                <ul v-if="form.images.length" class="mb-3 space-y-1">
                                    <li
                                        v-for="(file, idx) in form.images"
                                        :key="`${file.name}-${idx}`"
                                        class="flex items-center justify-between gap-2 rounded-md bg-background px-2 py-1.5 text-xs"
                                    >
                                        <span class="flex min-w-0 items-center gap-1.5 truncate">
                                            <ImageIcon class="h-3.5 w-3.5 shrink-0 text-blue-500" />
                                            <span class="truncate">{{ file.name }}</span>
                                        </span>
                                        <span class="shrink-0 text-muted-foreground">{{ formatFileSize(file.size) }}</span>
                                    </li>
                                </ul>
                                <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                                    <img v-for="(src, idx) in imagePreviews" :key="idx" :src="src" alt="" class="h-24 w-full rounded-lg border object-cover shadow-sm" />
                                    <img v-if="form.ai_image_url" :src="form.ai_image_url" alt="AI generated" class="h-24 w-full rounded-lg border object-cover shadow-sm" />
                                </div>
                            </div>
                        </div>

                        <div v-if="form.post_type === 'video'" class="mt-4 space-y-3">
                            <input
                                ref="videoInputRef"
                                type="file"
                                accept=".mp4,.mov,.avi,.wmv,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv"
                                class="hidden"
                                @change="onVideoChange"
                            />

                            <button
                                type="button"
                                class="flex w-full flex-col items-center gap-2 rounded-xl border-2 border-dashed border-violet-300/70 bg-gradient-to-b from-violet-50/80 to-background px-4 py-8 text-center transition hover:border-violet-400 hover:bg-violet-50 dark:border-violet-800/60 dark:from-violet-950/30 dark:hover:bg-violet-950/40"
                                @click="openVideoPicker"
                            >
                                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-violet-500/10 text-violet-600">
                                    <Video class="h-6 w-6" />
                                </span>
                                <span class="text-sm font-semibold text-foreground">Click to upload a video</span>
                                <span class="text-xs text-muted-foreground">MP4, MOV, AVI, or WMV</span>
                            </button>

                            <div v-if="form.video" class="flex items-center justify-between gap-3 rounded-lg border border-violet-200 bg-violet-50/50 px-3 py-3 dark:border-violet-900/40 dark:bg-violet-950/20">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-500/15 text-violet-600">
                                        <Video class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">{{ selectedVideoName }}</p>
                                        <p class="text-xs text-muted-foreground">{{ formatFileSize(form.video.size) }}</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:bg-background"
                                    @click="clearVideo"
                                >
                                    <X class="h-3 w-3" /> Remove
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                        <label class="mb-2 block text-sm font-semibold">Post content</label>
                        <textarea
                            ref="contentTextareaRef"
                            v-model="form.content"
                            rows="12"
                            class="w-full rounded-xl border border-border bg-muted/20 px-5 py-5 text-sm leading-relaxed focus:border-blue-400 focus:bg-background focus:outline-none focus:ring-2 focus:ring-blue-500/20"
                            placeholder="Write your LinkedIn post here…"
                        />
                        <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                            <span class="text-muted-foreground">Main post text — hashtags go in the field below.</span>
                            <span :class="charCount > charMax ? 'text-red-500 font-medium' : 'text-muted-foreground'">{{ charCount }}/{{ charMax }}</span>
                        </div>

                        <label class="mb-2 mt-5 block text-sm font-semibold">Hashtags</label>
                        <input
                            v-model="form.hashtags"
                            class="w-full rounded-xl border border-border bg-muted/20 px-4 py-3 text-sm focus:border-blue-400 focus:bg-background focus:outline-none focus:ring-2 focus:ring-blue-500/20"
                            :placeholder="form.hashtags ? '' : 'Auto-filled when you generate with AI — e.g. #linkedin #b2b #growth'"
                        />
                        <p class="mt-1.5 text-xs text-muted-foreground">Appended to your post when you publish. Leave empty if you included tags in the body.</p>

                        <div class="mt-5 rounded-xl border border-border bg-muted/20 p-4">
                            <p class="mb-1 text-sm font-semibold">Choose what happens next</p>
                            <p class="mb-3 text-xs text-muted-foreground">Pick one option — the button below will match your choice.</p>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <button type="button" :class="actionCardClass('draft')" @click="setAction('draft')">
                                    <span class="inline-flex items-center gap-1.5 text-sm font-semibold"><Save class="h-4 w-4" /> Save draft</span>
                                    <span class="text-xs text-muted-foreground">Keep in CRM to edit later</span>
                                </button>
                                <button type="button" :class="actionCardClass('publish')" @click="setAction('publish')">
                                    <span class="inline-flex items-center gap-1.5 text-sm font-semibold"><Rocket class="h-4 w-4" /> Publish now</span>
                                    <span class="text-xs text-muted-foreground">Post to LinkedIn immediately</span>
                                </button>
                                <button type="button" :class="actionCardClass('schedule')" @click="setAction('schedule')">
                                    <span class="inline-flex items-center gap-1.5 text-sm font-semibold"><Clock class="h-4 w-4" /> Schedule</span>
                                    <span class="text-xs text-muted-foreground">Pick a date & time</span>
                                </button>
                            </div>

                            <div v-if="form.action === 'schedule'" class="mt-3 rounded-lg border border-violet-200 bg-violet-50/50 p-3 dark:border-violet-900/40 dark:bg-violet-950/20">
                                <label class="mb-1.5 block text-xs font-medium text-violet-800 dark:text-violet-200">Publish date & time</label>
                                <input v-model="form.scheduled_at" type="datetime-local" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm" />
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                            <div v-if="!hasLinkedIn" class="flex items-center gap-1.5 text-xs text-orange-600">
                                <AlertCircle class="h-4 w-4 shrink-0" /> LinkedIn not connected — publish will fail until you connect.
                            </div>
                            <div v-else class="text-xs text-muted-foreground">
                                {{ form.action === 'publish' ? 'Will publish to LinkedIn when you confirm.' : form.action === 'schedule' ? 'Post will queue for the selected time.' : 'Saved as draft — publish anytime from your posts list.' }}
                            </div>
                            <AppToolbarButton
                                :variant="form.action === 'publish' ? 'default' : form.action === 'schedule' ? 'violet' : 'slate'"
                                :disabled="form.processing || !form.content.trim() || (form.action === 'schedule' && !form.scheduled_at)"
                                @click="submitCompose"
                            >
                                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
                                <Send v-else-if="form.action === 'publish'" class="h-4 w-4" />
                                <Clock v-else-if="form.action === 'schedule'" class="h-4 w-4" />
                                <Save v-else class="h-4 w-4" />
                                {{ submitLabel }}
                            </AppToolbarButton>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
