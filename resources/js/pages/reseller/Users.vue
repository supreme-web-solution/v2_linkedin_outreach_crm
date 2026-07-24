<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Loader2, Pencil, Plus, Search, Trash2, UserPlus, Users } from '@lucide/vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { computed, ref } from 'vue';

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

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const searchEmail = ref(props.filters.email);
const showUserModal = ref(false);
const editingUser = ref<ManagedUser | null>(null);

const userForm = useForm({
    name: '',
    email: '',
    linkedin_public_id: '',
    password: '',
});

const hasUsers = computed(() => props.users.total > 0);
const isEditing = computed(() => editingUser.value !== null);

function runSearch() {
    router.get('/reseller/users', { email: searchEmail.value || undefined }, { preserveState: true, replace: true });
}

function openCreate() {
    editingUser.value = null;
    userForm.reset();
    userForm.clearErrors();
    showUserModal.value = true;
}

function openEdit(user: ManagedUser) {
    editingUser.value = user;
    userForm.name = user.name;
    userForm.email = user.email;
    userForm.linkedin_public_id = user.linkedin_public_id ?? '';
    userForm.password = '';
    userForm.clearErrors();
    showUserModal.value = true;
}

function closeUserModal() {
    showUserModal.value = false;
    editingUser.value = null;
    userForm.reset();
    userForm.clearErrors();
}

function submitUser() {
    if (isEditing.value && editingUser.value) {
        userForm.put(`/reseller/users/${editingUser.value.id}`, {
            preserveScroll: true,
            onSuccess: closeUserModal,
        });
        return;
    }

    userForm.post('/reseller/users', {
        preserveScroll: true,
        onSuccess: closeUserModal,
    });
}

function destroyUser(user: ManagedUser) {
    if (!confirm(`Delete ${user.email}?`)) return;
    router.delete(`/reseller/users/${user.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Reseller Users" />

    <div class="flex flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Reseller Users</h1>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Create and manage sub-users with front-end (FE) access under your reseller account.
                </p>
            </div>
            <AppToolbarButton @click="openCreate">
                <Plus class="h-4 w-4" /> New sub-user
            </AppToolbarButton>
        </div>

        <p v-if="flashSuccess" class="rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-800 dark:border-green-900/40 dark:bg-green-950/30 dark:text-green-300">
            {{ flashSuccess }}
        </p>
        <p v-if="flashError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-300">
            {{ flashError }}
        </p>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium uppercase text-muted-foreground">Sub-users</p>
                <p class="mt-1 text-2xl font-semibold">{{ users.total }}</p>
                <p class="text-xs text-muted-foreground">Managed under your account</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium uppercase text-muted-foreground">This page</p>
                <p class="mt-1 text-2xl font-semibold">{{ users.data.length }}</p>
                <p class="text-xs text-muted-foreground">Page {{ users.current_page }} of {{ users.last_page }}</p>
            </div>
        </div>

        <form class="flex max-w-md items-center gap-2" @submit.prevent="runSearch">
            <div class="flex min-w-0 flex-1 items-center gap-2 rounded-xl border border-border bg-card px-3 py-2 shadow-sm">
                <Search class="h-4 w-4 shrink-0 text-muted-foreground" />
                <input
                    v-model="searchEmail"
                    type="search"
                    placeholder="Search by email…"
                    class="w-full bg-transparent text-sm outline-none"
                />
            </div>
            <button
                type="submit"
                class="rounded-xl border border-border bg-card px-3 py-2 text-sm font-medium shadow-sm transition-colors hover:bg-muted/50"
            >
                Search
            </button>
        </form>

        <div v-if="!hasUsers" class="flex flex-col items-center justify-center rounded-xl border border-dashed border-border bg-card px-6 py-14 text-center shadow-sm">
            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                <Users class="h-7 w-7" />
            </div>
            <h2 class="text-base font-semibold">No sub-users yet</h2>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Create your first sub-user to give them FE access to the platform.
            </p>
            <AppToolbarButton class="mt-5" @click="openCreate">
                <UserPlus class="h-4 w-4" /> Create sub-user
            </AppToolbarButton>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Email</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Created</th>
                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="user in users.data" :key="user.id" class="transition-colors hover:bg-muted/20">
                        <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ user.email }}</td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ user.created_at?.slice(0, 10) ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted"
                                    @click="openEdit(user)"
                                >
                                    <Pencil class="h-3 w-3" /> Edit
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 transition-colors hover:bg-red-50 dark:border-red-900/40 dark:hover:bg-red-950/30"
                                    @click="destroyUser(user)"
                                >
                                    <Trash2 class="h-3 w-3" /> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground">
                <span>{{ users.total }} sub-users total</span>
                <div class="flex gap-2">
                    <Link
                        v-if="users.prev_page_url"
                        :href="users.prev_page_url"
                        class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium transition-colors hover:bg-muted"
                    >
                        Previous
                    </Link>
                    <Link
                        v-if="users.next_page_url"
                        :href="users.next_page_url"
                        class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium transition-colors hover:bg-muted"
                    >
                        Next
                    </Link>
                </div>
            </div>
        </div>
    </div>

    <!-- Create / edit sub-user modal -->
    <Dialog v-model:open="showUserModal">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ isEditing ? 'Edit sub-user' : 'Create sub-user' }}</DialogTitle>
                <DialogDescription>
                    {{ isEditing ? 'Update sub-user details. Leave password blank to keep the current one.' : 'Add a new sub-user with FE access under your reseller account.' }}
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submitUser">
                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">Full name</span>
                    <input
                        v-model="userForm.name"
                        required
                        placeholder="Jane Doe"
                        class="rounded-xl border border-border bg-background px-3 py-2.5 text-sm"
                        :class="{ 'border-red-300': userForm.errors.name }"
                    />
                    <span v-if="userForm.errors.name" class="text-xs text-red-600">{{ userForm.errors.name }}</span>
                </label>

                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">Email</span>
                    <input
                        v-model="userForm.email"
                        type="email"
                        required
                        placeholder="user@example.com"
                        class="rounded-xl border border-border bg-background px-3 py-2.5 text-sm"
                        :class="{ 'border-red-300': userForm.errors.email }"
                    />
                    <span v-if="userForm.errors.email" class="text-xs text-red-600">{{ userForm.errors.email }}</span>
                </label>

                <label v-if="isEditing" class="grid gap-1.5 text-sm">
                    <span class="font-medium">LinkedIn public ID</span>
                    <input
                        v-model="userForm.linkedin_public_id"
                        placeholder="Optional"
                        class="rounded-xl border border-border bg-background px-3 py-2.5 text-sm"
                    />
                </label>

                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">{{ isEditing ? 'New password' : 'Password' }}</span>
                    <PasswordInput
                        v-model="userForm.password"
                        :required="!isEditing"
                        :placeholder="isEditing ? 'Leave blank to keep current password' : 'Minimum 8 characters'"
                        autocomplete="new-password"
                        class="h-10 rounded-xl py-2.5 text-sm"
                        :class="{ 'border-red-300': userForm.errors.password }"
                    />
                    <span v-if="userForm.errors.password" class="text-xs text-red-600">{{ userForm.errors.password }}</span>
                </label>

                <DialogFooter class="gap-2 pt-1 sm:gap-0">
                    <button
                        type="button"
                        class="rounded-xl border border-border px-4 py-2 text-sm font-medium transition-colors hover:bg-muted/50"
                        @click="closeUserModal"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-60"
                        :disabled="userForm.processing"
                    >
                        <Loader2 v-if="userForm.processing" class="h-4 w-4 animate-spin" />
                        {{ isEditing ? 'Save changes' : 'Create sub-user' }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
