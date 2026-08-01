<script setup lang="ts">
import type { Component } from 'vue';
import { Gift } from '@lucide/vue';

defineProps<{
    title: string;
    subtitle?: string;
    badge?: string;
    icon?: Component;
}>();
</script>

<template>
    <div class="mx-auto flex max-w-3xl flex-col gap-5 p-4 sm:p-6">
        <div class="relative overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-blue-50 via-white to-slate-50 p-5 shadow-sm sm:p-6">
            <div class="pointer-events-none absolute -right-8 -top-8 h-32 w-32 rounded-full bg-blue-500/10 blur-2xl" />
            <div class="pointer-events-none absolute -bottom-10 left-10 h-28 w-28 rounded-full bg-indigo-400/10 blur-2xl" />

            <div class="relative flex items-start gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white shadow-md shadow-blue-500/25">
                    <component :is="icon ?? Gift" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <span
                        v-if="badge"
                        class="mb-2 inline-flex rounded-full border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-blue-700"
                    >
                        {{ badge }}
                    </span>
                    <h1 class="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">{{ title }}</h1>
                    <p v-if="subtitle" class="mt-1.5 max-w-xl text-sm leading-relaxed text-muted-foreground">
                        {{ subtitle }}
                    </p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-border bg-card p-5 shadow-sm sm:p-6">
            <slot />
        </div>

        <slot name="extra" />
    </div>
</template>
