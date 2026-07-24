import type { Edge } from '@vue-flow/core';

export type FlowEdgeData = {
    targetKey: number;
    deletable: boolean;
};

export function deletableEdge(
    edge: Omit<Edge, 'type' | 'data'>,
    targetKey: number,
    targetType: string,
): Edge {
    return {
        ...edge,
        type: 'deletable',
        data: {
            targetKey,
            deletable: targetType !== 'end',
        } satisfies FlowEdgeData,
    };
}
