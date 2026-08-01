<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';

export type BuilderStepId = 'template' | 'leads' | 'build' | 'review';

const props = defineProps<{
    current: BuilderStepId;
    includeTemplate?: boolean;
}>();

const steps = computed(() => {
    const all: Array<{ id: BuilderStepId; label: string; hint: string }> = [
        { id: 'template', label: 'Template', hint: 'Pick a starting point' },
        { id: 'leads', label: 'Leads', hint: 'Import or select lists' },
        { id: 'build', label: 'Sequence & prep', hint: 'Build steps and prepare contacts' },
        { id: 'review', label: 'Launch', hint: 'Review and go live' },
    ];

    return props.includeTemplate === false ? all.filter((s) => s.id !== 'template') : all;
});

const currentIndex = computed(() => steps.value.findIndex((s) => s.id === props.current));
</script>

<template>
    <nav aria-label="Campaign setup progress" class="rounded-xl border border-border bg-white px-4 py-3">
        <ol class="flex flex-wrap items-center gap-2 sm:gap-0">
            <li
                v-for="(step, index) in steps"
                :key="step.id"
                class="flex min-w-0 items-center gap-2 sm:flex-1"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                        :class="
                            index < currentIndex
                                ? 'bg-emerald-500 text-white'
                                : index === currentIndex
                                  ? 'bg-primary text-primary-foreground'
                                  : 'bg-muted text-muted-foreground'
                        "
                    >
                        <Check v-if="index < currentIndex" class="h-3.5 w-3.5" />
                        <span v-else>{{ index + 1 }}</span>
                    </span>
                    <div class="min-w-0 hidden sm:block">
                        <p
                            class="truncate text-xs font-semibold"
                            :class="index === currentIndex ? 'text-foreground' : 'text-muted-foreground'"
                        >
                            {{ step.label }}
                        </p>
                        <p class="truncate text-[10px] text-muted-foreground">{{ step.hint }}</p>
                    </div>
                </div>
                <div
                    v-if="index < steps.length - 1"
                    class="mx-2 hidden h-px flex-1 bg-border sm:block"
                    :class="index < currentIndex ? 'bg-emerald-300' : ''"
                />
            </li>
        </ol>
        <p class="mt-2 text-center text-[11px] text-muted-foreground sm:hidden">
            Step {{ currentIndex + 1 }} of {{ steps.length }} · {{ steps[currentIndex]?.label }}
        </p>
    </nav>
</template>
