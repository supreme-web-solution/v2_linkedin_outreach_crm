<script setup lang="ts">
import type { ButtonVariants } from '@/components/ui/button';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        variant?: ButtonVariants['variant'];
        disabled?: boolean;
        type?: 'button' | 'submit' | 'reset';
        title?: string;
    }>(),
    {
        variant: 'default',
        type: 'button',
    },
);
</script>

<template>
    <Button :variant="variant" size="toolbar" :disabled="disabled" :type="type" :title="title">
        <slot />
    </Button>
</template>
