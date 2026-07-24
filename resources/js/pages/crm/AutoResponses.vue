<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Bot, Loader2, MessageSquare, Pencil, Plus, Power, Search, Sparkles, Trash2, Zap } from '@lucide/vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import CheckboxField from '@/components/CheckboxField.vue';
import ClientPagination from '@/components/crm/ClientPagination.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { computed, ref } from 'vue';
import { useClientList } from '@/composables/useClientList';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Auto-Responses', href: '/auto-responses' }] },
});

interface Rule {
    id: number;
    message_type: string;
    message_keywords: string | null;
    message_body: string;
    platforms: string[];
    enabled: boolean;
    created_at: string;
}

interface PlatformOption {
    key: string;
    label: string;
    color: string;
}

interface ConnectedChannel {
    channel: string;
    label: string;
    connected: boolean;
}

const inertiaPage = usePage();
const flashSuccess = computed(() => (inertiaPage.props.flash as { success?: string })?.success);
const flashError = computed(() => (inertiaPage.props.flash as { error?: string })?.error);

const props = defineProps<{
    rules: Rule[];
    hasOrg: boolean;
    messageTypes: Array<{ value: string; label: string }>;
    platformOptions: PlatformOption[];
}>();

const connectedChannels = computed(() => (inertiaPage.props.connectedChannels as ConnectedChannel[]) ?? []);

const connectedPlatformOptions = computed(() => {
    const connected = new Set(connectedChannels.value.filter((c) => c.connected).map((c) => c.channel));
    return props.platformOptions.filter((p) => connected.has(p.key));
});

const hasConnectedPlatforms = computed(() => connectedPlatformOptions.value.length > 0);

const enabledFilter = ref<'all' | 'enabled' | 'disabled'>('all');

const {
    search,
    page: listPage,
    paginated: paginatedRules,
    totalPages,
    total,
} = useClientList(computed(() => props.rules), {
    perPage: 8,
    searchKeys: (r) => [r.message_body, r.message_keywords ?? '', r.message_type],
    filterFn: (r) => {
        if (enabledFilter.value === 'enabled') return r.enabled;
        if (enabledFilter.value === 'disabled') return !r.enabled;
        return true;
    },
});

const showRuleModal = ref(false);
const editing = ref<Rule | null>(null);
const allPlatformsSelected = ref(true);

const form = useForm({
    message_type: 'contains',
    message_keywords: '',
    message_body: '',
    platforms: [] as string[],
    enabled: true,
});

const hasRules = computed(() => props.rules.length > 0);
const isFilteredEmpty = computed(() => hasRules.value && total.value === 0);
const canSubmitPlatforms = computed(() => allPlatformsSelected.value || form.platforms.length > 0);

function openCreate() {
    editing.value = null;
    form.reset();
    form.message_type = 'contains';
    form.enabled = true;
    form.platforms = [];
    allPlatformsSelected.value = true;
    showRuleModal.value = true;
}

function openEdit(rule: Rule) {
    editing.value = rule;
    form.message_type = rule.message_type;
    form.message_keywords = rule.message_keywords ?? '';
    form.message_body = rule.message_body;
    form.platforms = [...rule.platforms];
    form.enabled = rule.enabled;
    allPlatformsSelected.value = rule.platforms.length === 0;
    showRuleModal.value = true;
}

function closeRuleModal() {
    showRuleModal.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
    allPlatformsSelected.value = true;
}

function selectAllPlatforms() {
    allPlatformsSelected.value = true;
    form.platforms = [];
}

function togglePlatform(key: string) {
    allPlatformsSelected.value = false;
    const next = new Set(form.platforms);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    form.platforms = [...next];
}

function isPlatformSelected(key: string): boolean {
    return allPlatformsSelected.value || form.platforms.includes(key);
}

function submit() {
    if (!canSubmitPlatforms.value) return;

    form.transform((data) => ({
        ...data,
        platforms: allPlatformsSelected.value ? [] : data.platforms,
    }));

    if (editing.value) {
        form.put(`/auto-responses/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess: closeRuleModal,
        });
    } else {
        form.post('/auto-responses', {
            preserveScroll: true,
            onSuccess: closeRuleModal,
        });
    }
}

function toggleRule(id: number) {
    router.post(`/auto-responses/${id}/toggle`, {}, { preserveScroll: true });
}

function deleteRule(rule: Rule) {
    if (!confirm('Delete this auto-response rule?')) return;
    router.delete(`/auto-responses/${rule.id}`, { preserveScroll: true });
}

const typeLabel = (v: string) => props.messageTypes.find((t) => t.value === v)?.label ?? v;
const platformLabel = (key: string) => props.platformOptions.find((p) => p.key === key)?.label ?? key;
</script>

<template>
    <Head title="Auto-Responses" />

    <div class="flex flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Auto-Responses</h1>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Reply automatically when inbound messages match your rules — works across LinkedIn, WhatsApp, and other connected channels.
                </p>
            </div>
            <AppToolbarButton v-if="hasOrg" @click="openCreate">
                <Plus class="h-4 w-4" /> New rule
            </AppToolbarButton>
        </div>

        <p v-if="flashSuccess" class="rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-800 dark:border-green-900/40 dark:bg-green-950/30 dark:text-green-300">
            {{ flashSuccess }}
        </p>
        <p v-if="flashError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-300">
            {{ flashError }}
        </p>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400">
            Connect your workspace via the extension first.
        </div>

        <template v-else>
            <div v-if="hasRules" class="flex flex-wrap items-center gap-2">
                <div class="flex min-w-[200px] max-w-md flex-1 items-center gap-2 rounded-xl border border-border bg-card px-3 py-2 shadow-sm">
                    <Search class="h-4 w-4 shrink-0 text-muted-foreground" />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search rules…"
                        class="w-full bg-transparent text-sm outline-none"
                    />
                </div>
                <select v-model="enabledFilter" class="rounded-xl border border-border bg-card px-3 py-2 text-sm shadow-sm">
                    <option value="all">All rules</option>
                    <option value="enabled">Active only</option>
                    <option value="disabled">Disabled only</option>
                </select>
            </div>

            <!-- Empty state: no rules at all -->
            <div
                v-if="!hasRules"
                class="relative overflow-hidden rounded-2xl border border-dashed border-blue-200/80 bg-gradient-to-br from-blue-50/90 via-background to-violet-50/60 p-10 text-center dark:border-blue-900/50 dark:from-blue-950/25 dark:to-violet-950/15 md:p-14"
            >
                <div class="pointer-events-none absolute -right-8 -top-8 size-40 rounded-full bg-blue-400/10 blur-3xl" />
                <div class="pointer-events-none absolute -bottom-10 -left-6 size-36 rounded-full bg-violet-400/10 blur-3xl" />

                <div class="relative mx-auto mb-5 flex size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-500 to-violet-600 text-white shadow-lg shadow-blue-500/30 ring-1 ring-white/20">
                    <Bot class="size-8 stroke-[1.75]" />
                </div>

                <h2 class="relative text-lg font-semibold text-foreground">No auto-response rules yet</h2>
                <p class="relative mx-auto mt-2 max-w-lg text-sm text-muted-foreground">
                    Set up keyword rules once — the app replies for you when prospects message you, even while you’re away from the inbox.
                </p>

                <ul class="relative mx-auto mt-6 grid max-w-md gap-3 text-left text-sm text-muted-foreground sm:grid-cols-1">
                    <li class="flex items-start gap-3 rounded-xl border border-border/60 bg-card/70 px-4 py-3 backdrop-blur-sm">
                        <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600">
                            <Zap class="size-3.5" />
                        </span>
                        <span>Match words like <strong class="font-medium text-foreground">pricing</strong>, <strong class="font-medium text-foreground">demo</strong>, or any inbound message</span>
                    </li>
                    <li class="flex items-start gap-3 rounded-xl border border-border/60 bg-card/70 px-4 py-3 backdrop-blur-sm">
                        <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-violet-500/10 text-violet-600">
                            <MessageSquare class="size-3.5" />
                        </span>
                        <span>Replies go out on the same thread — LinkedIn, WhatsApp, and more</span>
                    </li>
                    <li class="flex items-start gap-3 rounded-xl border border-border/60 bg-card/70 px-4 py-3 backdrop-blur-sm">
                        <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600">
                            <Sparkles class="size-3.5" />
                        </span>
                        <span>Turn rules on or off anytime without deleting them</span>
                    </li>
                </ul>

                <div class="relative mt-8">
                    <AppToolbarButton @click="openCreate">
                        <Plus class="h-4 w-4" /> Create your first rule
                    </AppToolbarButton>
                </div>
            </div>

            <!-- Filtered empty -->
            <div
                v-else-if="isFilteredEmpty"
                class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border bg-muted/20 p-12 text-center"
            >
                <Search class="size-10 text-muted-foreground/40" />
                <p class="font-medium">No rules match your search</p>
                <p class="text-sm text-muted-foreground">Try different keywords or reset the status filter.</p>
            </div>

            <!-- Rules list -->
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="rule in paginatedRules"
                    :key="rule.id"
                    class="rounded-2xl border border-border bg-card p-4 shadow-sm transition-shadow hover:shadow-md"
                    :class="!rule.enabled ? 'opacity-70' : ''"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                            :class="rule.enabled ? 'bg-gradient-to-br from-blue-500/15 to-violet-500/15 text-blue-600' : 'bg-muted text-muted-foreground'"
                        >
                            <Bot class="size-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="mb-1.5 flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
                                    {{ typeLabel(rule.message_type) }}
                                </span>
                                <span v-if="rule.message_keywords" class="truncate text-xs text-muted-foreground">
                                    “{{ rule.message_keywords }}”
                                </span>
                                <span
                                    class="ml-auto shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium sm:ml-0"
                                    :class="rule.enabled ? 'bg-green-500/10 text-green-700 dark:text-green-400' : 'bg-muted text-muted-foreground'"
                                >
                                    {{ rule.enabled ? 'Active' : 'Off' }}
                                </span>
                            </div>
                            <div class="mb-2 flex flex-wrap gap-1.5">
                                <span
                                    v-if="!rule.platforms.length"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                                >
                                    <span class="flex -space-x-1">
                                        <OutreachChannelIcon
                                            v-for="p in connectedPlatformOptions.slice(0, 4)"
                                            :key="p.key"
                                            :channel="p.key"
                                            :size="14"
                                            class="h-3.5 w-3.5 ring-1 ring-background"
                                        />
                                    </span>
                                    All connected platforms
                                </span>
                                <span
                                    v-for="platformKey in rule.platforms"
                                    :key="`${rule.id}-${platformKey}`"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                                >
                                    <OutreachChannelIcon :channel="platformKey" :size="14" class="h-3.5 w-3.5" />
                                    {{ platformLabel(platformKey) }}
                                </span>
                            </div>
                            <p class="text-sm leading-relaxed whitespace-pre-wrap text-foreground/90">{{ rule.message_body }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-border/60 pt-3">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted"
                            @click="openEdit(rule)"
                        >
                            <Pencil class="h-3 w-3" /> Edit
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted"
                            @click="toggleRule(rule.id)"
                        >
                            <Power class="h-3 w-3" /> {{ rule.enabled ? 'Disable' : 'Enable' }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 transition-colors hover:bg-red-50 dark:border-red-900/40 dark:hover:bg-red-950/30"
                            @click="deleteRule(rule)"
                        >
                            <Trash2 class="h-3 w-3" /> Delete
                        </button>
                    </div>
                </div>
                <ClientPagination v-model:page="listPage" :total-pages="totalPages" :total="total" :per-page="8" label="rules" />
            </div>
        </template>
    </div>

    <!-- New / edit rule modal -->
    <Dialog v-model:open="showRuleModal">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ editing ? 'Edit rule' : 'New auto-response rule' }}</DialogTitle>
                <DialogDescription>
                    When an inbound message matches, this reply is sent automatically on the same conversation thread.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1.5 text-sm">
                        <span class="font-medium">Match type</span>
                        <select v-model="form.message_type" class="rounded-xl border border-border bg-background px-3 py-2.5 text-sm">
                            <option v-for="t in messageTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </label>
                    <label v-if="form.message_type !== 'any'" class="grid gap-1.5 text-sm">
                        <span class="font-medium">Keywords</span>
                        <input
                            v-model="form.message_keywords"
                            type="text"
                            placeholder="e.g. pricing, demo"
                            class="rounded-xl border border-border bg-background px-3 py-2.5 text-sm"
                        />
                    </label>
                </div>

                <div class="grid gap-2 text-sm">
                    <span class="font-medium">Platforms</span>
                    <p class="text-xs text-muted-foreground">Choose which connected channels this rule should run on.</p>

                    <div v-if="!hasConnectedPlatforms" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-300">
                        No platforms connected yet.
                        <a href="/integrations" class="font-medium underline">Connect a channel</a> first.
                    </div>

                    <template v-else>
                        <button
                            type="button"
                            class="flex w-full items-center justify-between rounded-xl border px-3 py-2.5 text-left transition-colors"
                            :class="allPlatformsSelected ? 'border-primary bg-primary/5 text-primary' : 'border-border hover:bg-muted/40'"
                            @click="selectAllPlatforms"
                        >
                            <span class="font-medium">All connected platforms</span>
                            <span v-if="allPlatformsSelected" class="text-xs">Selected</span>
                        </button>

                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="platform in connectedPlatformOptions"
                                :key="platform.key"
                                type="button"
                                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors"
                                :class="isPlatformSelected(platform.key) && !allPlatformsSelected
                                    ? 'border-primary bg-primary/5 text-foreground ring-1 ring-primary/20'
                                    : 'border-border bg-background text-muted-foreground hover:bg-muted/50'"
                                @click="togglePlatform(platform.key)"
                            >
                                <OutreachChannelIcon :channel="platform.key" :size="18" class="h-4 w-4" />
                                {{ platform.label }}
                            </button>
                        </div>

                        <p v-if="!canSubmitPlatforms" class="text-xs text-red-600">
                            Select at least one platform, or choose “All connected platforms”.
                        </p>
                    </template>
                </div>

                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">Reply message</span>
                    <textarea
                        v-model="form.message_body"
                        rows="5"
                        required
                        placeholder="Thanks for reaching out! Here’s a link to book a call…"
                        class="rounded-xl border border-border bg-background px-3 py-2.5 text-sm"
                    />
                </label>

                <CheckboxField v-model="form.enabled">Enabled — start replying when this rule matches</CheckboxField>

                <DialogFooter class="gap-2 pt-1 sm:gap-0">
                    <button
                        type="button"
                        class="rounded-xl border border-border px-4 py-2 text-sm font-medium hover:bg-muted/50"
                        @click="closeRuleModal"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-60"
                        :disabled="form.processing || !canSubmitPlatforms || !hasConnectedPlatforms"
                    >
                        <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
                        {{ editing ? 'Save changes' : 'Create rule' }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
