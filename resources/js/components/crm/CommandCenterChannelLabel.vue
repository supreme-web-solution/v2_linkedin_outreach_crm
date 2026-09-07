<script setup lang="ts">
import { Globe } from '@lucide/vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';

const props = defineProps<{
    channel?: string | null;
    align?: 'left' | 'right';
}>();

function channelLabel(channel?: string | null): string | null {
    if (channel === 'whatsapp') return 'WhatsApp';
    if (channel === 'web') return 'Web';

    return null;
}
</script>

<template>
    <div
        v-if="channelLabel(channel)"
        class="text-muted-foreground flex items-center gap-1 px-1 text-[10px] font-medium uppercase tracking-wide"
        :class="align === 'right' ? 'justify-end' : 'justify-start'"
    >
        <OutreachChannelIcon
            v-if="channel === 'whatsapp'"
            channel="whatsapp"
            :size="12"
            class="rounded-sm"
        />
        <Globe
            v-else-if="channel === 'web'"
            class="size-3 shrink-0 text-primary"
            aria-hidden="true"
        />
        <span>{{ channelLabel(channel) }}</span>
    </div>
</template>
