export type ChatTimelineMessage = {
    created_at?: string | null;
};

export function messageDayKey(at: string | null | undefined): string {
    if (!at) return '';

    try {
        return new Date(at).toDateString();
    } catch {
        return at.slice(0, 10);
    }
}

export function formatChatDateDivider(at: string | null | undefined): string {
    if (!at) return '';

    try {
        const date = new Date(at);
        const now = new Date();
        const sameDay = date.toDateString() === now.toDateString();
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        const isYesterday = date.toDateString() === yesterday.toDateString();

        if (sameDay) return 'Today';
        if (isYesterday) return 'Yesterday';

        return date.toLocaleDateString(undefined, {
            weekday: 'long',
            month: 'short',
            day: 'numeric',
        });
    } catch {
        return at.slice(0, 10);
    }
}

export function formatChatMessageTime(at: string | null | undefined): string {
    if (!at) return '';

    try {
        const date = new Date(at);
        return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    } catch {
        return at.slice(11, 16);
    }
}

export function showChatDateDivider<T extends ChatTimelineMessage>(messages: T[], index: number): boolean {
    const current = messages[index];
    if (!current?.created_at) return false;
    if (index === 0) return true;

    const previous = messages[index - 1];
    if (!previous?.created_at) return true;

    return messageDayKey(current.created_at) !== messageDayKey(previous.created_at);
}
