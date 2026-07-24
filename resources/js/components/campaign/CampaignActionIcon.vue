<script setup lang="ts">
import { computed, type Component } from 'vue';
import {
    Eye,
    Heart,
    MessageSquare,
    Star,
    UserPlus,
} from '@lucide/vue';

const props = withDefaults(defineProps<{
    value?: string | number;
    size?: number;
    class?: string;
}>(), {
    size: 16,
});

const ICONS: Record<string, Component> = {
    'send-invite': UserPlus,
    message: MessageSquare,
    endorse: Star,
    'profile-view': Eye,
    follow: Eye,
    'like-post': Heart,
};

const Icon = computed(() => ICONS[String(props.value ?? '')] ?? UserPlus);
</script>

<template>
    <component :is="Icon" :class="props.class" :size="size" stroke-width="2" />
</template>
