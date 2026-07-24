<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Loader2, LogIn, Pencil, Plus, Search, Shield, Trash2, UserPlus, Users } from '@lucide/vue';
import AppToolbarButton from '@/components/crm/AppToolbarButton.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Checkbox } from '@/components/ui/checkbox';
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

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const searchEmail = ref(props.filters.email);
const showUserModal = ref(false);
const showPermissionsModal = ref(false);
const editingUser = ref<ManagedUser | null>(null);
const permUser = ref<ManagedUser | null>(null);
const selectedEntitlements = ref<string[]>([]);

const userForm = useForm({
    name: '',
    email: '',
    linkedin_public_id: '',
    password: '',
});
const permForm = useForm({ user_id: 0, entitlements: [] as string[] });

const entitlementLabels = computed(() => props.entitlementOptions.filter((e) => !e.startsWith('view_')));
const hasUsers = computed(() => props.users.total > 0);
const isEditing = computed(() => editingUser.value !== null);

function runSearch() {
    router.get('/admin/users', { email: searchEmail.value || undefined }, { preserveState: true, replace: true });
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
        userForm.put(`/admin/users/${editingUser.value.id}`, {
            preserveScroll: true,
            onSuccess: closeUserModal,
        });
        return;
    }

    userForm.post('/admin/users', {
        preserveScroll: true,
        onSuccess: closeUserModal,
    });
}

async function openPermissions(user: ManagedUser) {
    permUser.value = user;
    const res = await fetch(`/admin/users/${user.id}/permissions`);
    const data = await res.json();
    selectedEntitlements.value = data.assigned ?? user.entitlements;
    showPermissionsModal.value = true;
}

function closePermissionsModal() {
    showPermissionsModal.value = false;
    permUser.value = null;
}

function submitPermissions() {
    if (!permUser.value) return;
    permForm.user_id = permUser.value.id;
    permForm.entitlements = [...selectedEntitlements.value];
    permForm.put('/admin/users/entitlements', {
        preserveScroll: true,
        onSuccess: closePermissionsModal,
    });
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

    <div class="flex flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Manage Users</h1>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Platform-wide user administration, license assignment, and impersonation.
                </p>
            </div>
            <AppToolbarButton @click="openCreate">
                <Plus class="h-4 w-4" /> New user
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
                <p class="text-xs font-medium uppercase text-muted-foreground">Total users</p>
                <p class="mt-1 text-2xl font-semibold">{{ users.total }}</p>
                <p class="text-xs text-muted-foreground">Registered on this platform</p>
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
            <h2 class="text-base font-semibold">No users yet</h2>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Create the first platform user to get started.
            </p>
            <AppToolbarButton class="mt-5" @click="openCreate">
                <UserPlus class="h-4 w-4" /> Create user
            </AppToolbarButton>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Email</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Entitlements</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Created</th>
                        <th class="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="user in users.data" :key="user.id" class="transition-colors hover:bg-muted/20">
                        <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ user.email }}</td>
                        <td class="px-4 py-3">
                            <span
                                v-for="e in user.entitlements"
                                :key="e"
                                class="mr-1 inline-block rounded-full border border-primary/20 bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                            >
                                {{ e }}
                            </span>
                            <span v-if="user.entitlements.length === 0" class="text-xs text-muted-foreground">—</span>
                        </td>
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
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted"
                                    @click="openPermissions(user)"
                                >
                                    <Shield class="h-3 w-3" /> Licenses
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-violet-200 px-2.5 py-1.5 text-xs font-medium text-violet-600 transition-colors hover:bg-violet-50 dark:border-violet-900/40 dark:hover:bg-violet-950/30"
                                    @click="impersonate(user)"
                                >
                                    <LogIn class="h-3 w-3" /> Impersonate
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
                <span>{{ users.total }} users total</span>
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

    <!-- Create / edit user modal -->
    <Dialog v-model:open="showUserModal">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ isEditing ? 'Edit user' : 'Create new user' }}</DialogTitle>
                <DialogDescription>
                    {{ isEditing ? 'Update account details. Leave password blank to keep the current one.' : 'Add a new platform user with name, email, and initial password.' }}
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
                        {{ isEditing ? 'Save changes' : 'Create user' }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Entitlements modal -->
    <Dialog v-model:open="showPermissionsModal">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>License entitlements</DialogTitle>
                <DialogDescription v-if="permUser">
                    Choose which product licenses apply to <span class="font-medium text-foreground">{{ permUser.email }}</span>.
                </DialogDescription>
            </DialogHeader>

            <div class="grid max-h-[50vh] grid-cols-2 gap-2 overflow-y-auto py-1">
                <label
                    v-for="opt in entitlementLabels"
                    :key="opt"
                    class="flex cursor-pointer items-center gap-2 rounded-xl border border-border px-3 py-2.5 text-sm transition-colors hover:bg-muted/40"
                >
                    <Checkbox :checked="selectedEntitlements.includes(opt)" @update:checked="toggleEntitlement(opt)" />
                    {{ opt }}
                </label>
            </div>

            <DialogFooter class="gap-2 pt-1 sm:gap-0">
                <button
                    type="button"
                    class="rounded-xl border border-border px-4 py-2 text-sm font-medium transition-colors hover:bg-muted/50"
                    @click="closePermissionsModal"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 disabled:opacity-60"
                    :disabled="permForm.processing"
                    @click="submitPermissions"
                >
                    <Loader2 v-if="permForm.processing" class="h-4 w-4 animate-spin" />
                    Save entitlements
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
