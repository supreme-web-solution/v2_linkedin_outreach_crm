<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Bot, Pencil, Plus, Power, Search, Trash2 } from '@lucide/vue';
import CheckboxField from '@/components/CheckboxField.vue';
import { computed, ref } from 'vue';
import ClientPagination from '@/components/crm/ClientPagination.vue';
import { useClientList } from '@/composables/useClientList';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Auto-Responses', href: '/auto-responses' }] },
});

interface Rule {
    id: number;
    message_type: string;
    message_keywords: string | null;
    message_body: string;
    enabled: boolean;
    created_at: string;
}

const inertiaPage = usePage();
const flashSuccess = computed(() => (inertiaPage.props.flash as { success?: string })?.success);
const flashError = computed(() => (inertiaPage.props.flash as { error?: string })?.error);

const props = defineProps<{
    rules: Rule[];
    hasOrg: boolean;
    messageTypes: Array<{ value: string; label: string }>;
}>();

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

const showForm = ref(false);
const editing = ref<Rule | null>(null);

const form = useForm({
    message_type: 'contains',
    message_keywords: '',
    message_body: '',
    enabled: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.message_type = 'contains';
    form.enabled = true;
    showForm.value = true;
}

function openEdit(rule: Rule) {
    editing.value = rule;
    form.message_type = rule.message_type;
    form.message_keywords = rule.message_keywords ?? '';
    form.message_body = rule.message_body;
    form.enabled = rule.enabled;
    showForm.value = true;
}

function submit() {
    if (editing.value) {
        form.put(`/auto-responses/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false; editing.value = null; },
        });
    } else {
        form.post('/auto-responses', {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false; form.reset(); },
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
</script>

<template>
    <Head title="Auto-Responses" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Auto-Responses</h1>
                <p class="text-sm text-muted-foreground">Automatic LinkedIn replies when inbound messages match your rules (via Unipile).</p>
            </div>
            <button
                v-if="hasOrg"
                type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                @click="openCreate"
            >
                <Plus class="h-4 w-4" /> New rule
            </button>
        </div>

        <p v-if="flashSuccess" class="rounded-lg bg-green-100 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ flashSuccess }}</p>
        <p v-if="flashError" class="rounded-lg bg-red-100 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">{{ flashError }}</p>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400">
            Connect your workspace via the extension first.
        </div>

        <div v-if="showForm && hasOrg" class="rounded-xl border border-primary/30 bg-primary/5 p-4">
            <h2 class="mb-3 text-sm font-semibold">{{ editing ? 'Edit rule' : 'New rule' }}</h2>
            <form class="grid gap-3" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">
                        <span class="font-medium">Match type</span>
                        <select v-model="form.message_type" class="rounded-lg border border-border bg-background px-3 py-2">
                            <option v-for="t in messageTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </label>
                    <label v-if="form.message_type !== 'any'" class="grid gap-1 text-sm">
                        <span class="font-medium">Keywords</span>
                        <input v-model="form.message_keywords" type="text" placeholder="e.g. pricing, demo" class="rounded-lg border border-border bg-background px-3 py-2" />
                    </label>
                </div>
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Reply message</span>
                    <textarea v-model="form.message_body" rows="4" required class="rounded-lg border border-border bg-background px-3 py-2" />
                </label>
                <CheckboxField v-model="form.enabled">Enabled</CheckboxField>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground" :disabled="form.processing">Save</button>
                    <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm" @click="showForm = false">Cancel</button>
                </div>
            </form>
        </div>

        <div v-if="hasOrg && !showForm" class="flex flex-wrap items-center gap-2">
            <div class="flex min-w-[200px] flex-1 max-w-md items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
                <Search class="h-4 w-4 text-muted-foreground" />
                <input v-model="search" type="search" placeholder="Search rules…" class="w-full bg-transparent text-sm outline-none" />
            </div>
            <select v-model="enabledFilter" class="rounded-lg border border-border bg-card px-3 py-2 text-sm">
                <option value="all">All rules</option>
                <option value="enabled">Active only</option>
                <option value="disabled">Disabled only</option>
            </select>
        </div>

        <div v-if="hasOrg && total === 0 && !showForm" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center">
            <Bot class="h-10 w-10 text-muted-foreground/40" />
            <p class="font-medium">No rules yet</p>
            <p class="text-sm text-muted-foreground">Create a rule to auto-reply when prospects message you on LinkedIn.</p>
        </div>

        <div v-else-if="hasOrg" class="flex flex-col gap-3">
            <div
                v-for="rule in paginatedRules"
                :key="rule.id"
                class="rounded-xl border border-border bg-card p-4 shadow-sm"
                :class="!rule.enabled ? 'opacity-60' : ''"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <div class="mb-1 flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">{{ typeLabel(rule.message_type) }}</span>
                            <span v-if="rule.message_keywords" class="text-xs text-muted-foreground">“{{ rule.message_keywords }}”</span>
                        </div>
                        <p class="text-sm whitespace-pre-wrap">{{ rule.message_body }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="rule.enabled ? 'bg-green-500/10 text-green-600' : 'bg-muted text-muted-foreground'">
                        {{ rule.enabled ? 'Active' : 'Off' }}
                    </span>
                </div>
                <div class="mt-3 flex gap-2 border-t border-border pt-3">
                    <button type="button" class="inline-flex items-center gap-1 text-xs text-primary hover:underline" @click="openEdit(rule)">
                        <Pencil class="h-3 w-3" /> Edit
                    </button>
                    <button type="button" class="inline-flex items-center gap-1 text-xs hover:underline" @click="toggleRule(rule.id)">
                        <Power class="h-3 w-3" /> {{ rule.enabled ? 'Disable' : 'Enable' }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-1 text-xs text-red-600 hover:underline" @click="deleteRule(rule)">
                        <Trash2 class="h-3 w-3" /> Delete
                    </button>
                </div>
            </div>
            <ClientPagination v-model:page="listPage" :total-pages="totalPages" :total="total" :per-page="8" label="rules" />
        </div>
    </div>
</template>
