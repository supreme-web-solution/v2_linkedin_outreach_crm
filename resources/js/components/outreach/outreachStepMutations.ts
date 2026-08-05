import { nextStepKey, type OutreachChannel, type OutreachStep } from '@/components/outreach/types';

function cloneSteps(steps: OutreachStep[]): OutreachStep[] {
    return JSON.parse(JSON.stringify(steps)) as OutreachStep[];
}

type StepLocation = {
    list: OutreachStep[];
    index: number;
};

function findStepLocation(steps: OutreachStep[], key: number): StepLocation | null {
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

function endInsertIndex(list: OutreachStep[], afterIndex: number): number {
    const endIdx = list.findIndex((step) => step.type === 'end');
    if (afterIndex === -1) {
        return endIdx === -1 ? list.length : endIdx;
    }

    return Math.min(afterIndex + 1, endIdx === -1 ? list.length : endIdx);
}

function defaultConfig(actionKey: string): Record<string, unknown> {
    if (actionKey === 'send_invite' || actionKey === 'send_message') {
        return { message: '' };
    }
    if (actionKey === 'send_email') {
        return { subject: '', body: '' };
    }
    if (actionKey === 'endorse') {
        return { skills: 3 };
    }
    return {};
}

function createActionStep(
    channel: OutreachChannel,
    actionKey: string,
    actionLabel: string,
    key: number,
): OutreachStep {
    return {
        key,
        type: 'action',
        channel,
        action: actionKey,
        label: actionLabel,
        config: defaultConfig(actionKey),
    };
}

function createDelayStep(key: number): OutreachStep {
    return { key, type: 'delay', value: 1, time: 'days', label: 'Wait 1 day' };
}

function createConditionStep(
    channel: OutreachChannel,
    conditionKey: string,
    conditionLabel: string,
    key: number,
): OutreachStep {
    return {
        key,
        type: 'condition',
        channel,
        condition: conditionKey,
        label: conditionLabel,
        branches: { accepted: [], not_accepted: [] },
    };
}

function insertStep(
    steps: OutreachStep[],
    afterKey: number,
    step: OutreachStep,
): OutreachStep[] {
    const next = cloneSteps(steps);

    if (afterKey === -1) {
        const insertIdx = endInsertIndex(next, -1);
        next.splice(insertIdx, 0, step);
        if (!next.some((s) => s.type === 'end')) {
            next.push({ key: 99, type: 'end', label: 'End' });
        }
        return next;
    }

    const location = findStepLocation(next, afterKey);
    if (!location) return steps;

    const insertIdx = endInsertIndex(location.list, location.index);
    location.list.splice(insertIdx, 0, step);
    return next;
}

export function insertIntoBranch(
    steps: OutreachStep[],
    conditionKey: number,
    branch: 'accepted' | 'not_accepted',
    channel: OutreachChannel,
    actionKey: string,
    actionLabel: string,
): OutreachStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, conditionKey);
    if (!location || location.list[location.index].type !== 'condition') return steps;

    const cond = location.list[location.index];
    if (!cond.branches) cond.branches = { accepted: [], not_accepted: [] };
    const branchList = branch === 'accepted' ? cond.branches.accepted : cond.branches.not_accepted;
    const key = nextStepKey(steps);
    branchList.push(createActionStep(channel, actionKey, actionLabel, key));
    return next;
}

export function insertDelayIntoBranch(
    steps: OutreachStep[],
    conditionKey: number,
    branch: 'accepted' | 'not_accepted',
): OutreachStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, conditionKey);
    if (!location || location.list[location.index].type !== 'condition') return steps;

    const cond = location.list[location.index];
    if (!cond.branches) cond.branches = { accepted: [], not_accepted: [] };
    const branchList = branch === 'accepted' ? cond.branches.accepted : cond.branches.not_accepted;
    const key = nextStepKey(steps);
    branchList.push(createDelayStep(key));
    return next;
}

export function insertActionAfter(
    steps: OutreachStep[],
    afterKey: number,
    channel: OutreachChannel,
    actionKey: string,
    actionLabel: string,
): OutreachStep[] {
    const key = nextStepKey(steps);
    return insertStep(steps, afterKey, createActionStep(channel, actionKey, actionLabel, key));
}

export function insertDelayAfter(steps: OutreachStep[], afterKey: number): OutreachStep[] {
    const key = nextStepKey(steps);
    return insertStep(steps, afterKey, createDelayStep(key));
}

export function insertConditionAfter(
    steps: OutreachStep[],
    afterKey: number,
    channel: OutreachChannel,
    conditionKey: string,
    conditionLabel: string,
): OutreachStep[] {
    const key = nextStepKey(steps);
    return insertStep(steps, afterKey, createConditionStep(channel, conditionKey, conditionLabel, key));
}

export function removeStep(steps: OutreachStep[], key: number): OutreachStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, key);
    if (!location) return steps;

    const step = location.list[location.index];
    if (step.type === 'end') return steps;

    // Conditions are removed as a whole (including Yes/No branch steps nested on the node).
    location.list.splice(location.index, 1);

    if (next.length === 0 || !next.some((s) => s.type === 'end')) {
        return next.length ? next : [{ key: 99, type: 'end', label: 'End' }];
    }

    return next;
}

export function conditionBranchStepCount(step: OutreachStep): number {
    if (step.type !== 'condition') return 0;
    const accepted = step.branches?.accepted?.length ?? 0;
    const notAccepted = step.branches?.not_accepted?.length ?? 0;
    return accepted + notAccepted;
}

/**
 * Soft UX hint when a condition no longer has a matching action above it on the main path.
 */
export function conditionPrerequisiteWarning(steps: OutreachStep[], condition: OutreachStep): string | null {
    if (condition.type !== 'condition') return null;

    const idx = steps.findIndex((s) => s.key === condition.key);
    if (idx < 0) return null;

    const before = steps.slice(0, idx);
    const cond = String(condition.condition ?? '');
    const channel = String(condition.channel ?? '');

    const hasAction = (action: string) =>
        before.some((s) => s.type === 'action' && s.channel === channel && s.action === action);

    if (cond === 'invite_accepted' && !hasAction('send_invite')) {
        return `"${condition.label}" usually needs a Send Invite step above it.`;
    }

    if (['message_replied', 'has_replied', 'no_reply'].includes(cond) && channel !== 'email') {
        if (!hasAction('send_message')) {
            return `"${condition.label}" usually needs a Send Message step above it.`;
        }
    }

    if (['email_replied', 'email_opened', 'email_bounced', 'opened', 'bounced'].includes(cond) || (cond === 'no_reply' && channel === 'email')) {
        if (!hasAction('send_email')) {
            return `"${condition.label}" usually needs a Send Email step above it.`;
        }
    }

    return null;
}

/** Remove a step when disconnecting an edge (allows conditions too). */
export function disconnectStep(steps: OutreachStep[], key: number): OutreachStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, key);
    if (!location) return steps;

    const step = location.list[location.index];
    if (step.type === 'end') return steps;

    location.list.splice(location.index, 1);

    if (next.length === 0 || !next.some((s) => s.type === 'end')) {
        return next.length ? next : [{ key: 99, type: 'end', label: 'End' }];
    }

    return next;
}

export function updateStepField(
    steps: OutreachStep[],
    key: number,
    field: string,
    value: unknown,
): OutreachStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, key);
    if (!location) return steps;

    const step = location.list[location.index];
    (step as unknown as Record<string, unknown>)[field] = value;

    if (step.type === 'delay' && (field === 'time' || field === 'value')) {
        step.label = `Wait ${step.value} ${step.time}`;
    }

    return next;
}

export function updateStepConfig(
    steps: OutreachStep[],
    key: number,
    configKey: string,
    value: unknown,
): OutreachStep[] {
    const next = cloneSteps(steps);
    const location = findStepLocation(next, key);
    if (!location) return steps;

    const step = location.list[location.index];
    step.config = { ...step.config, [configKey]: value };
    return next;
}

export function findStepByKey(steps: OutreachStep[], key: number): OutreachStep | null {
    const location = findStepLocation(steps, key);
    return location ? location.list[location.index] : null;
}

export { nextStepKey };
