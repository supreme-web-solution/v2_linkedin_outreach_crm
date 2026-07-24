<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { AlertCircle, ChevronLeft, Clock, Edit2, FileText, Image as ImageIcon, Loader2, PenLine, RefreshCw, Send, Sparkles, Trash2 } from '@lucide/vue';
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

type Inspiration = {
    id: number;
    content: string;
    created_at: string;
    meta?: Record<string, unknown>;
};

type Template = {
    id: number;
    title: string;
    category: string;
    industry: string;
    engagement_score: number;
    content: string;
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
    inspiration: Inspiration[];
    templates?: Template[];
    contentStats: { total: number; published: number; scheduled: number; draft: number };
    hasOrg: boolean;
    hasLinkedIn: boolean;
    aiConfigured?: boolean;
    rapidConfigured?: boolean;
}>();

const postSearch = ref(props.filters?.search ?? '');
const postStatusFilter = ref(props.filters?.status ?? 'all');

function applyPostFilters() {
    router.get('/content', {
        search: postSearch.value || undefined,
        status: postStatusFilter.value !== 'all' ? postStatusFilter.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true, only: ['posts', 'filters', 'contentStats'] });
}

const tab = ref<'writer' | 'inspiration'>('writer');
const panel = ref<'list' | 'compose'>('list');
const editingId = ref<number | null>(null);
const inspirationRows = ref<Inspiration[]>([...props.inspiration]);

const form = useForm({
    content: '',
    hashtags: '',
    scheduled_at: '',
    action: 'draft' as 'draft' | 'publish' | 'schedule',
    post_type: 'text' as 'text' | 'image' | 'video',
    images: [] as File[],
    video: null as File | null,
    ai_image_url: '',
});

const aiTopic = ref('');
const aiStyle = ref<'professional' | 'casual' | 'motivational' | 'educational' | 'storytelling'>('professional');
const aiLength = ref<'short' | 'medium' | 'long'>('medium');
const aiTone = ref<'professional' | 'casual' | 'motivational' | 'educational' | 'storytelling'>('professional');
const aiLoading = ref(false);
const aiError = ref('');

const inspireKeyword = ref('vibe coding');
const inspireLoading = ref(false);
const inspireError = ref('');
const templateSearch = ref('');
const templateCategory = ref('');

const templates = computed(() => props.templates ?? []);
const filteredTemplates = computed(() =>
    templates.value.filter((t) => {
        const searchOk = !templateSearch.value || `${t.title} ${t.content}`.toLowerCase().includes(templateSearch.value.toLowerCase());
        const categoryOk = !templateCategory.value || t.category === templateCategory.value;
        return searchOk && categoryOk;
    }),
);

const charMax = 3000;
const charCount = computed(() => form.content.length);

const imagePreviews = computed(() => form.images.map((f) => URL.createObjectURL(f)));

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
    form.reset();
    form.post_type = 'text';
}

function openEdit(post: Post) {
    panel.value = 'compose';
    editingId.value = post.id;
    form.content = post.content ?? '';
    form.hashtags = '';
    form.scheduled_at = post.scheduled_at ? post.scheduled_at.slice(0, 16) : '';
    form.post_type = (post.meta?.post_type as 'text' | 'image' | 'video') ?? 'text';
    form.images = [];
    form.video = null;
    form.ai_image_url = (post.meta?.ai_image_url as string) ?? '';
    form.action = 'draft';
}

function closeCompose() {
    panel.value = 'list';
    editingId.value = null;
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

function setAction(action: 'draft' | 'publish' | 'schedule') {
    form.action = action;
}

function submitCompose() {
    const opts = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeCompose();
            tab.value = 'writer';
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
    router.delete(`/content/${id}`);
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

async function apiGet(url: string) {
    const res = await fetch(url);
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
        });
        form.content = data.content ?? '';
        form.hashtags = data.hashtags ?? '';
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
        form.content = data.content ?? form.content;
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
        form.content = data.content ?? form.content;
    } catch (e) {
        aiError.value = e instanceof Error ? e.message : 'Rewrite failed.';
    } finally {
        aiLoading.value = false;
    }
}

async function generateImage() {
    if (!aiTopic.value.trim()) return;
    aiLoading.value = true;
    aiError.value = '';
    try {
        const data = await apiPost('/content/ai/generate-image', { prompt: `LinkedIn post illustration about ${aiTopic.value}` });
        form.ai_image_url = data.url ?? '';
        if (form.ai_image_url) form.post_type = 'image';
    } catch (e) {
        aiError.value = e instanceof Error ? e.message : 'Image generation failed.';
    } finally {
        aiLoading.value = false;
    }
}

function applyTemplate(t: Template) {
    const topic = aiTopic.value.trim() || 'your topic';
    form.content = t.content.replaceAll('{topic}', topic);
}

async function fetchInspiration() {
    if (!inspireKeyword.value.trim()) return;
    inspireLoading.value = true;
    inspireError.value = '';
    try {
        const data = await apiPost('/content/inspiration/fetch', { keyword: inspireKeyword.value, limit: 18 });
        inspirationRows.value = data.data ?? inspirationRows.value;
        tab.value = 'inspiration';
    } catch (e) {
        inspireError.value = e instanceof Error ? e.message : 'Fetch failed.';
    } finally {
        inspireLoading.value = false;
    }
}

async function useInspiration(id: number) {
    try {
        const data = await apiGet(`/content/inspiration/${id}/use`);
        openCreate();
        form.content = data.content ?? '';
    } catch (e) {
        inspireError.value = e instanceof Error ? e.message : 'Use failed.';
    }
}

async function remixInspiration(id: number) {
    try {
        const data = await apiPost(`/content/inspiration/${id}/remix`, { tone: aiTone.value });
        openCreate();
        form.content = data.content ?? '';
    } catch (e) {
        inspireError.value = e instanceof Error ? e.message : 'Remix failed.';
    }
}
</script>

<template>
    <Head title="Content Creator" />

    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center justify-between border-b border-border px-6 py-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Content Creator</h1>
                <p class="text-sm text-muted-foreground">AI Content Creation + Inspiration feed (old CRM style, cleaner).</p>
            </div>
            <button v-if="panel === 'list'" @click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                <PenLine class="h-4 w-4" /> Create New Post
            </button>
            <button v-else @click="closeCompose" class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm hover:bg-muted">
                <ChevronLeft class="h-4 w-4" /> Back to Posts
            </button>
        </div>

        <div v-if="!hasOrg" class="m-6 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-700">
            Connect your workspace first.
        </div>

        <template v-else-if="panel === 'list'">
            <div class="flex gap-2 px-6 pt-4">
                <button @click="tab = 'writer'" :class="tab === 'writer' ? 'bg-primary text-primary-foreground' : 'bg-card text-foreground border border-border'" class="rounded-lg px-3 py-1.5 text-sm font-medium">
                    AI Content Creation
                </button>
                <button @click="tab = 'inspiration'" :class="tab === 'inspiration' ? 'bg-primary text-primary-foreground' : 'bg-card text-foreground border border-border'" class="rounded-lg px-3 py-1.5 text-sm font-medium">
                    Inspiration
                </button>
            </div>

            <div v-if="tab === 'writer'" class="px-6 py-4">
                <div class="grid grid-cols-4 gap-3">
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

                <div class="mt-4 overflow-hidden rounded-xl border border-border bg-card">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/30">
                            <tr>
                                <th class="px-4 py-3 text-left">Content</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-left">Date</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="post in posts.data" :key="post.id">
                                <td class="max-w-lg px-4 py-3">
                                    <div class="font-medium text-foreground">{{ preview(post.content, 90) }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusMap[post.status] ?? 'bg-slate-100 text-slate-600'">{{ post.status }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">
                                    {{ fmtDate(post.scheduled_at || post.published_at || post.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button v-if="['draft','failed'].includes(post.status)" @click="openEdit(post)" class="rounded p-1.5 hover:bg-muted"><Edit2 class="h-4 w-4" /></button>
                                    <button v-if="['draft','failed','scheduled'].includes(post.status)" @click="publishNow(post.id)" class="rounded p-1.5 text-blue-600 hover:bg-blue-50"><Send class="h-4 w-4" /></button>
                                    <button v-if="post.status !== 'published'" @click="deletePost(post.id)" class="rounded p-1.5 text-red-500 hover:bg-red-50"><Trash2 class="h-4 w-4" /></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ListPagination v-if="posts.data.length" :paginator="posts" label="posts" />
            </div>

            <div v-else class="px-6 py-4">
                <div class="rounded-xl border border-border bg-card p-4">
                    <div class="mb-3 flex items-center gap-2">
                        <input v-model="inspireKeyword" class="w-80 rounded border border-border px-3 py-2 text-sm" placeholder="Search topic (e.g. vibe coding, founder branding)" />
                        <button @click="fetchInspiration" :disabled="inspireLoading" class="inline-flex items-center gap-2 rounded bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                            <Loader2 v-if="inspireLoading" class="h-4 w-4 animate-spin" />
                            <RefreshCw v-else class="h-4 w-4" />
                            Fetch via RapidAPI
                        </button>
                        <span v-if="!rapidConfigured" class="text-xs text-orange-600">RAPIDAPI_KEY missing</span>
                    </div>
                    <p v-if="inspireError" class="text-xs text-red-500">{{ inspireError }}</p>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <div v-for="row in inspirationRows" :key="row.id" class="rounded-xl border border-border bg-card p-4">
                        <p class="mb-2 line-clamp-5 text-sm text-foreground">{{ row.content }}</p>
                        <div class="mb-2 text-xs text-muted-foreground">{{ fmtDate(row.created_at) }}</div>
                        <div class="flex gap-2">
                            <button @click="useInspiration(row.id)" class="rounded bg-primary px-2.5 py-1 text-xs text-primary-foreground hover:bg-primary/90">Use</button>
                            <button @click="remixInspiration(row.id)" class="rounded border border-border px-2.5 py-1 text-xs hover:bg-muted">Remix</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <template v-else>
            <div class="grid grid-cols-1 gap-4 p-6 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-1">
                    <div class="rounded-xl border border-border bg-card p-4">
                        <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-blue-700">
                            <Sparkles class="h-4 w-4" /> AI Assistant
                        </div>
                        <div class="space-y-2">
                            <input v-model="aiTopic" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="Topic or idea" />
                            <div class="grid grid-cols-2 gap-2">
                                <select v-model="aiStyle" class="rounded border border-border px-2 py-2 text-sm">
                                    <option value="professional">Professional</option>
                                    <option value="casual">Casual</option>
                                    <option value="motivational">Motivational</option>
                                    <option value="educational">Educational</option>
                                    <option value="storytelling">Storytelling</option>
                                </select>
                                <select v-model="aiLength" class="rounded border border-border px-2 py-2 text-sm">
                                    <option value="short">Short</option>
                                    <option value="medium">Medium</option>
                                    <option value="long">Long</option>
                                </select>
                            </div>
                            <button @click="generateAi" :disabled="aiLoading || !aiConfigured" class="w-full rounded bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                                <Loader2 v-if="aiLoading" class="mr-1 inline h-4 w-4 animate-spin" />
                                Generate with OpenAI
                            </button>
                            <button @click="generateImage" :disabled="aiLoading || !aiConfigured || !aiTopic.trim()" class="w-full rounded border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100 disabled:opacity-50">
                                <ImageIcon class="mr-1 inline h-4 w-4" /> Generate AI Image
                            </button>
                            <div class="grid grid-cols-2 gap-2">
                                <button @click="improve('make_viral')" class="rounded border border-border px-2 py-1.5 text-xs hover:bg-muted">Make Viral</button>
                                <button @click="improve('add_hook')" class="rounded border border-border px-2 py-1.5 text-xs hover:bg-muted">Add Hook</button>
                                <button @click="improve('add_cta')" class="rounded border border-border px-2 py-1.5 text-xs hover:bg-muted">Add CTA</button>
                                <button @click="improve('bullet_points')" class="rounded border border-border px-2 py-1.5 text-xs hover:bg-muted">Bullets</button>
                                <button @click="rewrite('shorten')" class="rounded border border-border px-2 py-1.5 text-xs hover:bg-muted">Shorten</button>
                                <button @click="rewrite('expand')" class="rounded border border-border px-2 py-1.5 text-xs hover:bg-muted">Expand</button>
                            </div>
                            <p v-if="aiError" class="text-xs text-red-500">{{ aiError }}</p>
                            <p v-if="!aiConfigured" class="text-xs text-orange-600">OPENAI_API_KEY missing in .env</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-card p-4">
                        <div class="mb-2 text-sm font-semibold">Templates</div>
                        <input v-model="templateSearch" class="mb-2 w-full rounded border border-border px-3 py-2 text-sm" placeholder="Search templates..." />
                        <select v-model="templateCategory" class="mb-2 w-full rounded border border-border px-3 py-2 text-sm">
                            <option value="">All categories</option>
                            <option value="engagement">Engagement</option>
                            <option value="storytelling">Storytelling</option>
                            <option value="educational">Educational</option>
                            <option value="sales">Sales</option>
                        </select>
                        <div class="max-h-64 space-y-2 overflow-auto">
                            <button v-for="t in filteredTemplates" :key="t.id" @click="applyTemplate(t)" class="w-full rounded border border-border px-3 py-2 text-left text-xs hover:bg-muted">
                                <div class="font-semibold">{{ t.title }} <span class="text-[10px] text-blue-600">{{ t.engagement_score }}%</span></div>
                                <div class="mt-0.5 line-clamp-2 text-muted-foreground">{{ t.content }}</div>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="space-y-4 lg:col-span-2">
                    <div class="rounded-xl border border-border bg-card p-4">
                        <div class="mb-3 text-sm font-semibold">Post Type</div>
                        <div class="grid grid-cols-3 gap-2">
                            <button @click="form.post_type = 'text'" :class="form.post_type === 'text' ? 'border-blue-500 bg-blue-50' : 'border-border'" class="rounded border px-3 py-2 text-sm">Text</button>
                            <button @click="form.post_type = 'image'" :class="form.post_type === 'image' ? 'border-blue-500 bg-blue-50' : 'border-border'" class="rounded border px-3 py-2 text-sm">Image</button>
                            <button @click="form.post_type = 'video'" :class="form.post_type === 'video' ? 'border-blue-500 bg-blue-50' : 'border-border'" class="rounded border px-3 py-2 text-sm">Video</button>
                        </div>

                        <div v-if="form.post_type === 'image'" class="mt-3 space-y-2">
                            <input type="file" multiple accept=".jpg,.jpeg,.png,.webp" @change="onImageChange" />
                            <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                                <img v-for="(src, idx) in imagePreviews" :key="idx" :src="src" class="h-24 w-full rounded border object-cover" />
                                <img v-if="form.ai_image_url" :src="form.ai_image_url" class="h-24 w-full rounded border object-cover" />
                            </div>
                        </div>
                        <div v-if="form.post_type === 'video'" class="mt-3">
                            <input type="file" accept=".mp4,.mov,.avi,.wmv" @change="onVideoChange" />
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-card p-4">
                        <label class="mb-2 block text-sm font-semibold">Post Content</label>
                        <textarea v-model="form.content" rows="10" class="w-full rounded border border-border px-3 py-3 text-sm focus:border-blue-400 focus:outline-none"></textarea>
                        <div class="mt-1 text-right text-xs text-muted-foreground">{{ charCount }}/{{ charMax }}</div>

                        <label class="mb-2 mt-3 block text-sm font-semibold">Hashtags</label>
                        <input v-model="form.hashtags" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="#linkedin #b2b #growth" />

                        <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
                            <button @click="setAction('draft')" :class="form.action === 'draft' ? 'ring-2 ring-blue-400' : ''" class="rounded border border-border px-3 py-2 text-sm">Save Draft</button>
                            <button @click="setAction('publish')" :class="form.action === 'publish' ? 'ring-2 ring-blue-400' : ''" class="rounded border border-border px-3 py-2 text-sm">Publish Now</button>
                        </div>

                        <div class="mt-2 rounded border border-border p-2">
                            <button @click="setAction('schedule')" :class="form.action === 'schedule' ? 'text-blue-600' : ''" class="mb-2 inline-flex items-center gap-1 text-sm"><Clock class="h-4 w-4" /> Schedule</button>
                            <input v-if="form.action === 'schedule'" v-model="form.scheduled_at" type="datetime-local" class="w-full rounded border border-border px-3 py-2 text-sm" />
                        </div>

                        <div class="mt-4 flex items-center justify-between">
                            <div v-if="!hasLinkedIn" class="text-xs text-orange-600"><AlertCircle class="mr-1 inline h-4 w-4" /> LinkedIn not connected yet.</div>
                            <button @click="submitCompose" :disabled="form.processing || !form.content.trim()" class="rounded bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                                <Loader2 v-if="form.processing" class="mr-1 inline h-4 w-4 animate-spin" />
                                {{ editingId ? 'Update Post' : 'Save Post' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
