/** Detect and strip the WhatsApp voice-note prefix used by the Zernio ingest path. */

const VOICE_NOTE_PREFIX = /^\s*\[Voice note\]\s*/i;

export function isVoiceNoteMessage(content: string | null | undefined): boolean {
    return VOICE_NOTE_PREFIX.test(String(content ?? ''));
}

export function voiceNoteTranscript(content: string | null | undefined): string {
    return String(content ?? '').replace(VOICE_NOTE_PREFIX, '').trim();
}
