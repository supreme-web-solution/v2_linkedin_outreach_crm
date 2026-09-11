<script setup lang="ts">
import AlexAvatar from '@/components/crm/AlexAvatar.vue';
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    name?: string;
    showLabel?: boolean;
    statusLabel?: string;
}>(), {
    name: 'Soci',
    showLabel: true,
    statusLabel: 'Thinking…',
});

const neutralLabel = 'Thinking…';

const headline = computed(() => props.statusLabel.trim() || neutralLabel);
</script>

<template>
    <div class="flex justify-start">
        <div class="max-w-[92%] space-y-1.5">
            <div
                v-if="showLabel"
                class="text-muted-foreground px-1 text-[10px] uppercase tracking-wide"
            >
                {{ name }} · {{ headline }}
            </div>
            <div
                class="rounded-2xl border border-border/60 bg-muted/50 px-3 py-3 shadow-sm dark:bg-muted/30"
            >
                <div class="mb-3 flex items-center gap-2.5">
                    <AlexAvatar size="xs" :alt="name" />
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-[78%] animate-pulse rounded-full bg-foreground/10" />
                        <div class="h-2.5 w-[56%] animate-pulse rounded-full bg-foreground/10 [animation-delay:120ms]" />
                        <div class="h-2.5 w-[68%] animate-pulse rounded-full bg-foreground/10 [animation-delay:240ms]" />
                    </div>
                </div>
                <div class="flex items-center gap-2 border-t border-border/40 pt-2.5">
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-primary/40 opacity-75" />
                        <span class="relative inline-flex size-2 rounded-full bg-primary" />
                    </span>
                    <span class="text-muted-foreground text-xs">{{ headline }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
