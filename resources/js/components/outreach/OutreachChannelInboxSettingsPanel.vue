<script setup lang="ts">
import { computed } from 'vue';
import { Inbox, Info, Pause, Sparkles } from '@lucide/vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

export type ChannelInboxSettings = {
    ai_context: string;
    auto_reply_enabled: boolean;
    pause_on_reply: boolean;
};

export type ChannelInboxPlatform = {
    channel: string;
    label: string;
    color: string;
};

const props = defineProps<{
    platforms: ChannelInboxPlatform[];
    modelValue: Record<string, ChannelInboxSettings>;
    aiConfigured?: boolean;
    showReplyBranchHint?: boolean;
    compact?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: Record<string, ChannelInboxSettings>];
}>();

const showPauseOnReplyWarning = computed(() => {
    if (!props.showReplyBranchHint) return false;

    return props.platforms.some((p) => props.modelValue[p.channel]?.pause_on_reply === true);
});

function patchChannel(channel: string, patch: Partial<ChannelInboxSettings>) {
    const current = props.modelValue[channel] ?? {
        ai_context: '',
        auto_reply_enabled: false,
        pause_on_reply: true,
    };

    emit('update:modelValue', {
        ...props.modelValue,
        [channel]: { ...current, ...patch },
    });
}
</script>

<template>
    <div class="rounded-xl border border-border bg-card">
        <div class="border-b border-border px-4 py-3">
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <Inbox class="h-4 w-4" /> Inbox & reply handling
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Per platform used in this sequence — same settings as the live inbox sidebar. Review before launch.
            </p>
        </div>

        <div v-if="showPauseOnReplyWarning" class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-950">
            Your sequence includes a <strong>“replied”</strong> condition branch. Turn <strong>Pause on reply</strong> off for that platform
            so follow-up steps on the replied path can run. With pause on, the whole sequence stops when they reply.
        </div>

        <div class="divide-y divide-border">
            <div
                v-for="platform in platforms"
                :key="platform.channel"
                class="p-4"
            >
                <div class="flex items-center gap-2">
                    <OutreachChannelIcon :channel="platform.channel" :size="18" class="h-[18px] w-[18px]" />
                    <span class="text-sm font-medium">{{ platform.label }}</span>
                </div>

                <div class="mt-3 space-y-3">
                    <textarea
                        v-if="!compact"
                        :value="modelValue[platform.channel]?.ai_context ?? ''"
                        rows="2"
                        class="w-full rounded-lg border border-border bg-background px-2.5 py-2 text-xs"
                        :placeholder="`${platform.label} AI context — offer, tone, objections`"
                        @input="patchChannel(platform.channel, { ai_context: ($event.target as HTMLTextAreaElement).value })"
                    />

                    <div class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/20 px-2.5 py-2">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <Sparkles class="h-3.5 w-3.5 shrink-0 text-violet-600" />
                            <span class="text-xs font-medium">AI auto-reply</span>
                            <Tooltip :delay-duration="200">
                                <TooltipTrigger as-child>
                                    <button type="button" class="rounded p-0.5 text-muted-foreground hover:text-foreground" aria-label="About AI auto-reply">
                                        <Info class="h-3.5 w-3.5" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent side="top" class="max-w-[14rem] text-xs leading-relaxed">
                                    When enabled, inbound replies on {{ platform.label }} get an AI reply using this campaign context.
                                </TooltipContent>
                            </Tooltip>
                        </div>
                        <Switch
                            :model-value="modelValue[platform.channel]?.auto_reply_enabled ?? false"
                            class="shrink-0"
                            @update:model-value="patchChannel(platform.channel, { auto_reply_enabled: $event })"
                        />
                    </div>

                    <div class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/20 px-2.5 py-2">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <Pause class="h-3.5 w-3.5 shrink-0 text-amber-600" />
                            <span class="text-xs font-medium">Pause on reply</span>
                            <Tooltip :delay-duration="200">
                                <TooltipTrigger as-child>
                                    <button type="button" class="rounded p-0.5 text-muted-foreground hover:text-foreground" aria-label="About pause on reply">
                                        <Info class="h-3.5 w-3.5" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent side="top" class="max-w-[14rem] text-xs leading-relaxed">
                                    Stops this lead's sequence when they reply on {{ platform.label }}. Other leads keep running.
                                </TooltipContent>
                            </Tooltip>
                        </div>
                        <Switch
                            :model-value="modelValue[platform.channel]?.pause_on_reply ?? true"
                            class="shrink-0"
                            @update:model-value="patchChannel(platform.channel, { pause_on_reply: $event })"
                        />
                    </div>
                </div>
            </div>
        </div>

        <p v-if="!aiConfigured" class="border-t border-border px-4 py-3 text-xs text-amber-600">
            Add OPENAI_API_KEY to enable AI auto-replies.
        </p>
    </div>
</template>
