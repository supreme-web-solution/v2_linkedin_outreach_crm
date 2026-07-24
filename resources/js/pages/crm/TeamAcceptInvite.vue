<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, LogIn, Users2 } from '@lucide/vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Team invitation' }] },
});

const props = defineProps<{
    invite: {
        email: string;
        role: string;
        organization_name: string | null;
        expires_at: string | null;
        token: string;
    };
    isLoggedIn: boolean;
    emailMatches: boolean;
}>();

function accept() {
    router.post(`/team/accept/${props.invite.token}`);
}
</script>

<template>
    <Head title="Accept team invitation" />

    <div class="flex min-h-[60vh] items-center justify-center p-4">
        <div class="w-full max-w-md rounded-xl border border-border bg-card p-8 shadow-sm">
            <div class="mb-4 flex justify-center">
                <Users2 class="h-10 w-10 text-primary" />
            </div>
            <h1 class="text-center text-xl font-semibold">Join {{ invite.organization_name ?? 'the team' }}</h1>
            <p class="mt-2 text-center text-sm text-muted-foreground">
                You've been invited as <strong>{{ invite.role }}</strong>.
                <br />
                Invitation for <strong>{{ invite.email }}</strong>
            </p>
            <p v-if="invite.expires_at" class="mt-1 text-center text-xs text-muted-foreground">Expires {{ invite.expires_at.slice(0, 10) }}</p>

            <div v-if="!isLoggedIn" class="mt-6 space-y-3 text-center">
                <p class="text-sm">Sign in or create an account with <strong>{{ invite.email }}</strong> to accept.</p>
                <Link href="/login" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">
                    <LogIn class="h-4 w-4" /> Sign in
                </Link>
                <p class="text-xs text-muted-foreground">
                    No account?
                    <Link href="/register" class="text-primary underline">Register</Link>
                </p>
            </div>

            <div v-else-if="!emailMatches" class="mt-6 rounded-lg border border-yellow-500/30 bg-yellow-500/10 p-3 text-sm text-yellow-800">
                You're signed in with a different email. Sign out and sign in as <strong>{{ invite.email }}</strong> to accept.
            </div>

            <div v-else class="mt-6 text-center">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 text-sm font-medium text-primary-foreground"
                    @click="accept"
                >
                    <CheckCircle2 class="h-4 w-4" /> Accept invitation
                </button>
            </div>
        </div>
    </div>
</template>
