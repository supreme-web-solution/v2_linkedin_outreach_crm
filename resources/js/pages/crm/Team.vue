<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Mail, RefreshCw, Shield, Trash2, UserPlus, Users2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ClientPagination from '@/components/crm/ClientPagination.vue';
import ListSearchBar from '@/components/crm/ListSearchBar.vue';
import { useClientList } from '@/composables/useClientList';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Team', href: '/team' }] },
});

interface Member {
    id: number;
    role: string;
    status: string;
    capabilities: string[];
    template: string;
    is_self: boolean;
    user: { id: number; name: string; email: string } | null;
}

interface Invite {
    id: number;
    invitee_email: string;
    role: string;
    status: string;
    template: string;
    expires_at: string | null;
    created_at: string;
    accept_url: string;
}

interface Template {
    key: string;
    label: string;
    role: string;
    capabilities: string[];
}

const props = defineProps<{
    organization: { id: number; name: string } | null;
    members: Member[];
    invites: Invite[];
    myOrgs: Array<{ id: number; name: string | null; role: string; is_current: boolean }>;
    templates: Template[];
    canManage: boolean;
    currentMembership: { role: string; template: string } | null;
    stats: { total_members: number; pending_invites: number; admins: number };
    hasOrg: boolean;
}>();

const showInvite = ref(false);
const editingMember = ref<number | null>(null);
const teamSearch = ref('');

const {
    search: memberSearch,
    page: memberPage,
    paginated: paginatedMembers,
    totalPages: memberTotalPages,
    total: memberTotal,
} = useClientList(computed(() => props.members), {
    perPage: 10,
    searchKeys: (m) => [m.user?.name ?? '', m.user?.email ?? '', m.role, m.template],
});

const {
    search: inviteSearch,
    page: invitePage,
    paginated: paginatedInvites,
    totalPages: inviteTotalPages,
    total: inviteTotal,
} = useClientList(computed(() => props.invites), {
    perPage: 10,
    searchKeys: (i) => [i.invitee_email, i.role, i.status, i.template],
});

watch(teamSearch, (value) => {
    memberSearch.value = value;
    inviteSearch.value = value;
});

const inviteForm = useForm({
    email: '',
    template: 'operator',
    expires_in_days: 7,
});

const roleColor = (r: string) => {
    if (r === 'owner') return 'bg-purple-500/10 text-purple-600';
    if (r === 'admin') return 'bg-blue-500/10 text-blue-600';
    return 'bg-muted text-muted-foreground';
};

const inviteStatusColor = (s: string) => {
    if (s === 'accepted') return 'text-green-600';
    if (s === 'revoked' || s === 'expired') return 'text-red-500';
    return 'text-amber-600';
};

function templateLabel(key: string) {
    return props.templates.find((t) => t.key === key)?.label ?? key;
}

function sendInvite() {
    inviteForm.post('/team/invite', {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset('email');
            showInvite.value = false;
        },
    });
}

function updateMemberTemplate(memberId: number, template: string) {
    router.put(`/team/members/${memberId}`, { template }, { preserveScroll: true, onSuccess: () => { editingMember.value = null; } });
}

function removeMember(member: Member) {
    if (member.is_self) return;
    if (!confirm(`Remove ${member.user?.name ?? 'this member'} from the team?`)) return;
    router.delete(`/team/members/${member.id}`, { preserveScroll: true });
}

function revokeInvite(invite: Invite) {
    if (!confirm(`Revoke invitation for ${invite.invitee_email}?`)) return;
    router.delete(`/team/invites/${invite.id}`, { preserveScroll: true });
}

function resendInvite(invite: Invite) {
    router.post(`/team/invites/${invite.id}/resend`, {}, { preserveScroll: true });
}

function switchOrg(orgId: number) {
    router.post(`/team/switch/${orgId}`);
}
</script>

<template>
    <Head title="Team" />

    <div class="flex flex-col gap-5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Team</h1>
                <p class="text-sm text-muted-foreground">
                    <span v-if="organization">{{ organization.name }} · </span>
                    Manage members, roles, and invitations.
                </p>
            </div>
            <button
                v-if="canManage && hasOrg"
                type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                @click="showInvite = !showInvite"
            >
                <UserPlus class="h-4 w-4" />
                Invite member
            </button>
        </div>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400">
            Connect your workspace via the extension to manage your team.
        </div>

        <!-- Org switcher -->
        <div v-if="myOrgs.length > 1" class="rounded-xl border border-border bg-card p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold">Your workspaces</h2>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="org in myOrgs"
                    :key="org.id"
                    type="button"
                    class="rounded-full border px-3 py-1 text-xs font-medium transition"
                    :class="org.is_current ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted/50'"
                    :disabled="org.is_current"
                    @click="switchOrg(org.id)"
                >
                    {{ org.name ?? 'Workspace' }}
                    <span class="ml-1 text-muted-foreground">({{ org.role }})</span>
                </button>
            </div>
        </div>

        <!-- Stats -->
        <div v-if="hasOrg" class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground"><Users2 class="h-4 w-4" /><span class="text-xs font-medium uppercase">Members</span></div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.total_members }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground"><Mail class="h-4 w-4" /><span class="text-xs font-medium uppercase">Pending invites</span></div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.pending_invites }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <div class="flex items-center gap-2 text-muted-foreground"><Shield class="h-4 w-4" /><span class="text-xs font-medium uppercase">Admins</span></div>
                <p class="mt-1 text-2xl font-semibold">{{ stats.admins }}</p>
            </div>
        </div>

        <!-- Invite form -->
        <div v-if="showInvite && canManage" class="rounded-xl border border-primary/30 bg-primary/5 p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold">Invite a team member</h2>
            <form class="grid gap-3 md:grid-cols-3" @submit.prevent="sendInvite">
                <label class="grid gap-1 text-sm md:col-span-1">
                    <span class="font-medium">Email</span>
                    <input v-model="inviteForm.email" type="email" required class="rounded-lg border border-border bg-background px-3 py-2" placeholder="colleague@company.com" />
                </label>
                <label class="grid gap-1 text-sm md:col-span-1">
                    <span class="font-medium">Role template</span>
                    <select v-model="inviteForm.template" class="rounded-lg border border-border bg-background px-3 py-2">
                        <option v-for="t in templates.filter((x) => x.key !== 'owner')" :key="t.key" :value="t.key">{{ t.label }}</option>
                    </select>
                </label>
                <div class="flex items-end gap-2 md:col-span-1">
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground" :disabled="inviteForm.processing">Send invite</button>
                    <button type="button" class="rounded-lg border border-border px-4 py-2 text-sm" @click="showInvite = false">Cancel</button>
                </div>
            </form>
            <p class="mt-2 text-xs text-muted-foreground">If they already have an account, they are added immediately. Otherwise an email invite is sent.</p>
        </div>

        <!-- Role templates reference -->
        <div v-if="hasOrg && canManage" class="rounded-xl border border-border bg-card p-4 shadow-sm">
            <h2 class="mb-2 text-sm font-semibold">Role templates</h2>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="t in templates.filter((x) => x.key !== 'owner')" :key="t.key" class="rounded-lg border border-border/60 p-3 text-xs">
                    <p class="font-semibold">{{ t.label }}</p>
                    <p class="mt-1 text-muted-foreground">{{ t.capabilities.length }} capabilities</p>
                </div>
            </div>
        </div>

        <!-- Members -->
        <div v-if="hasOrg" class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div class="border-b border-border px-4 py-3 flex flex-col gap-3">
                <h2 class="text-sm font-semibold">Members</h2>
                <ListSearchBar v-model="teamSearch" placeholder="Search members and invites…" />
            </div>
            <div v-if="memberTotal === 0" class="p-8 text-center text-sm text-muted-foreground">
                {{ members.length === 0 ? 'No members yet — invite someone to get started.' : 'No members match your search.' }}
            </div>
            <table v-else class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Email</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Role</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Template</th>
                        <th v-if="canManage" class="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="m in paginatedMembers" :key="m.id" class="hover:bg-muted/20">
                        <td class="px-4 py-3 font-medium">
                            {{ m.user?.name ?? '—' }}
                            <span v-if="m.is_self" class="ml-1 text-xs text-muted-foreground">(you)</span>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">{{ m.user?.email ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="roleColor(m.role)">{{ m.role }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <template v-if="canManage && !m.is_self && m.role !== 'owner'">
                                <select
                                    v-if="editingMember === m.id"
                                    class="rounded border border-border bg-background px-2 py-1 text-xs"
                                    :value="m.template"
                                    @change="updateMemberTemplate(m.id, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option v-for="t in templates.filter((x) => x.key !== 'owner')" :key="t.key" :value="t.key">{{ t.label }}</option>
                                </select>
                                <button v-else type="button" class="text-xs text-primary hover:underline" @click="editingMember = m.id">{{ templateLabel(m.template) }}</button>
                            </template>
                            <span v-else class="text-xs text-muted-foreground">{{ templateLabel(m.template) }}</span>
                        </td>
                        <td v-if="canManage" class="px-4 py-3 text-right">
                            <button
                                v-if="!m.is_self && m.role !== 'owner'"
                                type="button"
                                class="inline-flex items-center gap-1 text-xs text-red-600 hover:underline"
                                @click="removeMember(m)"
                            >
                                <Trash2 class="h-3 w-3" /> Remove
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <ClientPagination v-model:page="memberPage" :total-pages="memberTotalPages" :total="memberTotal" :per-page="10" label="members" />
        </div>

        <!-- Invites -->
        <div v-if="hasOrg && (invites.length || teamSearch)" class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div class="border-b border-border px-4 py-3">
                <h2 class="text-sm font-semibold">Invitations</h2>
            </div>
            <div v-if="inviteTotal === 0" class="p-8 text-center text-sm text-muted-foreground">No invitations match your search.</div>
            <table v-else class="w-full text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Email</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Template</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Expires</th>
                        <th v-if="canManage" class="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="invite in paginatedInvites" :key="invite.id" class="hover:bg-muted/20">
                        <td class="px-4 py-3">{{ invite.invitee_email }}</td>
                        <td class="px-4 py-3 text-xs">{{ templateLabel(invite.template) }}</td>
                        <td class="px-4 py-3 text-xs font-medium capitalize" :class="inviteStatusColor(invite.status)">{{ invite.status }}</td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ invite.expires_at?.slice(0, 10) ?? '—' }}</td>
                        <td v-if="canManage" class="px-4 py-3 text-right">
                            <div v-if="invite.status === 'pending'" class="flex justify-end gap-2">
                                <button type="button" class="inline-flex items-center gap-1 text-xs text-primary hover:underline" @click="resendInvite(invite)">
                                    <RefreshCw class="h-3 w-3" /> Resend
                                </button>
                                <button type="button" class="text-xs text-red-600 hover:underline" @click="revokeInvite(invite)">Revoke</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <ClientPagination v-model:page="invitePage" :total-pages="inviteTotalPages" :total="inviteTotal" :per-page="10" label="invites" />
        </div>
    </div>
</template>
