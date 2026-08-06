<script setup lang="ts">
/**
 * Legacy vertical step list — kept for reference; campaign builder uses CampaignFlowCanvas.
 * Re-exports shared types for backward compatibility.
 */
import { ref } from 'vue';
import { Trash2, GripVertical, ChevronDown, ChevronUp, Plus, Clock, GitBranch } from '@lucide/vue';
import { actionMeta, CAMPAIGN_ACTIONS, type CampaignStep } from '@/components/campaign/types';
import CampaignActionIcon from '@/components/campaign/CampaignActionIcon.vue';
import InviteNoteLimitHint from '@/components/linkedin/InviteNoteLimitHint.vue';

export type { CampaignStep };

const ACTIONS = CAMPAIGN_ACTIONS;

const props = defineProps<{ steps: CampaignStep[]; isBranch?: boolean }>();
const emit = defineEmits<{ stepsChanged: [steps: CampaignStep[]] }>();

const selectedKey = ref<number | null>(null);
const dragSrcIdx  = ref<number | null>(null);
const dragOverIdx = ref<number | null>(null);

function nextKey(): number {
    let max = 0;
    function walk(list: CampaignStep[]) {
        for (const s of list) {
            if (s.key > max) max = s.key;
            if (s.branches) { walk(s.branches.accepted); walk(s.branches.not_accepted); }
        }
    }
    walk(props.steps);
    return max + 1;
}

function update(newSteps: CampaignStep[]) { emit('stepsChanged', [...newSteps]); }

function toggle(key: number) {
    selectedKey.value = selectedKey.value === key ? null : key;
}

function remove(idx: number) {
    const s = [...props.steps];
    s.splice(idx, 1);
    if (selectedKey.value === props.steps[idx]?.key) selectedKey.value = null;
    update(s);
}

function insertAfter(idx: number, type: 'action' | 'delay', value = 'message') {
    const info = ACTIONS.find(a => a.value === value) ?? ACTIONS[1];
    const step: CampaignStep = type === 'delay'
        ? { key: nextKey(), type: 'delay', value: 1, time: 'days', label: 'Wait 1 day' }
        : { key: nextKey(), type: 'action', value, label: info.label, config: value === 'endorse' ? { skills: 3 } : { message: '' } };

    const endIdx = props.steps.findIndex(s => s.type === 'end');
    const insertIdx = idx === -1 ? (endIdx === -1 ? props.steps.length : endIdx) : Math.min(idx + 1, endIdx === -1 ? props.steps.length : endIdx);

    const s = [...props.steps];
    s.splice(insertIdx, 0, step);
    update(s);
    selectedKey.value = step.key;
}

function updateField(idx: number, field: string, val: unknown) {
    const s = [...props.steps];
    (s[idx] as unknown as Record<string, unknown>)[field] = val;
    if (field === 'value') s[idx].label = actionMeta(val as string).label;
    if (field === 'time' || (field === 'value' && s[idx].type === 'delay')) {
        s[idx].label = `Wait ${s[idx].value} ${s[idx].time}`;
    }
    update(s);
}

function updateConfig(idx: number, key: string, val: unknown) {
    const s = [...props.steps];
    s[idx].config = { ...s[idx].config, [key]: val };
    update(s);
}

function updateBranch(idx: number, branch: 'accepted' | 'not_accepted', newBranch: CampaignStep[]) {
    const s = [...props.steps];
    if (s[idx].branches) s[idx].branches![branch] = newBranch;
    update(s);
}

function onDragStart(idx: number, e: DragEvent) {
    dragSrcIdx.value = idx;
    if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(idx)); }
}
function onDragOver(idx: number, e: DragEvent) { e.preventDefault(); dragOverIdx.value = idx; }
function onDragLeave()  { dragOverIdx.value = null; }
function onDrop(toIdx: number) {
    const from = dragSrcIdx.value;
    if (from === null || from === toIdx) { dragSrcIdx.value = null; dragOverIdx.value = null; return; }
    const s = [...props.steps];
    const [item] = s.splice(from, 1);
    s.splice(toIdx, 0, item);
    update(s);
    dragSrcIdx.value = null;
    dragOverIdx.value = null;
}
function onDragEnd() { dragSrcIdx.value = null; dragOverIdx.value = null; }

const addMenuAfter = ref<number | null>(null);
function openAddMenu(afterIdx: number) { addMenuAfter.value = addMenuAfter.value === afterIdx ? null : afterIdx; }
function pickAction(afterIdx: number, value: string) {
    insertAfter(afterIdx, 'action', value);
    addMenuAfter.value = null;
}
function pickDelay(afterIdx: number) {
    insertAfter(afterIdx, 'delay');
    addMenuAfter.value = null;
}
</script>

<template>
    <div class="flex flex-col items-center w-full" :class="isBranch ? 'gap-0' : 'gap-0'">
        <template v-for="(step, idx) in steps" :key="step.key">

            <div v-if="dragOverIdx === idx && dragSrcIdx !== idx"
                class="w-48 h-1.5 rounded-full bg-blue-400 my-1 transition-all" />

            <div v-if="step.type === 'end'" class="flex flex-col items-center">
                <div class="w-0.5 h-6 bg-border" v-if="!isBranch || idx > 0" />
                <div class="flex items-center gap-2 rounded-full border border-dashed border-border bg-background px-4 py-1.5 text-xs text-muted-foreground">
                    <span class="h-2 w-2 rounded-full bg-muted-foreground/40" /> End
                </div>
            </div>

            <template v-else-if="step.type === 'delay'">
                <div class="w-0.5 h-5 bg-border" />
                <div
                    draggable="true"
                    @dragstart="onDragStart(idx, $event)"
                    @dragover="onDragOver(idx, $event)"
                    @dragleave="onDragLeave"
                    @drop="onDrop(idx)"
                    @dragend="onDragEnd"
                    @click="toggle(step.key)"
                    class="group relative flex items-center gap-2.5 rounded-xl border border-dashed border-amber-300 bg-amber-50 px-4 py-2.5 cursor-pointer select-none transition-all hover:shadow-sm"
                    :class="selectedKey === step.key ? 'ring-2 ring-amber-400/60 shadow-sm' : ''"
                    style="min-width:220px">
                    <GripVertical class="h-3.5 w-3.5 text-amber-300 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity cursor-grab active:cursor-grabbing" />
                    <Clock class="h-3.5 w-3.5 shrink-0 text-amber-600" />
                    <span class="text-xs font-semibold text-amber-800 flex-1">Wait {{ step.value }} {{ step.time }}</span>
                    <button @click.stop="remove(idx)" class="opacity-0 group-hover:opacity-100 transition-opacity text-amber-400 hover:text-red-500 p-0.5 rounded">
                        <Trash2 class="h-3 w-3" />
                    </button>
                </div>
                <div v-if="selectedKey === step.key"
                    class="flex items-center gap-2 rounded-xl border border-amber-200 bg-white px-4 py-3 shadow-sm"
                    style="min-width:220px">
                    <span class="text-xs text-amber-700 font-medium">Wait</span>
                    <input type="number" :value="step.value" min="1" max="365"
                        @change="updateField(idx, 'value', +($event.target as HTMLInputElement).value)"
                        class="w-16 rounded-lg border border-amber-200 px-2 py-1 text-xs text-center" />
                    <select :value="step.time"
                        @change="updateField(idx, 'time', ($event.target as HTMLSelectElement).value)"
                        class="rounded-lg border border-amber-200 px-2 py-1 text-xs bg-white">
                        <option value="hours">hours</option>
                        <option value="days">days</option>
                    </select>
                </div>
            </template>

            <template v-else-if="step.type === 'condition'">
                <div class="w-0.5 h-5 bg-border" />
                <div class="flex items-center gap-2 rounded-xl border border-orange-300 bg-orange-50 px-4 py-2.5"
                    style="min-width:220px">
                    <GitBranch class="h-3.5 w-3.5 shrink-0 text-orange-500" />
                    <span class="text-xs font-semibold text-orange-800">Invite Accepted?</span>
                </div>
                <div class="w-full mt-3 grid grid-cols-2 gap-2 px-2">
                    <div class="flex flex-col items-center">
                        <div class="flex items-center gap-1 mb-2">
                            <span class="h-px w-8 bg-slate-300" />
                            <span class="text-[10px] font-bold text-slate-500 bg-slate-50 border border-slate-200 rounded-full px-2 py-0.5">✕ No</span>
                            <span class="h-px w-8 bg-slate-300" />
                        </div>
                        <StepList
                            v-if="step.branches"
                            :steps="step.branches.not_accepted"
                            :is-branch="true"
                            @steps-changed="updateBranch(idx, 'not_accepted', $event)" />
                    </div>
                    <div class="flex flex-col items-center">
                        <div class="flex items-center gap-1 mb-2">
                            <span class="h-px w-8 bg-green-300" />
                            <span class="text-[10px] font-bold text-green-600 bg-green-50 border border-green-200 rounded-full px-2 py-0.5">✓ Yes</span>
                            <span class="h-px w-8 bg-green-300" />
                        </div>
                        <StepList
                            v-if="step.branches"
                            :steps="step.branches.accepted"
                            :is-branch="true"
                            @steps-changed="updateBranch(idx, 'accepted', $event)" />
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="w-0.5 h-5 bg-border" v-if="idx > 0 || !isBranch" />
                <div
                    draggable="true"
                    @dragstart="onDragStart(idx, $event)"
                    @dragover="onDragOver(idx, $event)"
                    @dragleave="onDragLeave"
                    @drop="onDrop(idx)"
                    @dragend="onDragEnd"
                    @click="toggle(step.key)"
                    class="group relative flex items-center gap-3 rounded-xl border bg-white px-4 py-3 cursor-pointer select-none transition-all hover:shadow-md"
                    :class="selectedKey === step.key ? 'ring-2 shadow-md' : 'hover:border-opacity-80'"
                    :style="{
                        borderColor: selectedKey === step.key ? actionMeta(step.value).accent : actionMeta(step.value).border,
                        '--ring-color': actionMeta(step.value).accent + '40',
                        borderLeftWidth: '4px',
                        borderLeftColor: actionMeta(step.value).accent,
                        minWidth: '220px',
                    }">
                    <GripVertical class="h-4 w-4 text-muted-foreground/30 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity cursor-grab active:cursor-grabbing" />
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-base"
                        :style="{ background: actionMeta(step.value).light }">
                        <CampaignActionIcon :value="step.value" :size="16" :style="{ color: actionMeta(step.value).accent }" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-semibold text-foreground">{{ step.label }}</div>
                        <div v-if="step.config?.message" class="text-[10px] text-muted-foreground truncate max-w-[130px]">
                            {{ step.config.message as string || 'No message set' }}
                        </div>
                        <div v-else-if="step.value === 'endorse'" class="text-[10px] text-muted-foreground">
                            {{ step.config?.skills ?? 3 }} skills
                        </div>
                    </div>
                    <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button @click.stop="toggle(step.key)" class="rounded-lg px-1.5 py-1 text-[10px] text-muted-foreground hover:bg-muted">
                            <component :is="selectedKey === step.key ? ChevronUp : ChevronDown" class="h-3 w-3" />
                        </button>
                        <button @click.stop="remove(idx)" class="rounded-lg p-1 text-muted-foreground hover:bg-red-50 hover:text-red-500">
                            <Trash2 class="h-3 w-3" />
                        </button>
                    </div>
                </div>

                <div v-if="selectedKey === step.key"
                    class="w-full rounded-xl border bg-white p-4 shadow-sm flex flex-col gap-3"
                    :style="{ borderColor: actionMeta(step.value).border, minWidth: '220px' }">
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide">Action type</label>
                        <select :value="step.value"
                            @change="updateField(idx, 'value', ($event.target as HTMLSelectElement).value)"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400/20">
                            <option v-for="a in ACTIONS" :key="a.value" :value="a.value">{{ a.label }}</option>
                        </select>
                    </div>
                    <template v-if="step.value === 'message' || step.value === 'send-invite'">
                        <div class="flex flex-col gap-1">
                            <label class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide">
                                {{ step.value === 'send-invite' ? 'Invite note (optional)' : 'Message text' }}
                            </label>
                            <textarea
                                :value="(step.config?.message as string) ?? ''"
                                @input="updateConfig(idx, 'message', ($event.target as HTMLTextAreaElement).value)"
                                rows="3"
                                :placeholder="step.value === 'send-invite'
                                    ? 'Leave blank to send without a note, or write your own…'
                                    : 'Use {{firstName}}, {{lastName}}, {{company}}, {{position}}'"
                                class="w-full min-h-[6rem] rounded-lg border border-border bg-background px-3 py-2 text-xs outline-none focus:ring-1 focus:ring-blue-400/30 resize-y" />
                            <InviteNoteLimitHint v-if="step.value === 'send-invite'" variant="invite" />
                            <InviteNoteLimitHint v-else-if="step.value === 'message'" variant="action" />
                            <p class="text-[10px] text-muted-foreground" v-pre>Variables: <code class="bg-muted px-0.5 rounded">{{firstName}}</code> <code class="bg-muted px-0.5 rounded">{{company}}</code> <code class="bg-muted px-0.5 rounded">{{position}}</code></p>
                        </div>
                    </template>
                    <template v-if="step.value === 'endorse'">
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center gap-3">
                                <label class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide">Skills to endorse</label>
                                <input type="number" :value="(step.config?.skills as number) ?? 3" min="1" max="10"
                                    @input="updateConfig(idx, 'skills', +($event.target as HTMLInputElement).value)"
                                    class="w-16 rounded-lg border border-border bg-background px-2 py-1 text-sm text-center" />
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
                </div>
            </template>

            <template v-if="step.type !== 'end'">
                <div class="w-0.5 h-3 bg-border" />
                <div class="relative">
                    <button @click="openAddMenu(idx)"
                        class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-border bg-white text-muted-foreground hover:border-blue-400 hover:text-blue-500 hover:bg-blue-50 transition-all shadow-sm">
                        <Plus class="h-3 w-3" />
                    </button>
                    <div v-if="addMenuAfter === idx"
                        class="absolute left-8 top-0 z-50 rounded-xl border border-border bg-white shadow-lg p-2 flex flex-col gap-1"
                        style="min-width:180px">
                        <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide px-2 pb-1">Add step after</p>
                        <button v-for="a in ACTIONS" :key="a.value"
                            @click="pickAction(idx, a.value)"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs hover:bg-muted transition-colors text-left">
                            <span class="inline-flex h-5 w-5 items-center justify-center" :style="{ color: a.accent }">
                                <CampaignActionIcon :value="a.value" :size="14" />
                            </span> {{ a.label }}
                        </button>
                        <div class="border-t border-border my-1" />
                        <button @click="pickDelay(idx)"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs hover:bg-amber-50 text-amber-700 transition-colors text-left">
                            <Clock class="h-3.5 w-3.5 shrink-0" />
                            Add Wait / Delay
                        </button>
                    </div>
                </div>
                <div class="w-0.5 h-3 bg-border" />
            </template>

        </template>
    </div>
</template>
