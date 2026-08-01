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
import { Clock, GitBranch, ChevronDown, ChevronRight, Layers, Plus, X } from '@lucide/vue';
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
const showStepLibrary = ref(false);

watch(selectedKey, (key) => {
    if (key !== null) {
        showStepLibrary.value = false;
    }
});

const selectedStep = computed(() =>
    selectedKey.value === null ? null : findStepByKey(props.steps, selectedKey.value),
);

const connectedList = computed(() =>
    props.connectedChannels.filter((c) => c.connected && channelStepCount(c.channel) > 0),
);

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
    return expandedChannels.value[channel] === true;
}

function channelColor(channel: string) {
    return props.channelRegistry.channels[channel]?.color ?? '#64748b';
}

function channelStepCount(channel: string) {
    const actions = props.channelRegistry.actions[channel]?.length ?? 0;
    const conditions = props.channelRegistry.conditions?.[channel]?.length ?? 0;
    return actions + conditions;
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
    <div class="relative h-full w-full">
        <div class="relative h-full min-h-0 w-full bg-[#eef2f7]">
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
                Selected — use the <strong>+</strong> on any node to add the next step, or open the <strong>Step library</strong> tab on the right.
            </div>

            <!-- Collapsed tab — opens step library drawer -->
            <button
                v-if="!showStepLibrary && !selectedStep"
                type="button"
                class="step-library-tab absolute right-0 top-1/2 z-20 flex -translate-y-1/2 flex-col items-center gap-1.5 rounded-l-xl border border-r-0 border-slate-200 bg-white px-2 py-3 text-[10px] font-semibold uppercase tracking-wide text-slate-600 shadow-[-4px_0_16px_rgba(15,23,42,0.08)] transition hover:bg-slate-50 hover:text-primary"
                title="Open step library"
                @click="showStepLibrary = true"
            >
                <Layers class="h-4 w-4" />
                <span class="[writing-mode:vertical-rl] rotate-180">Steps</span>
            </button>

            <!-- Step library drawer overlay -->
            <Transition name="step-library-backdrop">
                <div
                    v-if="showStepLibrary"
                    class="absolute inset-0 z-30"
                    @click.self="showStepLibrary = false"
                >
                    <div class="absolute inset-0 bg-slate-900/20 backdrop-blur-[1px]" />

                    <Transition name="step-library-panel">
                        <aside
                            v-if="showStepLibrary"
                            class="absolute right-0 top-0 flex h-full w-[min(100%,18rem)] flex-col overflow-hidden border-l border-slate-200 bg-white shadow-[-8px_0_32px_rgba(15,23,42,0.12)]"
                        >
                            <div class="shrink-0 border-b border-slate-200 bg-white px-4 py-3">
                                <div class="flex items-start gap-2">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <Layers class="h-4 w-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h2 class="text-sm font-semibold text-slate-900">Step library</h2>
                                        <p class="mt-0.5 text-[11px] leading-relaxed text-muted-foreground">
                                            Expand a platform, then <strong class="font-medium text-slate-700">click an action</strong> to add it
                                            {{ selectedKey !== null ? ' after the selected step' : ' to your sequence' }}.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-800"
                                        title="Close step library"
                                        @click="showStepLibrary = false"
                                    >
                                        <X class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>

                            <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50/80 p-3">
                                <p v-if="connectedList.length === 0" class="rounded-lg border border-dashed border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                                    Connect channels on Integrations first.
                                </p>

                                <div v-for="ch in connectedList" :key="ch.channel" class="mb-2">
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 rounded-xl border px-2.5 py-2.5 text-left transition"
                                        :class="
                                            isChannelExpanded(ch.channel)
                                                ? 'border-slate-300 bg-white shadow-sm'
                                                : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-sm'
                                        "
                                        :aria-expanded="isChannelExpanded(ch.channel)"
                                        @click="toggleChannel(ch.channel)"
                                    >
                                        <OutreachChannelIcon :channel="ch.channel" class="h-4 w-4 shrink-0" />
                                        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800">{{ ch.label }}</span>
                                        <span
                                            v-if="!isChannelExpanded(ch.channel)"
                                            class="shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-slate-500"
                                        >
                                            {{ channelStepCount(ch.channel) }}
                                        </span>
                                        <span
                                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600 transition"
                                            :class="isChannelExpanded(ch.channel) ? 'border-slate-300 bg-slate-100' : ''"
                                        >
                                            <ChevronDown v-if="isChannelExpanded(ch.channel)" class="h-4 w-4" />
                                            <ChevronRight v-else class="h-4 w-4" />
                                        </span>
                                    </button>

                                    <div
                                        v-if="isChannelExpanded(ch.channel)"
                                        class="platform-thread relative ml-4 mr-0.5 mt-1 space-y-1 pl-4"
                                        :style="{ '--thread-color': channelColor(ch.channel) }"
                                    >
                                        <p class="mb-1.5 px-1 text-[10px] font-medium uppercase tracking-wide text-slate-400">Actions — click to add</p>
                                        <button
                                            v-for="action in channelRegistry.actions[ch.channel] ?? []"
                                            :key="action.key"
                                            type="button"
                                            class="platform-thread-item group flex w-full items-center gap-2 rounded-lg border border-slate-200/80 bg-white px-2.5 py-2 text-left text-xs font-medium text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:shadow"
                                            @click="addAction(ch.channel, action.key, action.label)"
                                        >
                                            <span
                                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md transition group-hover:scale-105"
                                                :style="{ background: `${channelColor(ch.channel)}18`, color: channelColor(ch.channel) }"
                                            >
                                                <Plus class="h-3 w-3" />
                                            </span>
                                            <span
                                                class="h-2 w-2 shrink-0 rounded-full ring-2 ring-white"
                                                :style="{ background: channelColor(ch.channel) }"
                                            />
                                            <span class="min-w-0 flex-1 truncate">{{ action.label }}</span>
                                        </button>

                                        <template v-if="(channelRegistry.conditions?.[ch.channel] ?? []).length">
                                            <p class="mb-1 mt-2 px-1 text-[10px] font-semibold uppercase tracking-wide text-orange-500/90">Conditions</p>
                                            <button
                                                v-for="cond in channelRegistry.conditions?.[ch.channel] ?? []"
                                                :key="cond.key"
                                                type="button"
                                                class="platform-thread-item group flex w-full items-center gap-2 rounded-lg border border-orange-200/70 bg-orange-50/50 px-2.5 py-2 text-left text-xs font-medium text-orange-900 shadow-sm transition hover:border-orange-300 hover:bg-orange-50 hover:shadow"
                                                @click="addCondition(ch.channel, cond.key, cond.label)"
                                            >
                                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-orange-100 text-orange-600 transition group-hover:scale-105">
                                                    <Plus class="h-3 w-3" />
                                                </span>
                                                <GitBranch class="h-3.5 w-3.5 shrink-0 text-orange-500" />
                                                <span class="min-w-0 flex-1 truncate">{{ cond.label }}</span>
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                <div class="mt-3 border-t border-slate-200 pt-3">
                                    <p class="mb-2 px-1 text-[10px] font-medium uppercase tracking-wide text-slate-400">Timing</p>
                                    <button
                                        type="button"
                                        class="group flex w-full items-center gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-left text-xs font-medium text-amber-950 shadow-sm transition hover:border-amber-300 hover:bg-amber-100 hover:shadow"
                                        @click="addDelay"
                                    >
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 transition group-hover:scale-105">
                                            <Plus class="h-3.5 w-3.5" />
                                        </span>
                                        <Clock class="h-4 w-4 shrink-0 text-amber-600" />
                                        <span>Wait / Delay</span>
                                    </button>
                                </div>
                            </div>

                            <div class="shrink-0 border-t border-slate-200 bg-white p-3">
                                <p class="text-[10px] leading-relaxed text-muted-foreground">
                                    Or use <strong class="text-slate-600">+</strong> on any canvas node. Condition nodes have <strong class="text-slate-600">+</strong> on Yes / No for branches.
                                </p>
                            </div>
                        </aside>
                    </Transition>
                </div>
            </Transition>

            <!-- Step config floating drawer — opens when a node is selected -->
            <Transition name="step-config-panel">
                <aside
                    v-if="selectedStep"
                    class="step-config-panel absolute right-0 top-0 z-40 flex h-full w-[min(100%,20rem)] flex-col overflow-hidden border-l border-slate-200 bg-white shadow-[-8px_0_32px_rgba(15,23,42,0.14)]"
                >
                    <div class="flex shrink-0 items-center justify-end border-b border-slate-100 px-3 py-2">
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-800"
                            title="Close step settings"
                            @click="selectedKey = null"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto p-4 pt-2">
                        <OutreachStepConfigPanel
                            :step="selectedStep"
                            :channel-registry="channelRegistry"
                            @update-field="onUpdateField"
                            @update-config="onUpdateConfig"
                            @delete="selectedKey !== null && onDelete(selectedKey)"
                        />
                    </div>
                </aside>
            </Transition>
        </div>
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

/* Keep the node with an open + menu above siblings (backup if Teleport is unavailable) */
.outreach-flow-canvas :deep(.vue-flow__node:has(.outreach-add-menu-open)) {
    z-index: 1000 !important;
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

/* Vertical thread line connecting platform actions */
.platform-thread::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.25rem;
    bottom: 0.25rem;
    width: 3px;
    border-radius: 9999px;
    background: var(--thread-color, #94a3b8);
    opacity: 0.85;
}

.platform-thread-item {
    position: relative;
}

.platform-thread-item::before {
    content: '';
    position: absolute;
    left: -1rem;
    top: 50%;
    width: 0.65rem;
    height: 3px;
    margin-top: -1.5px;
    border-radius: 0 2px 2px 0;
    background: var(--thread-color, #94a3b8);
    opacity: 0.7;
}

.step-library-backdrop-enter-active,
.step-library-backdrop-leave-active {
    transition: opacity 0.2s ease;
}

.step-library-backdrop-enter-from,
.step-library-backdrop-leave-to {
    opacity: 0;
}

.step-library-panel-enter-active,
.step-library-panel-leave-active {
    transition: transform 0.25s ease;
}

.step-library-panel-enter-from,
.step-library-panel-leave-to {
    transform: translateX(100%);
}

.step-config-panel-enter-active,
.step-config-panel-leave-active {
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.step-config-panel-enter-from,
.step-config-panel-leave-to {
    transform: translateX(100%);
}
</style>
