<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarCheck, Eye, MessageCircle, UserCheck } from '@lucide/vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const activityFeed = [
    {
        avatar: '/images/avatars/avatar-1.png',
        name: 'Amara K.',
        action: 'accepted your connection request',
        icon: UserCheck,
        badge: 'bg-emerald-500',
        position: 'top-6 left-0',
        rotate: '-rotate-2',
    },
    {
        avatar: '/images/avatars/avatar-4.png',
        name: 'Daniel W.',
        action: 'replied on WhatsApp',
        icon: MessageCircle,
        badge: 'bg-blue-500',
        position: 'top-1/2 right-0 -translate-y-[85%]',
        rotate: 'rotate-2',
    },
    {
        avatar: '/images/avatars/avatar-3.png',
        name: 'Sarah B.',
        action: 'opened your outreach message',
        icon: Eye,
        badge: 'bg-violet-500',
        position: 'bottom-24 left-0',
        rotate: 'rotate-2',
    },
    {
        avatar: '/images/avatars/avatar-2.png',
        name: 'Marcus J.',
        action: 'booked a call with you',
        icon: CalendarCheck,
        badge: 'bg-amber-500',
        position: 'bottom-6 right-0',
        rotate: '-rotate-2',
    },
];
</script>

<template>
    <div class="relative flex min-h-svh flex-col items-center justify-center bg-slate-50 p-6 md:p-10 dark:bg-slate-950">
        <!-- Decorative gradient blobs -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div
                class="absolute -top-40 -left-32 h-96 w-96 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 opacity-25 blur-3xl dark:opacity-20"
            />
            <div
                class="absolute top-1/2 -right-32 h-[26rem] w-[26rem] -translate-y-1/2 rounded-full bg-gradient-to-tr from-sky-400 to-blue-600 opacity-20 blur-3xl dark:opacity-15"
            />
            <div
                class="absolute -bottom-40 left-1/4 h-80 w-80 rounded-full bg-gradient-to-br from-indigo-400 to-blue-500 opacity-15 blur-3xl dark:opacity-10"
            />
            <div
                class="absolute inset-0 bg-[linear-gradient(to_right,#8080800a_1px,transparent_1px),linear-gradient(to_bottom,#8080800a_1px,transparent_1px)] bg-[size:32px_32px]"
            />
        </div>

        <div class="relative z-10 flex flex-col items-center gap-8">
            <Link
                :href="home()"
                class="flex flex-col items-center gap-3 font-medium"
            >
                <div class="h-14 w-14 overflow-hidden rounded-2xl shadow-lg shadow-blue-600/25 ring-1 ring-inset ring-white/25">
                    <img src="/images/brand/app-logo.png" alt="" class="h-full w-full object-cover" />
                </div>
                <span class="sr-only">{{ title }}</span>
            </Link>

            <!-- Wide "stage": chips live in the side gutters here so they never sit under the card -->
            <div class="relative w-full max-w-sm xl:w-[58rem] xl:max-w-none">
                <div
                    v-for="event in activityFeed"
                    :key="event.name"
                    :class="['pointer-events-none absolute z-0 hidden w-64 xl:block', event.position]"
                >
                    <div
                        :class="[
                            'relative flex items-center gap-3 rounded-2xl border border-black/5 bg-white p-3.5 shadow-xl shadow-slate-900/[0.08] transition-transform hover:-translate-y-0.5 hover:rotate-0 dark:border-white/10 dark:bg-slate-800',
                            event.rotate,
                        ]"
                    >
                        <div class="relative shrink-0">
                            <img
                                :src="event.avatar"
                                alt=""
                                class="size-11 rounded-full object-cover ring-2 ring-white dark:ring-slate-700"
                            />
                            <span
                                :class="[
                                    'absolute -right-1 -bottom-1 flex size-5 items-center justify-center rounded-full text-white ring-2 ring-white dark:ring-slate-800',
                                    event.badge,
                                ]"
                            >
                                <component :is="event.icon" class="size-3" stroke-width="2.5" />
                            </span>
                        </div>
                        <p class="text-[12.5px] leading-snug text-slate-500 dark:text-slate-400">
                            <span class="font-semibold text-slate-900 dark:text-white">{{ event.name }}</span>
                            {{ ' ' }}{{ event.action }}
                        </p>
                    </div>
                </div>

                <div
                    class="relative z-10 mx-auto w-full max-w-sm rounded-2xl border border-black/5 bg-white/90 p-8 shadow-xl shadow-slate-900/[0.06] backdrop-blur-xl dark:border-white/10 dark:bg-slate-900/80 dark:shadow-black/20"
                >
                    <div class="mb-6 space-y-1.5 text-center">
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">
                            {{ title }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
