<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { LogIn, Pencil, Plus, Search, Shield, Trash2, X } from '@lucide/vue';
import { Checkbox } from '@/components/ui/checkbox';
import { computed, ref } from 'vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Users', href: '/admin/users' }] },
});

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    linkedin_public_id: string | null;
    entitlements: string[];
    created_at: string | null;
}

const props = defineProps<{
    users: {
        data: ManagedUser[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    entitlementOptions: string[];
    filters: { email: string };
}>();

const searchEmail = ref(props.filters.email);
const showCreate = ref(false);
const editing = ref<ManagedUser | null>(null);
const permUser = ref<ManagedUser | null>(null);
const selectedEntitlements = ref<string[]>([]);

const createForm = useForm({ name: '', email: '', password: '' });
const editForm = useForm({ name: '', email: '', linkedin_public_id: '', password: '' });
const permForm = useForm({ user_id: 0, entitlements: [] as string[] });

const entitlementLabels = computed(() => props.entitlementOptions.filter((e) => !e.startsWith('view_')));

function runSearch() {
    router.get('/admin/users', { email: searchEmail.value || undefined }, { preserveState: true, replace: true });
}

function openEdit(user: ManagedUser) {
    editing.value = user;
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.linkedin_public_id = user.linkedin_public_id ?? '';
    editForm.password = '';
}

async function openPermissions(user: ManagedUser) {
    permUser.value = user;
    const res = await fetch(`/admin/users/${user.id}/permissions`);
    const data = await res.json();
    selectedEntitlements.value = data.assigned ?? user.entitlements;
}

function submitCreate() {
    createForm.post('/admin/users', { preserveScroll: true, onSuccess: () => { showCreate.value = false; createForm.reset(); } });
}

function submitEdit() {
    if (!editing.value) return;
    editForm.put(`/admin/users/${editing.value.id}`, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
}

function submitPermissions() {
    if (!permUser.value) return;
    permForm.user_id = permUser.value.id;
    permForm.entitlements = [...selectedEntitlements.value];
    permForm.put('/admin/users/entitlements', { preserveScroll: true, onSuccess: () => { permUser.value = null; } });
}

function destroyUser(user: ManagedUser) {
    if (!confirm(`Delete ${user.email} and all their data?`)) return;
    router.delete(`/admin/users/${user.id}`, { preserveScroll: true });
}

function impersonate(user: ManagedUser) {
    if (!confirm(`Log in as ${user.email}?`)) return;
    router.post(`/admin/users/${user.id}/impersonate`);
}

function toggleEntitlement(key: string, checked?: boolean | 'indeterminate') {
    const on = checked === undefined ? !selectedEntitlements.value.includes(key) : checked === true;
    const idx = selectedEntitlements.value.indexOf(key);
    if (on && idx < 0) {
        selectedEntitlements.value.push(key);
    } else if (!on && idx >= 0) {
        selectedEntitlements.value.splice(idx, 1);
    }
}
</script>

<template>
    <Head title="Manage Users" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Manage Users</h1>
                <p class="text-sm text-muted-foreground">Platform-wide user administration and license assignment.</p>
            </div>
            <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground" @click="showCreate = true">
                <Plus class="h-4 w-4" /> New user
            </button>
        </div>

        <form class="flex max-w-md gap-2" @submit.prevent="runSearch">
            <input v-model="searchEmail" type="search" placeholder="Search email…" class="flex-1 rounded-lg border border-border bg-background px-3 py-2 text-sm" />
            <button type="submit" class="rounded-lg border border-border px-3 py-2 text-sm hover:bg-muted"><Search class="h-4 w-4" /></button>
        </form>

        <div v-if="showCreate" class="rounded-xl border border-primary/30 bg-primary/5 p-4">
            <h2 class="mb-3 text-sm font-semibold">New user</h2>
            <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="submitCreate">
                <input v-model="createForm.name" required placeholder="Name" class="rounded-lg border border-border bg-background px-3 py-2 text-sm" />
                <input v-model="createForm.email" type="email" required placeholder="Email" class="rounded-lg border border-border bg-background px-3 py-2 text-sm" />
                <input v-model="createForm.password" type="password" required placeholder="Password" class="rounded-lg border border-border bg-background px-3 py-2 text-sm" />
                <div class="flex gap-2 sm:col-span-3">
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm text-primary-foreground" :disabled="createForm.processing">Create</button>
                    <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm" @click="showCreate = false">Cancel</button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Email</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Entitlements</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Created</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="user in users.data" :key="user.id" class="hover:bg-muted/20">
                        <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-3">{{ user.email }}</td>
                        <td class="px-4 py-3">
                            <span v-for="e in user.entitlements" :key="e" class="mr-1 inline-block rounded bg-primary/10 px-1.5 py-0.5 text-xs text-primary">{{ e }}</span>
                            <span v-if="user.entitlements.length === 0" class="text-xs text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ user.created_at?.slice(0, 10) ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="text-primary hover:underline" title="Edit" @click="openEdit(user)"><Pencil class="h-4 w-4" /></button>
                                <button type="button" class="text-primary hover:underline" title="Permissions" @click="openPermissions(user)"><Shield class="h-4 w-4" /></button>
                                <button type="button" class="text-purple-600 hover:underline" title="Impersonate" @click="impersonate(user)"><LogIn class="h-4 w-4" /></button>
                                <button type="button" class="text-red-600 hover:underline" title="Delete" @click="destroyUser(user)"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="flex items-center justify-between border-t border-border px-4 py-3 text-sm text-muted-foreground">
                <span>Page {{ users.current_page }} of {{ users.last_page }} ({{ users.total }} users)</span>
                <div class="flex gap-2">
                    <Link v-if="users.prev_page_url" :href="users.prev_page_url" class="rounded border border-border px-3 py-1 hover:bg-muted">Prev</Link>
                    <Link v-if="users.next_page_url" :href="users.next_page_url" class="rounded border border-border px-3 py-1 hover:bg-muted">Next</Link>
                </div>
            </div>
        </div>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-5 shadow-lg">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="font-semibold">Edit user</h2>
                    <button type="button" @click="editing = null"><X class="h-4 w-4" /></button>
                </div>
                <form class="grid gap-3" @submit.prevent="submitEdit">
                    <input v-model="editForm.name" required class="rounded-lg border border-border px-3 py-2 text-sm" />
                    <input v-model="editForm.email" type="email" required class="rounded-lg border border-border px-3 py-2 text-sm" />
                    <input v-model="editForm.linkedin_public_id" placeholder="LinkedIn public ID" class="rounded-lg border border-border px-3 py-2 text-sm" />
                    <input v-model="editForm.password" type="password" placeholder="New password (optional)" class="rounded-lg border border-border px-3 py-2 text-sm" />
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm text-primary-foreground" :disabled="editForm.processing">Save</button>
                </form>
            </div>
        </div>

        <div v-if="permUser" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-xl border border-border bg-card p-5 shadow-lg">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="font-semibold">Entitlements — {{ permUser.email }}</h2>
                    <button type="button" @click="permUser = null"><X class="h-4 w-4" /></button>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <label v-for="opt in entitlementLabels" :key="opt" class="flex items-center gap-2 text-sm">
                        <Checkbox :checked="selectedEntitlements.includes(opt)" @update:checked="toggleEntitlement(opt)" />
                        {{ opt }}
                    </label>
                </div>
                <button type="button" class="mt-4 rounded-lg bg-primary px-4 py-2 text-sm text-primary-foreground" @click="submitPermissions">Save entitlements</button>
            </div>
        </div>
    </div>
</template>
