<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink, PlayCircle } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tutorials', href: '/tutorials' }],
    },
});

interface Tutorial {
    link: string;
    label: string;
    featured?: boolean;
}

const tutorials: Tutorial[] = [
    {
        link: 'https://vimeo.com/1165389320',
        label: 'IMPORTANT: Watch This First to Get Started the Right Way',
        featured: true,
    },
    { link: 'https://vimeo.com/1165390082', label: 'Competitor Active Followers' },
    { link: 'https://vimeo.com/1165390293', label: 'AI Content Creation' },
    { link: 'https://vimeo.com/1165391485', label: 'Campaign' },
    { link: 'https://vimeo.com/1165390834', label: 'Leads & AI Messages' },
    {
        link: 'https://mega.nz/file/kXcSTCzL#Kdn7OU8pRq0er_Bm1KOYj2TzeH1uE3eejrcl0p_q-Vs',
        label: 'Extension Features',
    },
];
</script>

<template>
    <Head title="Tutorials" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold text-foreground">Tutorials</h1>
            <p class="text-sm text-muted-foreground">
                Step-by-step walkthroughs to get the most out of Socifusion.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <a
                v-for="tutorial in tutorials"
                :key="tutorial.link"
                :href="tutorial.link"
                target="_blank"
                rel="noopener noreferrer"
                class="group flex flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm transition hover:border-primary/50 hover:shadow-md"
                :class="{ 'sm:col-span-2 lg:col-span-3': tutorial.featured }"
            >
                <div
                    class="relative flex aspect-video items-center justify-center bg-gradient-to-br from-primary/15 via-primary/5 to-transparent"
                >
                    <PlayCircle
                        class="h-14 w-14 text-primary/70 transition group-hover:scale-110 group-hover:text-primary"
                    />
                    <span
                        v-if="tutorial.featured"
                        class="absolute left-3 top-3 rounded-full bg-primary px-2.5 py-1 text-xs font-semibold text-primary-foreground"
                    >
                        Start here
                    </span>
                </div>
                <div class="flex items-center justify-between gap-2 px-4 py-3">
                    <p class="text-sm font-medium text-foreground">{{ tutorial.label }}</p>
                    <ExternalLink class="h-4 w-4 shrink-0 text-muted-foreground" />
                </div>
            </a>
        </div>
    </div>
</template>
