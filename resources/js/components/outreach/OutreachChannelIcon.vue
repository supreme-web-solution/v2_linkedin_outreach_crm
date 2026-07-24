<script setup lang="ts">
import { computed } from 'vue';
import { brandIconSrc } from '@/lib/brandIcons';
import type { OutreachChannel } from '@/components/outreach/types';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{
    channel?: OutreachChannel | string;
    size?: number;
    class?: string;
    rounded?: boolean;
}>(), {
    size: 20,
    rounded: true,
});

const src = computed(() => brandIconSrc(props.channel));
const alt = computed(() => `${String(props.channel ?? 'channel')} icon`);
</script>

<template>
    <img
        :src="src"
        :alt="alt"
        :width="size"
        :height="size"
        loading="lazy"
        decoding="async"
        :class="cn('shrink-0 object-contain', rounded ? 'rounded-[22%]' : '', props.class)"
    />
</template>
