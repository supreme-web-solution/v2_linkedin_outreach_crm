<script setup lang="ts">
import { ChevronDown, Loader2, Rocket, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';

export type PlanFunnelStep = {
    step: string;
    label: string;
    detail: string;
    status: 'ready' | 'blocked' | 'pending';
};

export type ReviewLaunchApproval = {
    id: number;
    tool: string;
    status: string;
    payload: Record<string, unknown>;
    card_text: string;
    funnel?: PlanFunnelStep[];
    actions?: {
        approve_label?: string;
        reject_label?: string;
        preview_label?: string;
        show_preview?: boolean;
        summary?: string;
    };
};

type IntegrationChannel = {
    key: string;
    label: string;
    connected: boolean;
};

const props = defineProps<{
    approvals: ReviewLaunchApproval[];
    decidingId?: number | null;
    disabled?: boolean;
    draftEdits?: Record<number, string>;
    integrations?: IntegrationChannel[];
    formatMessageHtml: (content: string) => string;
}>();

const emit = defineEmits<{
    decide: [approvalId: number, decision: 'approve' | 'reject'];
    'update:draftEdits': [value: Record<number, string>];
}>();

const draftEditsModel = computed({
    get: () => props.draftEdits ?? {},
    set: (value) => emit('update:draftEdits', value),
});

function funnelStatusClass(status: PlanFunnelStep['status']) {
    if (status === 'ready') {
        return 'border-emerald-200/80 bg-emerald-50/80 text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-100';
    }
    if (status === 'blocked') {
        return 'border-orange-200/80 bg-orange-50/80 text-orange-900 dark:border-orange-900/40 dark:bg-orange-950/30 dark:text-orange-100';
    }
    return 'border-border bg-muted/50 text-muted-foreground';
}

function planFunnel(a: ReviewLaunchApproval): PlanFunnelStep[] {
    if (Array.isArray(a.funnel) && a.funnel.length) {
        return a.funnel;
    }
    const payloadFunnel = a.payload.funnel;
    return Array.isArray(payloadFunnel) ? (payloadFunnel as PlanFunnelStep[]) : [];
}

function isDraftReply(a: ReviewLaunchApproval) {
    return a.tool === 'draft_reply' || a.payload.type === 'draft_reply';
}

function isPersonalizedMessage(a: ReviewLaunchApproval) {
    return a.tool === 'draft_personalized_message' || a.payload.type === 'personalized_message';
}

function isEditableDraft(a: ReviewLaunchApproval) {
    return isDraftReply(a) || isPersonalizedMessage(a);
}

function isOutreachPlan(a: ReviewLaunchApproval) {
    return (
        a.tool === 'propose_strategy'
        || a.tool === 'draft_campaign_plan'
        || a.payload.type === 'strategy'
        || a.payload.type === 'campaign'
    );
}

function approveLabelFor(a: ReviewLaunchApproval): string {
    if (a.actions?.approve_label) return a.actions.approve_label;
    if (isDraftReply(a)) return 'Send';
    if (isPersonalizedMessage(a)) return 'Save';
    return 'Launch';
}

function rejectLabelFor(a: ReviewLaunchApproval): string {
    return a.actions?.reject_label || 'Reject';
}

function summaryFor(a: ReviewLaunchApproval): string {
    if (a.actions?.summary) return a.actions.summary;
    if (isOutreachPlan(a)) return 'Outreach plan ready — launch when you are ready.';
    return 'Action ready to confirm.';
}

function missingIntegrationsForPlan(a: ReviewLaunchApproval): string[] {
    if (!props.integrations?.length) return [];

    const channels = String(
        a.payload.preferred_channels ?? a.payload.channels ?? 'linkedin + email',
    ).toLowerCase();
    const missing: string[] = [];

    for (const integration of props.integrations) {
        const key = integration.key;
        const mentioned =
            channels.includes(key)
            || (key === 'linkedin' && channels.includes('linked'))
            || (key === 'email' && channels.includes('mail'))
            || (key === 'instagram' && (channels.includes('insta') || channels.includes('ig')))
            || (key === 'telegram' && channels.includes('telegram'))
            || (key === 'whatsapp' && (channels.includes('whats') || channels.includes(' wa')));

        if (mentioned && !integration.connected) {
            missing.push(integration.label);
        }
    }

    return missing;
}

function updateDraft(id: number, text: string) {
    draftEditsModel.value = { ...draftEditsModel.value, [id]: text };
}
</script>

<template>
    <div
        v-for="a in approvals"
        :key="a.id"
        class="w-full min-w-0 overflow-hidden rounded-2xl rounded-bl-md border border-sky-200/90 bg-sky-50 shadow-sm dark:border-sky-800/60 dark:bg-sky-950/35"
    >
        <!-- Always visible: header + actions -->
        <div class="w-full space-y-3 p-3.5">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-sky-950 dark:text-sky-50">Review & Launch</p>
                    <p class="mt-0.5 text-xs leading-snug text-sky-800/80 dark:text-sky-200/80">
                        {{ summaryFor(a) }}
                    </p>
                </div>
                <span class="shrink-0 rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-medium text-sky-800 dark:bg-sky-900/60 dark:text-sky-100">
                    #{{ a.id }}
                </span>
            </div>

            <div
                v-if="isOutreachPlan(a) && missingIntegrationsForPlan(a).length"
                class="rounded-lg border border-orange-200/80 bg-white/70 px-2.5 py-2 text-xs text-orange-900 dark:border-orange-900/40 dark:bg-orange-950/25 dark:text-orange-100"
            >
                Launch is blocked until you connect
                {{ missingIntegrationsForPlan(a).join(' and ') }}.
                <a href="/integrations" class="font-medium underline">Open Integrations</a>
            </div>

            <div class="flex w-full gap-2">
                <Button
                    size="sm"
                    class="h-9 min-w-0 flex-1 text-xs"
                    :disabled="disabled || decidingId === a.id"
                    @click="emit('decide', a.id, 'approve')"
                >
                    <Loader2 v-if="decidingId === a.id" class="mr-1.5 size-3.5 animate-spin" />
                    <Rocket v-else class="mr-1.5 size-3.5" />
                    {{ approveLabelFor(a) }}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-9 min-w-0 flex-1 border-sky-300/80 bg-white/80 text-xs hover:bg-white dark:border-sky-700 dark:bg-sky-950/50"
                    :disabled="disabled || decidingId === a.id"
                    @click="emit('decide', a.id, 'reject')"
                >
                    <X class="mr-1.5 size-3.5" />
                    {{ rejectLabelFor(a) }}
                </Button>
            </div>
        </div>

        <!-- Collapsible details only — header + buttons stay expanded above -->
        <Collapsible>
            <CollapsibleTrigger
                class="group flex w-full items-center gap-2 border-t border-sky-200/80 bg-sky-100/50 px-3.5 py-2.5 text-left text-xs font-medium text-sky-900/80 transition-colors hover:bg-sky-100 hover:text-sky-950 dark:border-sky-800/60 dark:bg-sky-900/30 dark:text-sky-100/80 dark:hover:bg-sky-900/50 dark:hover:text-sky-50"
            >
                <ChevronDown class="size-3.5 shrink-0 transition-transform group-data-[state=open]:rotate-180" />
                View full plan details
            </CollapsibleTrigger>
            <CollapsibleContent class="border-t border-sky-200/80 bg-sky-100/30 dark:border-sky-800/60 dark:bg-sky-950/25">
                <div class="max-h-72 w-full space-y-3 overflow-y-auto p-3.5">
                    <div
                        v-if="planFunnel(a).length"
                        class="overflow-x-auto rounded-lg border border-border bg-background p-2"
                    >
                        <div class="flex min-w-max items-stretch gap-1">
                            <div
                                v-for="(step, index) in planFunnel(a)"
                                :key="step.step"
                                class="flex items-center gap-1"
                            >
                                <div
                                    class="flex w-28 flex-col rounded-md border px-2 py-1.5 text-[10px]"
                                    :class="funnelStatusClass(step.status)"
                                >
                                    <div class="font-semibold uppercase tracking-wide">{{ step.label }}</div>
                                    <div class="mt-0.5 line-clamp-3 leading-snug">{{ step.detail }}</div>
                                </div>
                                <span
                                    v-if="index < planFunnel(a).length - 1"
                                    class="text-muted-foreground px-0.5"
                                >→</span>
                            </div>
                        </div>
                    </div>

                    <template v-if="isEditableDraft(a)">
                        <div class="space-y-1 text-xs">
                            <div class="font-medium text-foreground">{{ a.payload.prospect_name ?? 'Prospect' }}</div>
                            <div class="text-muted-foreground">{{ a.payload.channel_label ?? a.payload.channel }}</div>
                            <div v-if="a.payload.inbound_preview" class="text-muted-foreground italic">
                                "{{ a.payload.inbound_preview }}"
                            </div>
                        </div>
                        <textarea
                            :value="draftEditsModel[a.id] ?? a.payload.draft_text"
                            rows="4"
                            class="border-input bg-background w-full resize-y rounded-md border px-2 py-1.5 text-xs"
                            placeholder="Edit before Launch…"
                            @input="updateDraft(a.id, ($event.target as HTMLTextAreaElement).value)"
                        />
                    </template>

                    <div
                        v-else-if="a.card_text"
                        class="w-full rounded-lg border border-sky-200/70 bg-white/80 p-3 font-sans text-xs whitespace-pre-wrap dark:border-sky-800/50 dark:bg-sky-950/40 [&_strong]:font-semibold"
                        v-html="formatMessageHtml(a.card_text)"
                    />
                </div>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>
