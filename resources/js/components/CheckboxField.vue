<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';

const model = defineModel<boolean>({ default: false });

withDefaults(defineProps<{
    disabled?: boolean;
}>(), {
    disabled: false,
});
</script>

<template>
    <label class="flex cursor-pointer items-center gap-2 text-sm">
        <Checkbox v-model="model" :disabled="disabled" />
        <span><slot /></span>
    </label>
</template>
