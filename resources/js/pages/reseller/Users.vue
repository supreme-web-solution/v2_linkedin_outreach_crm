<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2, X } from '@lucide/vue';
import { ref } from 'vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Reseller', href: '/reseller/users' }] },
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
    filters: { email: string };
}>();

const searchEmail = ref(props.filters.email);
const showCreate = ref(false);
const editing = ref<ManagedUser | null>(null);

const createForm = useForm({ name: '', email: '', password: '' });
const editForm = useForm({ name: '', email: '', linkedin_public_id: '', password: '' });

function runSearch() {
    router.get('/reseller/users', { email: searchEmail.value || undefined }, { preserveState: true, replace: true });
}

function openEdit(user: ManagedUser) {
    editing.value = user;
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.linkedin_public_id = user.linkedin_public_id ?? '';
    editForm.password = '';
}

function submitCreate() {
    createForm.post('/reseller/users', { preserveScroll: true, onSuccess: () => { showCreate.value = false; createForm.reset(); } });
}

function submitEdit() {
    if (!editing.value) return;
    editForm.put(`/reseller/users/${editing.value.id}`, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
}

function destroyUser(user: ManagedUser) {
    if (!confirm(`Delete ${user.email}?`)) return;
    router.delete(`/reseller/users/${user.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Reseller Users" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Reseller Users</h1>
                <p class="text-sm text-muted-foreground">Create and manage sub-users (FE access).</p>
            </div>
            <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground" @click="showCreate = true">
                <Plus class="h-4 w-4" /> New sub-user
            </button>
        </div>

        <form class="flex max-w-md gap-2" @submit.prevent="runSearch">
            <input v-model="searchEmail" type="search" placeholder="Search email…" class="flex-1 rounded-lg border border-border bg-background px-3 py-2 text-sm" />
            <button type="submit" class="rounded-lg border border-border px-3 py-2 text-sm hover:bg-muted"><Search class="h-4 w-4" /></button>
        </form>

        <div v-if="showCreate" class="rounded-xl border border-primary/30 bg-primary/5 p-4">
            <h2 class="mb-3 text-sm font-semibold">New sub-user</h2>
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
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Created</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="user in users.data" :key="user.id" class="hover:bg-muted/20">
                        <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-3">{{ user.email }}</td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ user.created_at?.slice(0, 10) ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <button type="button" class="text-primary" @click="openEdit(user)"><Pencil class="h-4 w-4" /></button>
                                <button type="button" class="text-red-600" @click="destroyUser(user)"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="flex items-center justify-between border-t border-border px-4 py-3 text-sm text-muted-foreground">
                <span>{{ users.total }} sub-users</span>
                <div class="flex gap-2">
                    <Link v-if="users.prev_page_url" :href="users.prev_page_url" class="rounded border border-border px-3 py-1 hover:bg-muted">Prev</Link>
                    <Link v-if="users.next_page_url" :href="users.next_page_url" class="rounded border border-border px-3 py-1 hover:bg-muted">Next</Link>
                </div>
            </div>
        </div>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-5 shadow-lg">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="font-semibold">Edit sub-user</h2>
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
    </div>
</template>
