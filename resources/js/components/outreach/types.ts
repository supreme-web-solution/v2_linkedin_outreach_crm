export const START_NODE_ID = '__start__';

export type OutreachStepType = 'action' | 'delay' | 'condition' | 'end';

export type OutreachChannel =
    | 'linkedin'
    | 'email'
    | 'whatsapp'
    | 'instagram'
    | 'telegram'
    | 'twitter';

export interface OutreachStep {
    key: number;
    type: OutreachStepType;
    channel?: OutreachChannel;
    action?: string;
    condition?: string;
    value?: string | number;
    label: string;
    time?: 'hours' | 'days';
    config?: Record<string, unknown>;
    branches?: { accepted: OutreachStep[]; not_accepted: OutreachStep[] };
}

export interface ConnectedChannel {
    channel: OutreachChannel;
    label: string;
    connected: boolean;
    status: string;
    email?: string | null;
    account_name?: string | null;
}

export function stepChipLabel(step: OutreachStep): string {
    if (step.type === 'delay') {
        const unit = step.time === 'hours' ? 'h' : 'd';
        return `${step.value ?? ''}${unit}`;
    }
    if (step.type === 'condition') return 'Branch';
    if (step.type === 'end') return 'End';
    return step.label;
}

export function nextStepKey(steps: OutreachStep[]): number {
    let max = 0;
    const walk = (list: OutreachStep[]) => {
        for (const s of list) {
            if (s.key > max) max = s.key;
            if (s.branches) {
                walk(s.branches.accepted || []);
                walk(s.branches.not_accepted || []);
            }
        }
    };
    walk(steps);
    return max >= 99 ? max + 1 : Math.max(max + 1, 1);
}
