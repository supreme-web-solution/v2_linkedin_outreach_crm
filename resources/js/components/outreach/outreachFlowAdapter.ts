import type { Edge, Node } from '@vue-flow/core';
import { deletableEdge } from '@/components/flow/flowEdgeUtils';
import { START_NODE_ID, type OutreachStep } from '@/components/outreach/types';

const MAIN_X = 380;
const ACCEPTED_X = 80;
const NOT_ACCEPTED_X = 680;
const Y_GAP = 110;
const BRANCH_Y_GAP = 95;

export type OutreachFlowNodeData = {
    step: OutreachStep;
    branch?: 'accepted' | 'not_accepted';
    parentConditionKey?: number;
};

function nodeTypeForStep(step: OutreachStep): string {
    if (step.type === 'end') return 'outreachEnd';
    if (step.type === 'delay') return 'outreachDelay';
    if (step.type === 'condition') return 'outreachCondition';
    return 'outreachAction';
}

function mainSourceHandle(sourceId: string, nodes: Node[]): string | undefined {
    if (sourceId === START_NODE_ID) return undefined;

    const node = nodes.find((item) => item.id === sourceId);
    if (node?.type === 'outreachCondition' || node?.type === 'outreachAction') {
        return 'main';
    }

    return undefined;
}

function branchChain(
    conditionId: string,
    branchKey: 'accepted' | 'not_accepted',
    branchSteps: OutreachStep[],
    startY: number,
    x: number,
    parentKey: number,
    nodes: Node[],
    edges: Edge[],
): number {
    let y = startY;
    let previousId = conditionId;

    for (const step of branchSteps) {
        if (step.type === 'end') continue;

        const nodeId = String(step.key);
        nodes.push({
            id: nodeId,
            type: nodeTypeForStep(step),
            position: { x, y },
            data: { step, branch: branchKey, parentConditionKey: parentKey },
        });

        if (previousId === conditionId) {
            edges.push(deletableEdge({
                id: `e-${conditionId}-${nodeId}-${branchKey}`,
                source: conditionId,
                sourceHandle: branchKey,
                target: nodeId,
                animated: branchKey === 'accepted',
                style: branchKey === 'accepted'
                    ? { stroke: '#10b981', strokeWidth: 2 }
                    : { stroke: '#64748b', strokeWidth: 2 },
            }, step.key, step.type));
        } else {
            edges.push(deletableEdge({
                id: `e-${previousId}-${nodeId}`,
                source: previousId,
                target: nodeId,
                style: { stroke: '#94a3b8', strokeWidth: 1.5 },
            }, step.key, step.type));
        }

        previousId = nodeId;
        y += BRANCH_Y_GAP;
    }

    return y;
}

export function nodeModelToFlow(steps: OutreachStep[]): { nodes: Node[]; edges: Edge[] } {
    const nodes: Node[] = [];
    const edges: Edge[] = [];

    nodes.push({
        id: START_NODE_ID,
        type: 'outreachStart',
        position: { x: MAIN_X, y: 0 },
        data: { step: { key: 0, type: 'action', label: 'Start', channel: 'linkedin', action: 'start' } },
        selectable: false,
    });

    let previousMainId = START_NODE_ID;
    let mainY = 70;

    for (const step of steps) {
        const nodeId = String(step.key);

        if (step.type === 'condition') {
            nodes.push({
                id: nodeId,
                type: 'outreachCondition',
                position: { x: MAIN_X, y: mainY },
                data: { step },
            });

            edges.push(deletableEdge({
                id: `e-${previousMainId}-${nodeId}`,
                source: previousMainId,
                target: nodeId,
                style: { stroke: '#64748b', strokeWidth: 2 },
            }, step.key, step.type));

            const branchStartY = mainY + Y_GAP;
            const acceptedSteps = step.branches?.accepted ?? [];
            const notAcceptedSteps = step.branches?.not_accepted ?? [];

            const acceptedEndY = branchChain(
                nodeId,
                'accepted',
                acceptedSteps,
                branchStartY,
                ACCEPTED_X,
                step.key,
                nodes,
                edges,
            );

            const notAcceptedEndY = branchChain(
                nodeId,
                'not_accepted',
                notAcceptedSteps,
                branchStartY,
                NOT_ACCEPTED_X,
                step.key,
                nodes,
                edges,
            );

            mainY = Math.max(acceptedEndY, notAcceptedEndY, mainY + Y_GAP);
            previousMainId = nodeId;
            continue;
        }

        nodes.push({
            id: nodeId,
            type: nodeTypeForStep(step),
            position: { x: MAIN_X, y: mainY },
            data: { step },
        });

        edges.push(deletableEdge({
            id: `e-${previousMainId}-${nodeId}`,
            source: previousMainId,
            sourceHandle: mainSourceHandle(previousMainId, nodes),
            target: nodeId,
            style: { stroke: '#64748b', strokeWidth: 2 },
        }, step.key, step.type));

        mainY += Y_GAP;
        previousMainId = nodeId;
    }

    return { nodes, edges };
}
