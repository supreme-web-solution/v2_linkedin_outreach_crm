<script setup lang="ts">
import { ALEX_AVATAR_SRC } from '@/lib/alexAvatar';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        size?: 'xs' | 'sm' | 'md' | 'lg' | 'launcher';
        online?: boolean;
        alt?: string;
        class?: string;
    }>(),
    {
        size: 'md',
        alt: 'Alex',
    },
);

const sizeClasses: Record<NonNullable<typeof props.size>, string> = {
    xs: 'size-8',
    sm: 'size-9',
    md: 'size-10',
    lg: 'size-14',
    launcher: 'size-14',
};

const onlineDotClasses: Record<NonNullable<typeof props.size>, string> = {
    xs: 'size-2.5 border',
    sm: 'size-2.5 border',
    md: 'size-3 border-2',
    lg: 'size-3.5 border-2',
    launcher: 'size-3.5 border-2',
};
</script>

<template>
    <div :class="cn('relative shrink-0', props.class)">
        <img
            :src="ALEX_AVATAR_SRC"
            :alt="alt"
            class="rounded-full object-cover object-center ring-1 ring-black/10 dark:ring-white/10"
            :class="sizeClasses[size]"
        />
        <span
            v-if="online"
            class="absolute -right-0.5 -bottom-0.5 rounded-full border-background bg-emerald-400"
            :class="onlineDotClasses[size]"
        />
    </div>
</template>
