<script setup lang="ts">
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { cn } from '@/lib/utils';

withDefaults(defineProps<{
    title?: string;
    iconSize?: number;
    showBadge?: boolean;
    headingClass?: string;
    inline?: boolean;
}>(), {
    iconSize: 28,
    showBadge: false,
    headingClass: 'text-xl font-semibold tracking-tight',
    inline: false,
});
</script>

<template>
    <div :class="cn('flex gap-2.5', inline ? 'min-w-0 items-center' : 'items-start')">
        <OutreachChannelIcon
            channel="linkedin"
            :size="iconSize"
            :class="cn('shrink-0', inline ? '' : 'mt-0.5', iconSize >= 28 ? 'h-7 w-7' : 'h-6 w-6')"
        />
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 :class="cn(headingClass, 'text-foreground', inline && 'truncate')">
                    <slot>{{ title }}</slot>
                </h1>
                <span
                    v-if="showBadge"
                    class="rounded-full bg-[#0077b5]/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#0077b5]"
                >
                    LinkedIn
                </span>
                <slot name="trailing" />
            </div>
            <p v-if="$slots.subtitle" class="mt-1 text-sm text-muted-foreground">
                <slot name="subtitle" />
            </p>
        </div>
    </div>
</template>
