<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Profile settings', href: edit() }],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

defineProps<{
    mustVerifyEmail: boolean;
    status?: string;
    timezones: string[];
    profile: { timezone: string | null; linkedin_public_id: string | null };
    workspace: { id: number; name: string | null; role: string } | null;
}>();
</script>

<template>
    <Head title="Profile settings" />

    <div class="flex flex-col space-y-8">
        <Heading variant="small" title="Profile" description="Your personal information and workspace" />

        <div v-if="workspace" class="rounded-xl border border-border bg-muted/30 p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Current workspace</p>
            <p class="mt-1 font-semibold">{{ workspace.name ?? 'Workspace' }}</p>
            <p class="text-sm capitalize text-muted-foreground">Role: {{ workspace.role }}</p>
            <Link href="/team" class="mt-2 inline-block text-sm text-primary hover:underline">Manage team →</Link>
        </div>

        <Form v-bind="ProfileController.update.form()" class="space-y-6" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" :default-value="user.name" required autocomplete="name" placeholder="Full name" />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input id="email" type="email" name="email" :default-value="user.email" required autocomplete="username" placeholder="Email address" />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="linkedin_public_id">LinkedIn public ID</Label>
                <Input
                    id="linkedin_public_id"
                    name="linkedin_public_id"
                    :default-value="profile.linkedin_public_id ?? ''"
                    placeholder="e.g. john-doe-123456"
                />
                <p class="text-xs text-muted-foreground">From your profile URL: linkedin.com/in/<strong>your-id</strong></p>
                <InputError :message="errors.linkedin_public_id" />
            </div>

            <div class="grid gap-2">
                <Label for="timezone">Timezone</Label>
                <select
                    id="timezone"
                    name="timezone"
                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                    <option value="">Select timezone</option>
                    <option v-for="tz in timezones" :key="tz" :value="tz" :selected="tz === (profile.timezone ?? '')">{{ tz }}</option>
                </select>
                <InputError :message="errors.timezone" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link :href="send()" as="button" class="text-foreground underline">Re-send verification email</Link>
                </p>
                <div v-if="page.props.status === 'verification-link-sent'" class="mt-2 text-sm font-medium text-green-600">
                    A new verification link has been sent.
                </div>
            </div>

            <Button type="submit" :disabled="processing">Save</Button>
        </Form>

        <DeleteUser />
    </div>
</template>
