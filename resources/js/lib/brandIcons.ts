export type BrandChannel = 'linkedin' | 'email' | 'whatsapp' | 'instagram' | 'telegram' | 'twitter' | 'google_calendar' | 'outlook_calendar';

export const BRAND_ICON_SRC: Record<BrandChannel, string> = {
    linkedin: '/brands/linkedin.svg',
    whatsapp: '/brands/whatsapp.svg',
    instagram: '/brands/instagram.svg',
    telegram: '/brands/telegram.svg',
    twitter: '/brands/twitter.svg',
    email: '/brands/email.svg',
    google_calendar: '/brands/google_calendar.svg',
    outlook_calendar: '/brands/outlook_calendar.svg',
};

export function brandIconSrc(channel?: string | null): string {
    const key = String(channel ?? 'linkedin').toLowerCase() as BrandChannel;

    return BRAND_ICON_SRC[key] ?? BRAND_ICON_SRC.email;
}

export function brandChannelLabel(channel?: string | null): string {
    const labels: Record<string, string> = {
        linkedin: 'LinkedIn',
        email: 'Email',
        whatsapp: 'WhatsApp',
        instagram: 'Instagram',
        telegram: 'Telegram',
        twitter: 'X (Twitter)',
        google_calendar: 'Google Calendar',
        outlook_calendar: 'Outlook Calendar',
    };

    return labels[String(channel ?? '').toLowerCase()] ?? 'Channel';
}
