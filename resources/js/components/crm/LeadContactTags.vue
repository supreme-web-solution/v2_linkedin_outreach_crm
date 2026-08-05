<script setup lang="ts">
import { Loader2, Mail, MessageCircle, Phone, Send } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { type Component, computed, h } from 'vue';

export interface LeadContacts {
    email: string | null;
    phone: string | null;
    whatsapp_provider_id: string | null;
    whatsapp_verify_status: string | null;
    instagram_handle: string | null;
    instagram_provider_id: string | null;
    telegram_handle: string | null;
    telegram_provider_id: string | null;
    twitter_handle: string | null;
    twitter_provider_id: string | null;
    email_fetch_status: string | null;
    phone_fetch_status: string | null;
    phone_fetch_attempted?: boolean;
}

type TagState = 'available' | 'handle' | 'pending' | 'not_found' | 'missing' | 'unreachable';

interface ContactTag {
    key: string;
    icon: Component;
    state: TagState;
    title: string;
    value?: string;
    fetchable?: boolean;
}

const LinkedInIcon: Component = {
    render() {
        return h(
            'svg',
            {
                xmlns: 'http://www.w3.org/2000/svg',
                viewBox: '0 0 24 24',
                fill: 'currentColor',
                class: 'h-3.5 w-3.5',
            },
            [
                h('path', {
                    d: 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 1 1 0-4.124 2.062 2.062 0 0 1 0 4.124zM6.977 20.452H3.696V9h3.281v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
                }),
            ],
        );
    },
};

const InstagramIcon: Component = {
    render() {
        return h(
            'svg',
            {
                xmlns: 'http://www.w3.org/2000/svg',
                viewBox: '0 0 24 24',
                fill: 'currentColor',
                class: 'h-3.5 w-3.5',
            },
            [
                h('path', {
                    d: 'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z',
                }),
            ],
        );
    },
};

const XIcon: Component = {
    render() {
        return h(
            'svg',
            {
                xmlns: 'http://www.w3.org/2000/svg',
                viewBox: '0 0 24 24',
                fill: 'currentColor',
                class: 'h-3.5 w-3.5',
            },
            [
                h('path', {
                    d: 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z',
                }),
            ],
        );
    },
};

const props = withDefaults(
    defineProps<{
        contacts: LeadContacts;
        showLinkedIn?: boolean;
        showEmail?: boolean;
        allowEmailFetch?: boolean;
        emailFetchDisabled?: boolean;
        fetchingEmail?: boolean;
    }>(),
    {
        showLinkedIn: true,
        showEmail: true,
        allowEmailFetch: false,
        emailFetchDisabled: false,
        fetchingEmail: false,
    },
);

const emit = defineEmits<{
    'fetch-email': [];
}>();

function emailState(): TagState {
    if (props.contacts.email) return 'available';
    const status = props.contacts.email_fetch_status ?? '';
    if (['pending', 'processing'].includes(status) || props.fetchingEmail) return 'pending';
    if (status === 'completed') return 'not_found';
    return 'missing';
}

function phoneState(): TagState {
    if (props.contacts.phone) return 'available';
    const status = props.contacts.phone_fetch_status ?? '';
    if (['pending', 'processing'].includes(status)) return 'pending';
    if (status === 'completed' && props.contacts.phone_fetch_attempted) return 'not_found';
    return 'missing';
}

function whatsAppState(): TagState {
    // Green only when verified for sending. Phone icon alone means "has number".
    if (props.contacts.whatsapp_provider_id) return 'available';
    if (props.contacts.whatsapp_verify_status === 'unreachable') return 'unreachable';
    return 'missing';
}

const page = usePage();

const enabledChannels = computed(() => {
    const channels = page.props.enabledChannels;
    return Array.isArray(channels) ? (channels as string[]) : [];
});

function isChannelEnabled(key: string): boolean {
    const enabled = enabledChannels.value;
    return enabled.length === 0 || enabled.includes(key);
}

function socialState(handle: string | null, providerId: string | null): TagState {
    if (providerId) return 'available';
    if (handle) return 'handle';
    return 'missing';
}

const tags = computed((): ContactTag[] => {
    const list: ContactTag[] = [];

    if (props.showLinkedIn) {
        list.push({
            key: 'linkedin',
            icon: LinkedInIcon,
            state: 'available',
            title: 'LinkedIn profile',
        });
    }

    const email = emailState();
    if (props.showEmail) {
        list.push({
            key: 'email',
            icon: Mail,
            state: email,
            title: props.contacts.email
                ? props.contacts.email
                : email === 'not_found'
                  ? 'Email not found'
                  : email === 'pending'
                    ? 'Fetching email…'
                    : 'No email',
            value: props.contacts.email ?? undefined,
            fetchable: props.allowEmailFetch && email === 'missing',
        });
    }

    const phone = phoneState();
    list.push({
        key: 'phone',
        icon: Phone,
        state: phone,
        title: props.contacts.phone
            ? props.contacts.phone
            : phone === 'not_found'
              ? 'Phone not found'
              : phone === 'pending'
                ? 'Fetching phone…'
                : 'No phone',
        value: props.contacts.phone ?? undefined,
    });

    const wa = whatsAppState();
    list.push({
        key: 'whatsapp',
        icon: MessageCircle,
        state: wa,
        title:
            wa === 'available'
                ? 'WhatsApp ready to message'
                : wa === 'unreachable'
                  ? 'Marked not reachable on WhatsApp'
                  : props.contacts.phone
                    ? 'Has phone — run Enrich once to enable WhatsApp'
                    : 'No WhatsApp',
    });

    const ig = socialState(props.contacts.instagram_handle, props.contacts.instagram_provider_id);
    list.push({
        key: 'instagram',
        icon: InstagramIcon,
        state: ig,
        title:
            ig === 'available'
                ? `@${props.contacts.instagram_handle} (ready)`
                : ig === 'handle'
                  ? `@${props.contacts.instagram_handle} — resolve handle`
                  : 'No Instagram',
        value: props.contacts.instagram_handle ?? undefined,
    });

    const tg = socialState(props.contacts.telegram_handle, props.contacts.telegram_provider_id);
    list.push({
        key: 'telegram',
        icon: Send,
        state: tg,
        title:
            tg === 'available'
                ? `@${props.contacts.telegram_handle} (ready)`
                : tg === 'handle'
                  ? `@${props.contacts.telegram_handle} — resolve handle`
                  : 'No Telegram',
        value: props.contacts.telegram_handle ?? undefined,
    });

    const x = socialState(props.contacts.twitter_handle, props.contacts.twitter_provider_id);
    list.push({
        key: 'twitter',
        icon: XIcon,
        state: x,
        title:
            x === 'available'
                ? `@${props.contacts.twitter_handle} (ready)`
                : x === 'handle'
                  ? `@${props.contacts.twitter_handle} — resolve handle`
                  : 'No X / Twitter',
        value: props.contacts.twitter_handle ?? undefined,
    });

    return list.filter((tag) => {
        if (tag.key === 'linkedin') return isChannelEnabled('linkedin');
        if (tag.key === 'email') return isChannelEnabled('email');
        if (tag.key === 'whatsapp') return isChannelEnabled('whatsapp');
        if (tag.key === 'instagram') return isChannelEnabled('instagram');
        if (tag.key === 'telegram') return isChannelEnabled('telegram');
        if (tag.key === 'twitter') return isChannelEnabled('twitter');
        return true;
    });
});

function tagClass(state: TagState): string {
    switch (state) {
        case 'available':
            return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300';
        case 'handle':
            return 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200';
        case 'pending':
            return 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200';
        case 'not_found':
        case 'unreachable':
            return 'border-border bg-muted/40 text-muted-foreground/70 opacity-60';
        default:
            return 'border-border bg-background text-muted-foreground/45';
    }
}

function onEmailClick(tag: ContactTag) {
    if (tag.fetchable && !props.emailFetchDisabled && !props.fetchingEmail) {
        emit('fetch-email');
    }
}
</script>

<template>
    <div class="flex max-w-[320px] flex-wrap gap-1.5">
        <span
            v-for="tag in tags"
            :key="tag.key"
            :title="tag.title"
            class="inline-flex h-7 w-7 items-center justify-center rounded-md border transition-colors"
            :class="[
                tagClass(tag.state),
                tag.fetchable && !emailFetchDisabled && !fetchingEmail ? 'cursor-pointer hover:opacity-90' : '',
            ]"
            @click="tag.key === 'email' ? onEmailClick(tag) : undefined"
        >
            <Loader2 v-if="tag.key === 'email' && (tag.state === 'pending' || fetchingEmail)" class="h-3.5 w-3.5 animate-spin" />
            <component :is="tag.icon" v-else class="h-3.5 w-3.5" />
        </span>
    </div>
</template>
