<script setup lang="ts">
import { computed, markRaw, nextTick, onUnmounted, provide, ref, watch } from 'vue';
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
import { Plus, Clock, GitBranch, Map, X } from '@lucide/vue';
import CampaignActionIcon from '@/components/campaign/CampaignActionIcon.vue';
import { nodeModelToFlow, type CampaignFlowNodeData } from '@/components/campaign/flowAdapter';
import {
    findStepByKey,
    insertStepAfter,
    insertConditionAfter,
    insertIntoBranch,
    insertDelayIntoBranch,
    disconnectStep,
    nextStepKey,
    removeStep,
    conditionBranchStepCount,
    updateStepConfig,
    updateStepField,
} from '@/components/campaign/stepMutations';
import { CAMPAIGN_ACTIONS, CAMPAIGN_CONDITIONS, START_NODE_ID, type CampaignStep } from '@/components/campaign/types';
import FlowDeletableEdge from '@/components/flow/FlowDeletableEdge.vue';
import { FLOW_CANVAS_DOT_BG } from '@/components/flow/flowCanvasConfig';
import '@/components/flow/flow-canvas-dots.css';
import { useFlowMinimapVisibility } from '@/composables/useFlowMinimapVisibility';
import CampaignActionNode from '@/components/campaign/nodes/CampaignActionNode.vue';
import CampaignConditionNode from '@/components/campaign/nodes/CampaignConditionNode.vue';
import CampaignDelayNode from '@/components/campaign/nodes/CampaignDelayNode.vue';
import CampaignEndNode from '@/components/campaign/nodes/CampaignEndNode.vue';
import CampaignStartNode from '@/components/campaign/nodes/CampaignStartNode.vue';
import StepConfigPanel from '@/components/campaign/StepConfigPanel.vue';

const props = defineProps<{ steps: CampaignStep[] }>();
const emit = defineEmits<{ stepsChanged: [steps: CampaignStep[]] }>();

const { showMinimap, setMinimapVisible, toggleMinimap } = useFlowMinimapVisibility(
    'socifusion_campaign_flow_minimap',
);

const nodeTypes = {
    campaignStart: markRaw(CampaignStartNode),
    campaignAction: markRaw(CampaignActionNode),
    campaignDelay: markRaw(CampaignDelayNode),
    campaignCondition: markRaw(CampaignConditionNode),
    campaignEnd: markRaw(CampaignEndNode),
} as NodeTypesObject;

const edgeTypes = {
    deletable: markRaw(FlowDeletableEdge),
};

const nodes = ref<Node[]>([]);
const edges = ref<Edge[]>([]);
const selectedKey = ref<number | null>(null);
const showAddMenu = ref(false);
const openAddMenuId = ref<string | null>(null);
const addStepTriggerRef = ref<HTMLElement | null>(null);
const addMenuAnchor = ref({ top: 0, left: 0, width: 0 });

const addMenuStyle = computed(() => ({
    top: `${addMenuAnchor.value.top - 8}px`,
    left: `${addMenuAnchor.value.left}px`,
    transform: 'translateY(-100%)',
}));

function syncAddMenuPosition() {
    const el = addStepTriggerRef.value;
    if (!el) {
        return;
    }

    const rect = el.getBoundingClientRect();
    addMenuAnchor.value = {
        top: rect.top,
        left: rect.left,
        width: rect.width,
    };
}

watch(showAddMenu, async (open) => {
    if (open) {
        await nextTick();
        syncAddMenuPosition();
        window.addEventListener('resize', syncAddMenuPosition);
        window.addEventListener('scroll', syncAddMenuPosition, true);
    } else {
        window.removeEventListener('resize', syncAddMenuPosition);
        window.removeEventListener('scroll', syncAddMenuPosition, true);
    }
});

onUnmounted(() => {
    window.removeEventListener('resize', syncAddMenuPosition);
    window.removeEventListener('scroll', syncAddMenuPosition, true);
});

const selectedStep = computed(() =>
    selectedKey.value === null ? null : findStepByKey(props.steps, selectedKey.value),
);

function syncFromSteps() {
    const posById = Object.fromEntries(nodes.value.map((n) => [n.id, n.position]));
    const graph = nodeModelToFlow(props.steps);
    nodes.value = graph.nodes.map((node) => {
        const data = node.data as CampaignFlowNodeData | undefined;
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

function pushSteps(next: CampaignStep[]) {
    emit('stepsChanged', next);
}

function onSelect(key: number) {
    selectedKey.value = selectedKey.value === key ? null : key;
    showAddMenu.value = false;
}

function onDelete(key: number) {
    const step = findStepByKey(props.steps, key);
    if (step?.type === 'condition' && conditionBranchStepCount(step) > 0) {
        if (!window.confirm('Remove this condition and all Yes/No steps?')) {
            return;
        }
    }

    const next = removeStep(props.steps, key);
    if (next !== props.steps) {
        if (selectedKey.value === key) selectedKey.value = null;
        pushSteps(next);
    }
}

provide('campaignAddMenu', {
    openId: openAddMenuId,
    toggle: (id: string) => { openAddMenuId.value = openAddMenuId.value === id ? null : id; },
    close: () => { openAddMenuId.value = null; },
});

provide('campaignAddContext', {
    addActionAfter: (afterKey: number, value: string) => {
        const newKey = nextStepKey(props.steps);
        pushSteps(insertStepAfter(props.steps, afterKey, 'action', value));
        selectedKey.value = newKey;
        openAddMenuId.value = null;
    },
    addDelayAfter: (afterKey: number) => {
        const newKey = nextStepKey(props.steps);
        pushSteps(insertStepAfter(props.steps, afterKey, 'delay'));
        selectedKey.value = newKey;
        openAddMenuId.value = null;
    },
    addConditionAfter: (afterKey: number, value: string, label: string) => {
        const newKey = nextStepKey(props.steps);
        pushSteps(insertConditionAfter(props.steps, afterKey, value, label));
        selectedKey.value = newKey;
        openAddMenuId.value = null;
    },
    addIntoBranch: (conditionKey: number, branch: 'accepted' | 'not_accepted', type: 'action' | 'delay', value = 'message') => {
        pushSteps(insertIntoBranch(props.steps, conditionKey, branch, type, value));
        openAddMenuId.value = null;
    },
    addDelayIntoBranch: (conditionKey: number, branch: 'accepted' | 'not_accepted') => {
        pushSteps(insertDelayIntoBranch(props.steps, conditionKey, branch));
        openAddMenuId.value = null;
    },
});

provide('campaignFlowActions', {
    select: onSelect,
    delete: onDelete,
});

provide('flowDisconnectEdge', (targetKey: number) => {
    const step = findStepByKey(props.steps, targetKey);
    if (step?.type === 'condition' && conditionBranchStepCount(step) > 0) {
        if (!window.confirm('Remove this condition and all Yes/No steps?')) {
            return;
        }
    }
    const next = disconnectStep(props.steps, targetKey);
    if (next !== props.steps) {
        if (selectedKey.value === targetKey) selectedKey.value = null;
        pushSteps(next);
    }
});

function onNodeClick(event: NodeMouseEvent) {
    const step = (event.node.data as CampaignFlowNodeData)?.step;
    if (!step || event.node.id === START_NODE_ID || step.type === 'end') {
        selectedKey.value = null;
        return;
    }
    onSelect(step.key);
}

function onPaneClick() {
    selectedKey.value = null;
    showAddMenu.value = false;
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

function onAddAfter(type: 'action' | 'delay', value?: string) {
    const afterKey = selectedKey.value ?? findLastInsertKey();
    const newKey = nextStepKey(props.steps);
    pushSteps(insertStepAfter(props.steps, afterKey, type, value ?? 'message'));
    selectedKey.value = newKey;
    showAddMenu.value = false;
}

function addFromToolbar(type: 'action' | 'delay', value?: string) {
    onAddAfter(type, value);
}

function addConditionFromToolbar(value: string, label: string) {
    const afterKey = selectedKey.value ?? findLastInsertKey();
    const newKey = nextStepKey(props.steps);
    pushSteps(insertConditionAfter(props.steps, afterKey, value, label));
    selectedKey.value = newKey;
    showAddMenu.value = false;
}

function minimapNodeColor(node: Node): string {
    const type = (node.data as CampaignFlowNodeData | undefined)?.step?.type;
    if (type === 'delay') return '#fbbf24';
    if (type === 'condition') return '#fb923c';
    if (type === 'end') return '#94a3b8';
    return '#3b82f6';
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
                fit-view-on-init
                class="campaign-flow-canvas flow-canvas-dots h-full w-full"
                @node-click="onNodeClick"
                @pane-click="onPaneClick"
                @node-drag-stop="onNodeDragStop"
            >
                <Background
                    :variant="FLOW_CANVAS_DOT_BG.variant"
                    :gap="FLOW_CANVAS_DOT_BG.gap"
                    :size="FLOW_CANVAS_DOT_BG.size"
                    :offset="FLOW_CANVAS_DOT_BG.offset"
                    :pattern-color="FLOW_CANVAS_DOT_BG.patternColor"
                />
                <Controls :position="Position.BottomLeft" :show-zoom="true" :show-fit-view="true" :show-interactive="true" />

                <div v-if="showMinimap" class="campaign-flow-minimap-wrap">
                    <div class="flex items-center justify-between gap-2">
                        <p class="campaign-flow-minimap-label">Overview</p>
                        <button
                            type="button"
                            class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                            title="Hide overview"
                            @click.stop="setMinimapVisible(false)"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>
                    <MiniMap
                        :node-color="minimapNodeColor"
                        :mask-color="'rgba(255, 255, 255, 0.65)'"
                        pannable
                        zoomable
                    />
                </div>
            </VueFlow>

            <button
                v-if="!showMinimap"
                type="button"
                class="absolute bottom-4 right-4 z-10 inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-600 shadow-sm transition hover:bg-slate-50 hover:text-slate-900"
                title="Show overview map"
                @click="toggleMinimap"
            >
                <Map class="h-3.5 w-3.5" />
                Overview
            </button>

            <div class="absolute bottom-4 left-4 z-10 flex flex-col items-start gap-3">
                <div ref="addStepTriggerRef" class="relative">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-[0_10px_24px_rgba(15,23,42,0.18)] transition hover:bg-slate-800"
                        @click="showAddMenu = !showAddMenu"
                    >
                        <Plus class="h-4 w-4" />
                        Add step
                    </button>
                    <Teleport to="body">
                        <div
                            v-if="showAddMenu"
                            class="campaign-add-step-menu fixed z-[10050] min-w-[240px] rounded-2xl border border-slate-200 bg-white p-2 shadow-[0_16px_40px_rgba(15,23,42,0.18)]"
                            :style="addMenuStyle"
                            @click.stop
                        >
                            <p class="px-2 pb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                                Insert step
                            </p>
                            <button
                                v-for="action in CAMPAIGN_ACTIONS"
                                :key="action.value"
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left text-xs transition hover:bg-slate-50"
                                @click="addFromToolbar('action', action.value)"
                            >
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-lg border"
                                    :style="{ background: action.light, borderColor: action.border, color: action.accent }"
                                >
                                    <CampaignActionIcon :value="action.value" :size="15" />
                                </span>
                                <span class="font-medium text-slate-700">{{ action.label }}</span>
                            </button>
                            <div class="my-1.5 border-t border-slate-100" />
                            <p class="px-2 pb-1 text-[10px] font-semibold uppercase tracking-wide text-orange-500/90">
                                Conditions · Yes / No paths
                            </p>
                            <button
                                v-for="cond in CAMPAIGN_CONDITIONS"
                                :key="cond.value"
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl border border-orange-200/80 bg-orange-50/60 px-2.5 py-2.5 text-left text-xs transition hover:border-orange-300 hover:bg-orange-50"
                                @click="addConditionFromToolbar(cond.value, cond.label)"
                            >
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg border border-orange-200 bg-white text-orange-600">
                                    <GitBranch class="h-4 w-4" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-orange-950">{{ cond.label }}</span>
                                    <span class="block text-[10px] font-normal text-orange-800/80">Branches to Yes (accepted) or No</span>
                                </span>
                            </button>
                            <div class="my-1.5 border-t border-slate-100" />
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left text-xs transition hover:bg-amber-50"
                                @click="addFromToolbar('delay')"
                            >
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg border border-amber-200 bg-amber-50 text-amber-600">
                                    <Clock class="h-4 w-4" />
                                </span>
                                <span class="font-medium text-amber-900">Add Wait / Delay</span>
                            </button>
                        </div>
                    </Teleport>
                </div>
            </div>
        </div>

        <aside
            v-if="selectedStep"
            class="w-80 shrink-0 overflow-y-auto border-l border-slate-200 bg-white p-4 shadow-[inset_1px_0_0_rgba(15,23,42,0.04)]"
        >
            <StepConfigPanel
                :step="selectedStep"
                :steps="steps"
                @update-field="onUpdateField"
                @update-config="onUpdateConfig"
                @delete="selectedKey !== null && onDelete(selectedKey)"
                @add-after="onAddAfter"
                @add-condition="(value, label) => addConditionFromToolbar(value, label)"
            />
        </aside>
    </div>
</template>

<style scoped>
.campaign-flow-canvas :deep(.vue-flow__background) {
    background-color: #eef2f7;
}

.campaign-flow-canvas :deep(.vue-flow__pane) {
    background-color: transparent;
}

.campaign-flow-canvas :deep(.vue-flow__edge-path) {
    stroke-linecap: round;
}

.campaign-flow-canvas :deep(.vue-flow__node) {
    font-family: inherit;
}

.campaign-flow-canvas :deep(.vue-flow__handle) {
    width: 9px;
    height: 9px;
    border: 2px solid white;
    box-shadow: 0 0 0 1px rgba(148, 163, 184, 0.45);
}

.campaign-flow-canvas :deep(.vue-flow__controls) {
    left: 16px;
    bottom: 72px;
    display: flex;
    flex-direction: column;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    background: #ffffff;
}

.campaign-flow-canvas :deep(.vue-flow__controls-button) {
    width: 32px;
    height: 32px;
    padding: 6px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
}

.campaign-flow-canvas :deep(.vue-flow__controls-button:last-child) {
    border-bottom: none;
}

.campaign-flow-canvas :deep(.vue-flow__controls-button:hover) {
    background: #f8fafc;
}

.campaign-flow-minimap-wrap {
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

.campaign-flow-minimap-label {
    margin: 0;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
}

.campaign-flow-canvas :deep(.campaign-flow-minimap-wrap .vue-flow__minimap) {
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
