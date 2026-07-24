<script setup lang="ts">
import {
    AtSign,
    Camera,
    Mail,
    MessageCircle,
    Network,
    Send,
} from '@lucide/vue';
import { computed, type Component } from 'vue';
import type { OutreachChannel } from '@/components/outreach/types';

const props = withDefaults(defineProps<{
    channel?: OutreachChannel | string;
    size?: number;
    class?: string;
}>(), {
    size: 16,
});

const ICONS: Record<string, Component> = {
    linkedin: Network,
    email: Mail,
    whatsapp: MessageCircle,
    instagram: Camera,
    telegram: Send,
    twitter: AtSign,
};

const Icon = computed(() => ICONS[String(props.channel ?? 'linkedin')] ?? Mail);
</script>

<template>
    <component :is="Icon" :class="props.class" :size="size" stroke-width="2" />
</template>
