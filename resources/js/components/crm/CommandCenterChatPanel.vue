<script setup lang="ts">
import { computed } from 'vue';
import { Eraser, Loader2, Send } from '@lucide/vue';
import ChatProcessingIndicator from '@/components/crm/ChatProcessingIndicator.vue';
import CommandCenterChannelLabel from '@/components/crm/CommandCenterChannelLabel.vue';
import CommandCenterMessageContent from '@/components/crm/CommandCenterMessageContent.vue';
import CommandCenterReviewLaunchInline from '@/components/crm/CommandCenterReviewLaunchInline.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { useCommandCenterChat } from '@/composables/useCommandCenterChat';

type ChatApi = ReturnType<typeof useCommandCenterChat>;

const props = defineProps<{
    chat: ChatApi;
    compact?: boolean;
}>();

const {
    chat: messages,
    draft,
    settings,
    sending,
    awaitingReply,
    processingLabel,
    decidingApprovalId,
    clearingChat,
    pendingApprovals,
    bootstrapping,
    hasOlderMessages,
    loadingOlder,
    scrollEl,
    send,
    clearChat,
    decideApproval,
    loadOlderMessages,
    onChatScroll,
    formatMessageHtml,
    formatChatDateDivider,
    formatChatMessageTime,
    showChatDateDivider,
} = props.chat;

const chatBusy = computed(
    () => sending.value || awaitingReply.value || decidingApprovalId.value !== null || clearingChat.value,
);

const inlineApprovals = computed(() =>
    (pendingApprovals.value ?? []).map((approval) => ({
        id: approval.id,
        tool: approval.tool ?? 'plan',
        status: approval.status ?? 'pending',
        payload: approval.payload ?? {},
        card_text: approval.card_text ?? '',
        funnel: (approval as { funnel?: unknown }).funnel as import('@/components/crm/CommandCenterReviewLaunchInline.vue').PlanFunnelStep[] | undefined,
        actions: approval.actions,
    })),
);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div class="flex shrink-0 items-center justify-between gap-2 border-b px-3 py-2">
            <span class="text-muted-foreground text-xs font-medium">Chat</span>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                class="h-7 px-2 text-[11px] text-muted-foreground"
                :disabled="chatBusy || bootstrapping"
                title="Archive this thread and start fresh. Pending Launch items stay."
                @click="clearChat"
            >
                <Loader2 v-if="clearingChat" class="mr-1 size-3 animate-spin" />
                <Eraser v-else class="mr-1 size-3" />
                Clear chat
            </Button>
        </div>

        <div
            ref="scrollEl"
            class="min-h-0 flex-1 space-y-3 overflow-y-auto p-3"
            @scroll="onChatScroll"
        >
            <div v-if="bootstrapping" class="flex justify-center py-8">
                <Loader2 class="text-muted-foreground size-5 animate-spin" />
            </div>

            <template v-else>
                <div v-if="hasOlderMessages" class="flex justify-center py-1">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border bg-background px-3 py-1 text-[11px] font-medium text-muted-foreground hover:bg-muted/50 disabled:opacity-60"
                        :disabled="loadingOlder"
                        @click="loadOlderMessages"
                    >
                        <Loader2 v-if="loadingOlder" class="size-3 animate-spin" />
                        {{ loadingOlder ? 'Loading…' : 'Load older messages' }}
                    </button>
                </div>

                <template v-for="(m, i) in messages" :key="m.id ?? `local-${i}`">
                    <div
                        v-if="showChatDateDivider(messages, i)"
                        class="flex justify-center py-1"
                    >
                        <span class="rounded-full bg-muted/70 px-3 py-1 text-[11px] font-medium text-muted-foreground shadow-sm">
                            {{ formatChatDateDivider(m.created_at) }}
                        </span>
                    </div>

                    <div
                        class="flex"
                        :class="m.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div class="max-w-[92%] space-y-1">
                            <CommandCenterChannelLabel
                                :channel="m.channel"
                                :align="m.role === 'user' ? 'right' : 'left'"
                            />
                            <div
                                class="rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap [&_strong]:font-semibold"
                                :class="
                                    m.role === 'user'
                                        ? 'rounded-br-md bg-primary text-primary-foreground [&_strong]:text-primary-foreground'
                                        : 'rounded-bl-md bg-muted text-foreground'
                                "
                            >
                                <CommandCenterMessageContent
                                    :content="m.content"
                                    :on-primary="m.role === 'user'"
                                />
                                <div
                                    v-if="m.created_at"
                                    class="mt-1.5 flex justify-end border-t pt-1"
                                    :class="
                                        m.role === 'user'
                                            ? 'border-primary-foreground/15'
                                            : 'border-border/60'
                                    "
                                >
                                    <time
                                        :datetime="m.created_at"
                                        class="text-[11px] font-medium tabular-nums tracking-wide select-none"
                                        :class="
                                            m.role === 'user'
                                                ? 'text-primary-foreground/75'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{ formatChatMessageTime(m.created_at) }}
                                    </time>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <div
                    v-if="inlineApprovals.length"
                    class="flex w-full justify-start"
                >
                    <div class="w-full max-w-[92%] space-y-2">
                        <CommandCenterReviewLaunchInline
                            :approvals="inlineApprovals"
                            :deciding-id="decidingApprovalId"
                            :disabled="chatBusy"
                            :format-message-html="formatMessageHtml"
                            @decide="(id, decision) => decideApproval(id, decision)"
                        />
                    </div>
                </div>

                <ChatProcessingIndicator
                    v-if="chatBusy && !clearingChat"
                    :name="settings.employee_name"
                    :show-label="!compact"
                    :status-label="processingLabel"
                />
            </template>
        </div>

        <form class="flex shrink-0 gap-2 border-t p-3" @submit.prevent="send()">
            <Input
                v-model="draft"
                placeholder="Message Soci…"
                class="flex-1"
                :disabled="chatBusy || bootstrapping || !settings.enabled || settings.kill_switch"
            />
            <Button type="submit" size="icon" :disabled="chatBusy || bootstrapping || !draft.trim()">
                <Loader2 v-if="chatBusy" class="size-4 animate-spin" />
                <Send v-else class="size-4" />
            </Button>
        </form>
    </div>
</template>
