<script setup lang="ts">
import { Switch } from '@/components/ui/switch';

const model = defineModel<boolean>({ default: false });

withDefaults(defineProps<{
    disabled?: boolean;
    description?: string;
    class?: string;
}>(), {
    disabled: false,
});
</script>

<template>
    <div class="flex items-start justify-between gap-4 rounded-lg border border-border bg-muted/20 px-3 py-2.5" :class="class">
        <div class="min-w-0 text-sm">
            <p class="font-medium leading-snug"><slot /></p>
            <p v-if="description" class="mt-0.5 text-xs text-muted-foreground">{{ description }}</p>
        </div>
        <Switch v-model="model" :disabled="disabled" class="mt-0.5 shrink-0" />
    </div>
</template>
