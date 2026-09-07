export function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** Lightweight markdown for Command Center chat (bold + line breaks). */
export function formatChatMarkdown(value: string): string {
    let html = escapeHtml(value);

    html = html.replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>');
    html = html.replace(/\*(?!\*)([^*\n]+)\*(?!\*)/g, '<em>$1</em>');

    return html;
}
