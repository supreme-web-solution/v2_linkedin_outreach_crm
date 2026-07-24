export const INSPIRATION_DRAFT_KEY = 'linkedempire_content_from_inspiration';

export function stashInspirationDraft(content: string): void {
    sessionStorage.setItem(INSPIRATION_DRAFT_KEY, content);
}
