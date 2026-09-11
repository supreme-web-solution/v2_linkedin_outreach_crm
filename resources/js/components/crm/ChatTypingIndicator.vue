<script setup lang="ts">
import AlexAvatar from '@/components/crm/AlexAvatar.vue';

withDefaults(defineProps<{
    name?: string;
    showLabel?: boolean;
}>(), {
    name: 'Soci',
    showLabel: true,
});
</script>

<template>
    <div class="flex justify-start">
        <div class="max-w-[90%] space-y-1">
            <div
                v-if="showLabel"
                class="text-muted-foreground px-1 text-[10px] uppercase tracking-wide"
            >
                {{ name }} is typing…
            </div>
            <div
                class="flex items-center gap-3 rounded-2xl border border-border/60 bg-muted/80 px-3 py-2.5 shadow-sm"
            >
                <AlexAvatar size="xs" :alt="name" />
                <div class="flex items-center gap-1.5">
                    <span class="size-2 animate-bounce rounded-full bg-primary/70 [animation-delay:0ms]" />
                    <span class="size-2 animate-bounce rounded-full bg-primary/70 [animation-delay:150ms]" />
                    <span class="size-2 animate-bounce rounded-full bg-primary/70 [animation-delay:300ms]" />
                </div>
            </div>
        </div>
    </div>
</template>
