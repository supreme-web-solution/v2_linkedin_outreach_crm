<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Clock, GitBranch, Trash2 } from '@lucide/vue';
import FlowMessageAiHelp from '@/components/flow/FlowMessageAiHelp.vue';
import CampaignActionIcon from '@/components/campaign/CampaignActionIcon.vue';
import InviteNoteLimitHint, { type ActionQuotaSnapshot } from '@/components/linkedin/InviteNoteLimitHint.vue';
import { conditionPrerequisiteWarning } from '@/components/campaign/stepMutations';
import { CAMPAIGN_ACTIONS, CAMPAIGN_CONDITIONS, type CampaignStep } from '@/components/campaign/types';

const props = defineProps<{
    step: CampaignStep;
    steps?: CampaignStep[];
}>();

const emit = defineEmits<{
    updateField: [field: string, value: unknown];
    updateConfig: [key: string, value: unknown];
    delete: [];
    addAfter: [type: 'action' | 'delay', value?: string];
    addCondition: [value: string, label: string];
}>();

const page = usePage();
const actionQuotas = computed(() => {
    const raw = page.props.action_quotas as
        | { invites?: ActionQuotaSnapshot; messages?: ActionQuotaSnapshot }
        | undefined;
    return raw ?? null;
});
const inviteQuota = computed(() => actionQuotas.value?.invites ?? null);
const messageQuota = computed(() => actionQuotas.value?.messages ?? null);
const hasInviteNote = computed(() => String(props.step.config?.message ?? '').trim() !== '');

const isAction = computed(() => props.step.type === 'action');
const isDelay = computed(() => props.step.type === 'delay');
const isCondition = computed(() => props.step.type === 'condition');
const isEnd = computed(() => props.step.type === 'end');
const prerequisiteWarning = computed(() =>
    isCondition.value ? conditionPrerequisiteWarning(props.steps ?? [], props.step) : null,
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Selected step</p>
                <p class="text-sm font-semibold">{{ step.label }}</p>
                <p class="text-[11px] capitalize text-muted-foreground">{{ step.type }}</p>
            </div>
            <button
                v-if="!isEnd"
                type="button"
                class="rounded-lg p-1.5 text-muted-foreground hover:bg-red-50 hover:text-red-500"
                :title="isCondition ? 'Remove condition and Yes/No steps' : 'Remove step'"
                @click="emit('delete')"
            >
                <Trash2 class="h-4 w-4" />
            </button>
        </div>

        <template v-if="isDelay">
            <div class="flex items-center gap-2">
                <span class="text-xs font-medium text-amber-700">Wait</span>
                <input
                    type="number"
                    :value="step.value"
                    min="1"
                    max="365"
                    class="w-16 rounded-lg border border-amber-200 px-2 py-1 text-xs text-center"
                    @change="emit('updateField', 'value', +(($event.target as HTMLInputElement).value))"
                />
                <select
                    :value="step.time"
                    class="rounded-lg border border-amber-200 bg-white px-2 py-1 text-xs"
                    @change="emit('updateField', 'time', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="hours">hours</option>
                    <option value="days">days</option>
                </select>
            </div>
        </template>

        <template v-if="isAction">
            <div class="flex flex-col gap-1">
                <label class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Action type</label>
                <select
                    :value="step.value"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400/20"
                    @change="emit('updateField', 'value', ($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="action in CAMPAIGN_ACTIONS" :key="action.value" :value="action.value">
                        {{ action.label }}
                    </option>
                </select>
            </div>

            <template v-if="step.value === 'message' || step.value === 'send-invite'">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ step.value === 'send-invite' ? 'Invite note (optional)' : 'Message text' }}
                        </label>
                        <FlowMessageAiHelp
                            channel="linkedin"
                            :action="step.value === 'send-invite' ? 'send_invite' : 'send_message'"
                            field="message"
                            :current-text="(step.config?.message as string) ?? ''"
                            @apply="emit('updateConfig', 'message', $event)"
                        />
                    </div>
                    <textarea
                        :value="(step.config?.message as string) ?? ''"
                        rows="4"
                        :placeholder="step.value === 'send-invite'
                            ? 'Leave blank to send without a note, or write your own…'
                            : 'Use {{firstName}}, {{lastName}}, {{company}}, {{position}}'"
                        class="w-full min-h-[6rem] resize-y rounded-lg border border-border bg-background px-3 py-2 text-xs outline-none focus:ring-1 focus:ring-blue-400/30"
                        @input="emit('updateConfig', 'message', ($event.target as HTMLTextAreaElement).value)"
                    />
                    <InviteNoteLimitHint
                        v-if="step.value === 'send-invite'"
                        variant="invite"
                        :quota="inviteQuota"
                        :has-invite-note="hasInviteNote"
                    />
                    <InviteNoteLimitHint
                        v-else-if="step.value === 'message'"
                        variant="action"
                        :quota="messageQuota"
                    />
                    <p class="text-[10px] text-muted-foreground" v-pre>
                        Variables:
                        <code class="rounded bg-muted px-0.5">{{firstName}}</code>
                        <code class="rounded bg-muted px-0.5">{{company}}</code>
                        <code class="rounded bg-muted px-0.5">{{position}}</code>
                    </p>
                </div>
            </template>

            <template v-if="step.value === 'endorse'">
                <div class="flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <label class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                            Skills to endorse
                        </label>
                        <input
                            type="number"
                            :value="(step.config?.skills as number) ?? 3"
                            min="1"
                            max="10"
                            class="w-16 rounded-lg border border-border bg-background px-2 py-1 text-sm text-center"
                            @input="emit('updateConfig', 'skills', +(($event.target as HTMLInputElement).value))"
                        />
                    </div>
                    <InviteNoteLimitHint variant="action" />
                </div>
            </template>

            <template v-if="step.value === 'profile-view' || step.value === 'follow' || step.value === 'like-post'">
                <div class="flex flex-col gap-2">
                    <p class="text-xs text-muted-foreground">No additional configuration needed.</p>
                    <InviteNoteLimitHint variant="action" />
                </div>
            </template>
        </template>

        <template v-if="isCondition">
            <div
                v-if="prerequisiteWarning"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-snug text-amber-900"
            >
                {{ prerequisiteWarning }}
            </div>
            <p class="text-xs text-muted-foreground">
                Use the <strong>+</strong> on Yes (right) or No (left) to add steps to each branch. Select any branch step to configure it.
            </p>
        </template>

        <div v-if="!isEnd" class="border-t border-border pt-3">
            <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Add step after</p>
            <div class="flex flex-col gap-1">
                <button
                    v-for="action in CAMPAIGN_ACTIONS"
                    :key="action.value"
                    type="button"
                    class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs transition-colors hover:bg-muted"
                    @click="emit('addAfter', 'action', action.value)"
                >
                    <span
                        class="flex h-6 w-6 items-center justify-center rounded-md border"
                        :style="{ background: action.light, borderColor: action.border, color: action.accent }"
                    >
                        <CampaignActionIcon :value="action.value" :size="13" />
                    </span>
                    {{ action.label }}
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs text-amber-700 transition-colors hover:bg-amber-50"
                    @click="emit('addAfter', 'delay')"
                >
                    <Clock class="h-4 w-4" />
                    Add Wait / Delay
                </button>
                <p class="mb-1 mt-2 px-0.5 text-[10px] font-semibold uppercase tracking-wide text-orange-500/90">
                    Conditions · Yes / No
                </p>
                <button
                    v-for="cond in CAMPAIGN_CONDITIONS"
                    :key="cond.value"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg border border-orange-200/80 bg-orange-50/50 px-2 py-2 text-left text-xs text-orange-900 transition-colors hover:border-orange-300 hover:bg-orange-50"
                    @click="emit('addCondition', cond.value, cond.label)"
                >
                    <GitBranch class="h-4 w-4 shrink-0 text-orange-600" />
                    <span class="min-w-0">
                        <span class="block font-semibold">{{ cond.label }}</span>
                        <span class="block text-[10px] font-normal text-orange-800/80">Yes = accepted · No = not accepted</span>
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>
