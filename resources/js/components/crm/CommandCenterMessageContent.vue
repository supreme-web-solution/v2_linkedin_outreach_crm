<script setup lang="ts">
import { Mic } from '@lucide/vue';
import { computed } from 'vue';
import { formatChatMarkdown } from '@/lib/chatMarkdown';
import {
    isVoiceNoteMessage,
    voiceNoteTranscript,
} from '@/lib/voiceNoteMessage';

const props = defineProps<{
    content: string;
    /** Soften icon/label on primary (user) bubbles */
    onPrimary?: boolean;
}>();

const voice = computed(() => isVoiceNoteMessage(props.content));
const bodyHtml = computed(() =>
    formatChatMarkdown(voice.value ? voiceNoteTranscript(props.content) : props.content),
);
</script>

<template>
    <div v-if="voice" class="space-y-1.5">
        <div class="flex items-center gap-2">
            <div
                class="flex size-8 shrink-0 items-center justify-center rounded-full"
                :class="onPrimary ? 'bg-primary-foreground/15' : 'bg-background/70'"
                aria-hidden="true"
            >
                <Mic class="size-3.5 opacity-95" />
            </div>
            <div class="min-w-0 flex-1">
                <div
                    class="mb-1 text-[10px] font-semibold uppercase tracking-wide"
                    :class="onPrimary ? 'text-primary-foreground/80' : 'text-muted-foreground'"
                >
                    Voice note
                </div>
                <div class="flex h-3.5 items-end gap-[2px]" aria-hidden="true">
                    <span
                        v-for="(h, i) in [35, 70, 45, 90, 55, 80, 40, 65, 50, 75, 42, 85, 48, 60]"
                        :key="i"
                        class="w-[2px] rounded-full"
                        :class="onPrimary ? 'bg-primary-foreground/75' : 'bg-foreground/40'"
                        :style="{ height: `${h}%` }"
                    />
                </div>
            </div>
        </div>
        <span class="[&_strong]:font-semibold" v-html="bodyHtml" />
    </div>
    <span v-else class="[&_strong]:font-semibold" v-html="bodyHtml" />
</template>
