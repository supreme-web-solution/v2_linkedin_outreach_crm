<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

defineProps<{
    title: string;
    description: string;
    action: string;
}>();

defineOptions({ layout: { title: 'Activate license', description: 'Set your password after purchase' } });
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto w-full max-w-md space-y-6">
        <div>
            <h1 class="text-xl font-semibold">{{ title }}</h1>
            <p class="text-sm text-muted-foreground">{{ description }}</p>
        </div>

        <Form :action="action" method="post" v-slot="{ errors, processing }" class="flex flex-col gap-4">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required autocomplete="name" />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input id="email" type="email" name="email" required autocomplete="email" />
                <InputError :message="errors.email" />
            </div>
            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput id="password" name="password" required autocomplete="new-password" />
                <InputError :message="errors.password" />
            </div>
            <Button type="submit" :disabled="processing" class="w-full">
                <Spinner v-if="processing" class="mr-2" /> Activate account
            </Button>
        </Form>

        <p class="text-center text-sm text-muted-foreground">
            Already activated?
            <TextLink :href="login()">Sign in</TextLink>
        </p>
    </div>
</template>
