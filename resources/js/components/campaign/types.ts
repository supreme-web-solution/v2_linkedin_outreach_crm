export interface CampaignStep {
    key: number;
    type: 'action' | 'delay' | 'condition' | 'end';
    value?: string | number;
    label: string;
    time?: 'hours' | 'days';
    config?: Record<string, unknown>;
    branches?: { accepted: CampaignStep[]; not_accepted: CampaignStep[] };
}

export interface CampaignActionMeta {
    value: string;
    label: string;
    accent: string;
    light: string;
    border: string;
    ring: string;
}

export const CAMPAIGN_ACTIONS: CampaignActionMeta[] = [
    { value: 'send-invite', label: 'Send Invite', accent: '#2563eb', light: '#dbeafe', border: '#93c5fd', ring: '#2563eb33' },
    { value: 'message', label: 'Send Message', accent: '#059669', light: '#d1fae5', border: '#6ee7b7', ring: '#05966933' },
    { value: 'endorse', label: 'Endorse Skills', accent: '#d97706', light: '#fef3c7', border: '#fcd34d', ring: '#d9770633' },
    { value: 'profile-view', label: 'View Profile', accent: '#7c3aed', light: '#ede9fe', border: '#c4b5fd', ring: '#7c3aed33' },
    { value: 'like-post', label: 'Like a Post', accent: '#e11d48', light: '#ffe4e6', border: '#fda4af', ring: '#e11d4833' },
];

export const CAMPAIGN_CONDITIONS = [
    { value: 'accepted', label: 'Invite accepted?' },
] as const;

export function actionMeta(value?: string | number): CampaignActionMeta {
    return CAMPAIGN_ACTIONS.find((a) => a.value === value) ?? CAMPAIGN_ACTIONS[0];
}

export function stepChipLabel(step: CampaignStep): string {
    if (step.type === 'delay') {
        const unit = typeof step.time === 'string' ? step.time[0] : 'd';

        return `${step.value ?? ''}${unit}`;
    }

    if (step.type === 'condition') {
        return 'Branch';
    }

    return step.label;
}

export const START_NODE_ID = '__start__';
