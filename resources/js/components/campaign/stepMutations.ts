import { actionMeta, type CampaignStep } from '@/components/campaign/types';

function cloneSteps(steps: CampaignStep[]): CampaignStep[] {
    return JSON.parse(JSON.stringify(steps)) as CampaignStep[];
}

export function nextStepKey(steps: CampaignStep[]): number {
    let max = 0;

    function walk(list: CampaignStep[]) {
        for (const step of list) {
            if (step.key > max) max = step.key;
            if (step.branches) {
                walk(step.branches.accepted);
                walk(step.branches.not_accepted);
            }
        }
    }

    walk(steps);
    return max + 1;
}

type StepLocation = {
    list: CampaignStep[];
    index: number;
};

function findStepLocation(steps: CampaignStep[], key: number): StepLocation | null {
    for (let index = 0; index < steps.length; index += 1) {
        const step = steps[index];
        if (step.key === key) {
            return { list: steps, index };
        }

        if (step.branches) {
            const accepted = findStepLocation(step.branches.accepted, key);
            if (accepted) return accepted;
            const notAccepted = findStepLocation(step.branches.not_accepted, key);
            if (notAccepted) return notAccepted;
        }
    }

    return null;
}

function endInsertIndex(list: CampaignStep[], afterIndex: number): number {
    const endIdx = list.findIndex((step) => step.type === 'end');
    if (afterIndex === -1) {
        return endIdx === -1 ? list.length : endIdx;
    }

    return Math.min(afterIndex + 1, endIdx === -1 ? list.length : endIdx);
}

function createStep(type: 'action' | 'delay', value = 'message', key: number): CampaignStep {
    if (type === 'delay') {
        return { key, type: 'delay', value: 1, time: 'days', label: 'Wait 1 day' };
    }

    const info = actionMeta(value);
    return {
        key,
        type: 'action',
        value,
        label: info.label,
        config: value === 'endorse' ? { skills: 3 } : { message: '' },
    };
}

export function insertStepAfter(
    steps: CampaignStep[],
    afterKey: number,
    type: 'action' | 'delay',
    value = 'message',
): CampaignStep[] {
    const next = cloneSteps(steps);
    const key = nextStepKey(next);

    if (afterKey === -1) {
        const insertIdx = endInsertIndex(next, -1);
        next.splice(insertIdx, 0, createStep(type, value, key));
        return next;
    }

    const location = findStepLocation(next, afterKey);
    if (!location) return steps;

    const insertIdx = endInsertIndex(location.list, location.index);
    location.list.splice(insertIdx, 0, createStep(type, value, key));
    return next;
}

export function removeStep(steps: CampaignStep[], key: number): CampaignStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, key);
    if (!location) return steps;

    const step = location.list[location.index];
    if (step.type === 'end') return steps;

    // Conditions are removed as a whole (including Yes/No branch steps nested on the node).
    location.list.splice(location.index, 1);
    return next;
}

export function conditionBranchStepCount(step: CampaignStep): number {
    if (step.type !== 'condition') return 0;
    const accepted = step.branches?.accepted?.length ?? 0;
    const notAccepted = step.branches?.not_accepted?.length ?? 0;
    return accepted + notAccepted;
}

/**
 * Soft UX hint when a condition no longer has a matching action above it on the main path.
 */
export function conditionPrerequisiteWarning(steps: CampaignStep[], condition: CampaignStep): string | null {
    if (condition.type !== 'condition') return null;

    const idx = steps.findIndex((s) => s.key === condition.key);
    if (idx < 0) return null;

    const before = steps.slice(0, idx);
    const hasInvite = before.some((s) =>
        s.type === 'action' && ['send-invite', 'send-invites', 'invite', 'connect'].includes(String(s.value ?? '')),
    );

    const cond = String(condition.value ?? 'accepted');
    if (['accepted', 'invite_accepted', 'invite-accepted'].includes(cond) && !hasInvite) {
        return `"${condition.label}" usually needs a Send Invite step above it.`;
    }

    return null;
}

/** Remove a step when disconnecting an edge (allows conditions too). */
export function disconnectStep(steps: CampaignStep[], key: number): CampaignStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, key);
    if (!location) return steps;

    const step = location.list[location.index];
    if (step.type === 'end') return steps;

    location.list.splice(location.index, 1);
    return next;
}

export function updateStepField(
    steps: CampaignStep[],
    key: number,
    field: string,
    value: unknown,
): CampaignStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, key);
    if (!location) return steps;

    const step = location.list[location.index];
    (step as unknown as Record<string, unknown>)[field] = value;

    if (field === 'value' && step.type === 'action') {
        step.label = actionMeta(value as string).label;
    }

    if (step.type === 'delay' && (field === 'time' || field === 'value')) {
        step.label = `Wait ${step.value} ${step.time}`;
    }

    return next;
}

export function updateStepConfig(
    steps: CampaignStep[],
    key: number,
    configKey: string,
    value: unknown,
): CampaignStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, key);
    if (!location) return steps;

    const step = location.list[location.index];
    step.config = { ...step.config, [configKey]: value };
    return next;
}

export function findStepByKey(steps: CampaignStep[], key: number): CampaignStep | null {
    const location = findStepLocation(steps, key);
    return location ? location.list[location.index] : null;
}
