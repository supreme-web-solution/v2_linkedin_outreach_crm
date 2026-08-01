export type FormattedEmailBody = {
    main: string;
    quoted: string | null;
    preview: string;
    quote_header: string | null;
};

export function formatEmailBody(raw: string): FormattedEmailBody {
    const text = raw.replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (!text) {
        return { main: '', quoted: null, preview: '', quote_header: null };
    }

    const splitAt = findQuoteSplitIndex(text);
    let main = text.slice(0, splitAt).trim();
    let quotedRaw = text.slice(splitAt).trim();

    if (!main && quotedRaw) {
        main = stripQuoteMarkers(quotedRaw);
        quotedRaw = '';
    }

    const quoteHeader = resolveQuoteHeader(main, quotedRaw);
    main = stripOnWroteHeaderFromEnd(main);
    const quoted = quotedRaw ? stripQuoteMarkers(quotedRaw) : null;
    const previewSource = main || quoted || text;

    return {
        main,
        quoted: quoted || null,
        preview: previewSource.replace(/\s+/g, ' ').trim().slice(0, 160),
        quote_header: quoteHeader,
    };
}

function resolveQuoteHeader(main: string, quotedRaw: string): string | null {
    const block = extractOnWroteHeaderBlock(quotedRaw) ?? extractOnWroteHeaderBlock(main);
    if (!block) {
        return null;
    }

    const label = formatQuoteHeaderLabel(block);

    return label || null;
}

function extractOnWroteHeaderBlock(text: string): string | null {
    const match = text.match(/((?:^|[\n\s])On .+?(?:\n(?!>)[^\n]+)*\n?wrote:\s*)/is);

    return match?.[1]?.trim() ?? null;
}

function formatQuoteHeaderLabel(block: string): string {
    const parts: string[] = [];

    for (const line of block.split('\n')) {
        const trimmed = line.trim();
        if (!trimmed || /^wrote:\s*$/i.test(trimmed)) {
            continue;
        }

        const onMatch = trimmed.match(/^On\s+(.+)$/i);
        if (onMatch?.[1]) {
            const part = onMatch[1].replace(/\s+wrote:\s*$/i, '').trim();
            if (part) {
                parts.push(part);
            }
            continue;
        }

        parts.push(trimmed.replace(/\s+wrote:\s*$/i, '').trim());
    }

    return prettifyQuoteHeaderLabel(parts.filter(Boolean).join(' · ').replace(/\s+wrote:\s*$/i, '').trim());
}

function prettifyQuoteHeaderLabel(label: string): string {
    if (label.includes(' · ')) {
        return label;
    }

    const match = label.match(/^(.+?\s)(\S+@\S+)$/u);
    if (match?.[1] && match[2]) {
        return `${match[1].trim()} · ${match[2].trim()}`;
    }

    return label;
}

function findQuoteSplitIndex(text: string): number {
    const patterns = [
        /(?:^|[\n\s])On .+?(?:\n(?!>)[^\n]+)*\n?wrote:\s*/is,
        /\n-{2,}\s*Original Message\s*-{2,}/i,
        /\nFrom: .+\n(?:Sent|Date): .+\n/i,
    ];

    let splitAt = text.length;
    for (const pattern of patterns) {
        const match = text.match(pattern);
        if (match?.index !== undefined) {
            splitAt = Math.min(splitAt, match.index);
        }
    }

    const lines = text.split('\n');
    let quoteLineIndex: number | null = null;
    let contentBeforeQuote = 0;

    lines.forEach((line, index) => {
        const trimmed = line.trimStart();
        if (trimmed.startsWith('>')) {
            if (quoteLineIndex === null) {
                quoteLineIndex = index;
            }
        } else if (line.trim() && quoteLineIndex === null) {
            contentBeforeQuote += 1;
        }
    });

    if (quoteLineIndex !== null && contentBeforeQuote > 0) {
        const splitLineIndex = quoteBlockStartLineIndex(lines, quoteLineIndex);
        let offset = 0;
        for (let i = 0; i < splitLineIndex; i += 1) {
            offset += lines[i].length + 1;
        }
        splitAt = Math.min(splitAt, offset);
    }

    return Math.max(0, splitAt);
}

function quoteBlockStartLineIndex(lines: string[], quoteLineIndex: number): number {
    let start = quoteLineIndex;

    for (let i = quoteLineIndex - 1; i >= 0; i -= 1) {
        const lineTrimmed = lines[i].trim();
        if (lineTrimmed === '') {
            continue;
        }

        if (isOnWroteHeaderLine(lineTrimmed) || /^wrote:\s*$/i.test(lineTrimmed)) {
            start = i;
            continue;
        }

        if (/^On .+/i.test(lineTrimmed) && lineLooksLikeOnWroteContinuation(lines, i)) {
            start = i;
            continue;
        }

        if (lineTrimmed.includes('@') && lineLooksLikeOnWroteContinuation(lines, i)) {
            start = i;
            continue;
        }

        break;
    }

    return start;
}

function lineLooksLikeOnWroteContinuation(lines: string[], index: number): boolean {
    for (let j = index + 1; j < Math.min(index + 4, lines.length); j += 1) {
        const nextTrimmed = lines[j].trim();
        if (nextTrimmed === '') {
            continue;
        }

        if (/^wrote:\s*$/i.test(nextTrimmed)) {
            return true;
        }

        return /^.+ wrote:\s*$/i.test(nextTrimmed);
    }

    return false;
}

function isOnWroteHeaderLine(line: string): boolean {
    return /^On .+ wrote:\s*$/i.test(line);
}

function stripOnWroteHeaderFromEnd(text: string): string {
    return text.replace(/(?:\n\s*)+On .+?(?:\n(?!>)[^\n]+)*\n?wrote:\s*$/is, '').trim();
}

function stripQuoteMarkers(text: string): string {
    const withoutHeader = text.replace(/^(?:On .+?(?:\n(?!>)[^\n]+)*\n?wrote:\s*\n?)/is, '');

    const cleaned = withoutHeader
        .split('\n')
        .map((line) => {
            const lineTrimmed = line.trim();
            if (lineTrimmed === '' || isOnWroteHeaderLine(lineTrimmed) || /^wrote:\s*$/i.test(lineTrimmed)) {
                return '';
            }

            if (/^.+ wrote:\s*$/i.test(lineTrimmed) && lineTrimmed.includes('@')) {
                return '';
            }

            if (/^On .+/i.test(lineTrimmed)) {
                return '';
            }

            let trimmed = line.trimStart();
            if (trimmed.startsWith('>')) {
                trimmed = trimmed.slice(1).trimStart();
            }

            return trimmed.trim();
        })
        .filter(Boolean)
        .join('\n')
        .trim();

    return cleaned.replace(/\n{3,}/g, '\n\n');
}
