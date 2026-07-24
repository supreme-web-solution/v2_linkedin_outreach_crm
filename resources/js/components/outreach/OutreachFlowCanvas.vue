<script setup lang="ts">
import { computed, markRaw, provide, ref, watch } from 'vue';
import {
    VueFlow,
    Position,
    type Edge,
    type Node,
    type NodeDragEvent,
    type NodeMouseEvent,
    type NodeTypesObject,
} from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';
import { Clock, GitBranch, ChevronDown, ChevronRight } from '@lucide/vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import { nodeModelToFlow, type OutreachFlowNodeData } from '@/components/outreach/outreachFlowAdapter';
import {
    findStepByKey,
    insertActionAfter,
    insertConditionAfter,
    insertDelayAfter,
    insertDelayIntoBranch,
    insertIntoBranch,
    disconnectStep,
    nextStepKey,
    removeStep,
    updateStepConfig,
    updateStepField,
} from '@/components/outreach/outreachStepMutations';
import { START_NODE_ID, type ConnectedChannel, type OutreachChannel, type OutreachStep } from '@/components/outreach/types';
import FlowDeletableEdge from '@/components/flow/FlowDeletableEdge.vue';
import FlowCanvasFitView from '@/components/flow/FlowCanvasFitView.vue';
import { FLOW_CANVAS_DOT_BG } from '@/components/flow/flowCanvasConfig';
import '@/components/flow/flow-canvas-dots.css';
import OutreachActionNode from '@/components/outreach/nodes/OutreachActionNode.vue';
import OutreachConditionNode from '@/components/outreach/nodes/OutreachConditionNode.vue';
import OutreachDelayNode from '@/components/outreach/nodes/OutreachDelayNode.vue';
import OutreachEndNode from '@/components/outreach/nodes/OutreachEndNode.vue';
import OutreachStartNode from '@/components/outreach/nodes/OutreachStartNode.vue';
import OutreachStepConfigPanel from '@/components/outreach/OutreachStepConfigPanel.vue';

const props = defineProps<{
    steps: OutreachStep[];
    channelRegistry: {
        channels: Record<string, { label: string; color: string }>;
        actions: Record<string, Array<{ key: string; label: string }>>;
        conditions?: Record<string, Array<{ key: string; label: string }>>;
    };
    connectedChannels: ConnectedChannel[];
}>();

const emit = defineEmits<{ stepsChanged: [steps: OutreachStep[]] }>();

const nodeTypes = {
    outreachStart: markRaw(OutreachStartNode),
    outreachAction: markRaw(OutreachActionNode),
    outreachDelay: markRaw(OutreachDelayNode),
    outreachCondition: markRaw(OutreachConditionNode),
    outreachEnd: markRaw(OutreachEndNode),
} as NodeTypesObject;

const edgeTypes = {
    deletable: markRaw(FlowDeletableEdge),
};

const nodes = ref<Node[]>([]);
const edges = ref<Edge[]>([]);
const selectedKey = ref<number | null>(null);
const expandedChannels = ref<Record<string, boolean>>({});
const openAddMenuId = ref<string | null>(null);

const selectedStep = computed(() =>
    selectedKey.value === null ? null : findStepByKey(props.steps, selectedKey.value),
);

const connectedList = computed(() => props.connectedChannels.filter((c) => c.connected));

function syncFromSteps() {
    const posById = Object.fromEntries(nodes.value.map((n) => [n.id, n.position]));
    const graph = nodeModelToFlow(props.steps);
    nodes.value = graph.nodes.map((node) => {
        const data = node.data as OutreachFlowNodeData | undefined;
        return {
            ...node,
            position: posById[node.id] ?? node.position,
            selected: selectedKey.value === data?.step?.key,
        };
    });
    edges.value = graph.edges;
}

watch(() => props.steps, syncFromSteps, { immediate: true, deep: true });
watch(selectedKey, syncFromSteps);

function pushSteps(next: OutreachStep[]) {
    emit('stepsChanged', next);
}

function onSelect(key: number) {
    selectedKey.value = selectedKey.value === key ? null : key;
}

function onDelete(key: number) {
    const next = removeStep(props.steps, key);
    if (next !== props.steps) {
        if (selectedKey.value === key) selectedKey.value = null;
        pushSteps(next);
    }
}

provide('outreachFlowActions', { select: onSelect, delete: onDelete });
provide('outreachChannelRegistry', props.channelRegistry);
provide('flowDisconnectEdge', (targetKey: number) => {
    const next = disconnectStep(props.steps, targetKey);
    if (next !== props.steps) {
        if (selectedKey.value === targetKey) selectedKey.value = null;
        pushSteps(next);
    }
});

provide('outreachAddMenu', {
    openId: openAddMenuId,
    toggle: (id: string) => { openAddMenuId.value = openAddMenuId.value === id ? null : id; },
    close: () => { openAddMenuId.value = null; },
});

provide('outreachAddContext', {
    connectedChannels: connectedList,
    channelRegistry: props.channelRegistry,
    addActionAfter: (afterKey: number, channel: OutreachChannel, actionKey: string, label: string) => {
        const newKey = nextStepKey(props.steps);
        pushSteps(insertActionAfter(props.steps, afterKey, channel, actionKey, label));
        selectedKey.value = newKey;
        openAddMenuId.value = null;
    },
    addDelayAfter: (afterKey: number) => {
        const newKey = nextStepKey(props.steps);
        pushSteps(insertDelayAfter(props.steps, afterKey));
        selectedKey.value = newKey;
        openAddMenuId.value = null;
    },
    addConditionAfter: (afterKey: number, channel: OutreachChannel, conditionKey: string, label: string) => {
        const newKey = nextStepKey(props.steps);
        pushSteps(insertConditionAfter(props.steps, afterKey, channel, conditionKey, label));
        selectedKey.value = newKey;
        openAddMenuId.value = null;
    },
    addIntoBranch: (conditionKey: number, branch: 'accepted' | 'not_accepted', channel: OutreachChannel, actionKey: string, label: string) => {
        pushSteps(insertIntoBranch(props.steps, conditionKey, branch, channel, actionKey, label));
        openAddMenuId.value = null;
    },
    addDelayIntoBranch: (conditionKey: number, branch: 'accepted' | 'not_accepted') => {
        pushSteps(insertDelayIntoBranch(props.steps, conditionKey, branch));
        openAddMenuId.value = null;
    },
});

function onNodeClick(event: NodeMouseEvent) {
    const step = (event.node.data as OutreachFlowNodeData)?.step;
    if (!step || event.node.id === START_NODE_ID || step.type === 'end') {
        selectedKey.value = null;
        return;
    }
    onSelect(step.key);
}

function onPaneClick() {
    selectedKey.value = null;
    openAddMenuId.value = null;
}

function onNodeDragStop(event: NodeDragEvent) {
    const idx = nodes.value.findIndex((n) => n.id === event.node.id);
    if (idx >= 0) {
        nodes.value[idx] = { ...nodes.value[idx], position: { ...event.node.position } };
    }
}

function onUpdateField(field: string, value: unknown) {
    if (selectedKey.value === null) return;
    pushSteps(updateStepField(props.steps, selectedKey.value, field, value));
}

function onUpdateConfig(key: string, value: unknown) {
    if (selectedKey.value === null) return;
    pushSteps(updateStepConfig(props.steps, selectedKey.value, key, value));
}

function findLastInsertKey(): number {
    const nonEnd = props.steps.filter((step) => step.type !== 'end');
    if (nonEnd.length === 0) return -1;
    return nonEnd[nonEnd.length - 1].key;
}

function insertAfterKey(): number {
    return selectedKey.value ?? findLastInsertKey();
}

function addAction(channel: OutreachChannel, actionKey: string, actionLabel: string) {
    const afterKey = insertAfterKey();
    const newKey = nextStepKey(props.steps);
    pushSteps(insertActionAfter(props.steps, afterKey, channel, actionKey, actionLabel));
    selectedKey.value = newKey;
}

function addDelay() {
    const afterKey = insertAfterKey();
    const newKey = nextStepKey(props.steps);
    pushSteps(insertDelayAfter(props.steps, afterKey));
    selectedKey.value = newKey;
}

function addCondition(channel: OutreachChannel, conditionKey: string, conditionLabel: string) {
    const afterKey = insertAfterKey();
    const newKey = nextStepKey(props.steps);
    pushSteps(insertConditionAfter(props.steps, afterKey, channel, conditionKey, conditionLabel));
    selectedKey.value = newKey;
}

function toggleChannel(channel: string) {
    expandedChannels.value[channel] = !expandedChannels.value[channel];
}

function isChannelExpanded(channel: string) {
    return expandedChannels.value[channel] !== false;
}

function minimapNodeColor(node: Node): string {
    const step = (node.data as OutreachFlowNodeData | undefined)?.step;
    if (step?.type === 'delay') return '#fbbf24';
    if (step?.type === 'condition') return '#fb923c';
    if (step?.type === 'end') return '#94a3b8';
    const ch = step?.channel;
    return ch ? (props.channelRegistry.channels[ch]?.color ?? '#3b82f6') : '#3b82f6';
}
</script>

<template>
    <div class="relative flex h-full w-full">
        <div class="relative min-h-0 min-w-0 flex-1 bg-[#eef2f7]">
            <VueFlow
                :nodes="nodes"
                :edges="edges"
                :node-types="nodeTypes"
                :edge-types="edgeTypes"
                :nodes-draggable="true"
                :nodes-connectable="false"
                :elements-selectable="true"
                :delete-key-code="null"
                :zoom-on-scroll="true"
                :pan-on-scroll="false"
                :min-zoom="0.25"
                :max-zoom="1.5"
                class="outreach-flow-canvas flow-canvas-dots h-full w-full"
                @node-click="onNodeClick"
                @pane-click="onPaneClick"
                @node-drag-stop="onNodeDragStop"
            >
                <FlowCanvasFitView />
                <Background
                    :variant="FLOW_CANVAS_DOT_BG.variant"
                    :gap="FLOW_CANVAS_DOT_BG.gap"
                    :size="FLOW_CANVAS_DOT_BG.size"
                    :offset="FLOW_CANVAS_DOT_BG.offset"
                    :pattern-color="FLOW_CANVAS_DOT_BG.patternColor"
                />
                <Controls :position="Position.BottomLeft" :show-zoom="true" :show-fit-view="true" :show-interactive="true" />

                <div class="outreach-flow-minimap-wrap">
                    <p class="outreach-flow-minimap-label">Overview</p>
                    <MiniMap
                        :node-color="minimapNodeColor"
                        :mask-color="'rgba(255, 255, 255, 0.65)'"
                        pannable
                        zoomable
                    />
                </div>
            </VueFlow>

            <div v-if="selectedKey !== null" class="absolute bottom-4 left-4 z-10 max-w-xs rounded-xl border border-slate-200 bg-white/95 px-3 py-2 text-[11px] text-slate-600 shadow-sm backdrop-blur">
                Selected — use the <strong>+</strong> on any node to add the next step, or pick from the Platforms panel.
            </div>
        </div>

        <aside
            v-if="selectedStep"
            class="w-80 shrink-0 overflow-y-auto border-l border-slate-200 bg-white p-4 shadow-[inset_1px_0_0_rgba(15,23,42,0.04)]"
        >
            <OutreachStepConfigPanel
                :step="selectedStep"
                :channel-registry="channelRegistry"
                @update-field="onUpdateField"
                @update-config="onUpdateConfig"
                @delete="selectedKey !== null && onDelete(selectedKey)"
            />
        </aside>

        <aside class="flex w-72 shrink-0 flex-col overflow-hidden border-l border-slate-200 bg-white shadow-[inset_1px_0_0_rgba(15,23,42,0.04)]">
            <div class="shrink-0 border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold">Platforms</h2>
                <p class="text-[11px] text-muted-foreground">
                    {{ selectedKey !== null ? 'Add step after selection' : 'Add step to sequence' }}
                </p>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-3">
                <p v-if="connectedList.length === 0" class="rounded-lg border border-dashed border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                    Connect channels on Integrations first.
                </p>

                <div v-for="ch in connectedList" :key="ch.channel" class="mb-2">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-xl px-2 py-2 text-left text-sm font-medium hover:bg-slate-50"
                        @click="toggleChannel(ch.channel)"
                    >
                        <OutreachChannelIcon :channel="ch.channel" class="h-4 w-4" />
                        <span class="flex-1">{{ ch.label }}</span>
                        <component :is="isChannelExpanded(ch.channel) ? ChevronDown : ChevronRight" class="h-3.5 w-3.5 text-slate-400" />
                    </button>

                    <div v-if="isChannelExpanded(ch.channel)" class="ml-2 space-y-0.5 border-l border-slate-100 pl-3">
                        <button
                            v-for="action in channelRegistry.actions[ch.channel] ?? []"
                            :key="action.key"
                            type="button"
                            class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs text-slate-700 transition hover:bg-slate-50"
                            @click="addAction(ch.channel, action.key, action.label)"
                        >
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :style="{ background: channelRegistry.channels[ch.channel]?.color ?? '#64748b' }"
                            />
                            {{ action.label }}
                        </button>

                        <template v-if="(channelRegistry.conditions?.[ch.channel] ?? []).length">
                            <p class="px-2 pt-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Conditions</p>
                            <button
                                v-for="cond in channelRegistry.conditions?.[ch.channel] ?? []"
                                :key="cond.key"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs text-orange-700 transition hover:bg-orange-50"
                                @click="addCondition(ch.channel, cond.key, cond.label)"
                            >
                                <GitBranch class="h-3 w-3" />
                                {{ cond.label }}
                            </button>
                        </template>
                    </div>
                </div>

                <div class="mt-3 border-t border-slate-100 pt-3">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-left text-xs font-medium text-amber-900 transition hover:bg-amber-100"
                        @click="addDelay"
                    >
                        <Clock class="h-4 w-4 text-amber-600" />
                        Wait / Delay
                    </button>
                </div>
            </div>

            <div class="shrink-0 border-t border-slate-200 p-3">
                <p class="text-[10px] leading-relaxed text-muted-foreground">
                    Click <strong>+</strong> on any node to add after it. Condition nodes also have <strong>+</strong> on Yes / No to build branches.
                </p>
            </div>
        </aside>
    </div>
</template>

<style scoped>
.outreach-flow-canvas :deep(.vue-flow__background) {
    background-color: #eef2f7;
}

.outreach-flow-canvas :deep(.vue-flow__pane) {
    background-color: transparent;
}

.outreach-flow-canvas :deep(.vue-flow__edge-path) {
    stroke-linecap: round;
}

.outreach-flow-canvas :deep(.vue-flow__node) {
    font-family: inherit;
}

.outreach-flow-canvas :deep(.vue-flow__handle) {
    width: 9px;
    height: 9px;
    border: 2px solid white;
    box-shadow: 0 0 0 1px rgba(148, 163, 184, 0.45);
}

.outreach-flow-canvas :deep(.vue-flow__controls) {
    left: 16px;
    bottom: 16px;
    display: flex;
    flex-direction: column;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    background: #ffffff;
}

.outreach-flow-canvas :deep(.vue-flow__controls-button) {
    width: 32px;
    height: 32px;
    padding: 6px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
}

.outreach-flow-canvas :deep(.vue-flow__controls-button:last-child) {
    border-bottom: none;
}

.outreach-flow-canvas :deep(.vue-flow__controls-button:hover) {
    background: #f8fafc;
}

.outreach-flow-minimap-wrap {
    position: absolute;
    right: 16px;
    bottom: 16px;
    z-index: 5;
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 10px 10px 8px;
    border: 1px solid #cbd5e1;
    border-radius: 1rem;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.14);
}

.outreach-flow-minimap-label {
    margin: 0;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
}

.outreach-flow-canvas :deep(.outreach-flow-minimap-wrap .vue-flow__minimap) {
    position: static !important;
    width: 180px !important;
    height: 120px !important;
    margin: 0 !important;
    border: 1px solid #e2e8f0;
    border-radius: 0.625rem;
    overflow: hidden;
    background: #ffffff;
}
</style>
