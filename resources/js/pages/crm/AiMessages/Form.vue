<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Loader2, Mail, MessageSquare, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import SimpleTextEditor from '@/components/crm/SimpleTextEditor.vue';

interface AiContent {
    id: number;
    title: string;
    ai_type: string;
    language: string | null;
    idea: string | null;
    write_style: string | null;
    connection_message_type: string | null;
    connection_message_location: string | null;
    connection_message_industry: string | null;
    connection_message_jobtitle: string | null;
    contents: string | null;
    word_counts: number;
}

const props = defineProps<{
    aicontent?: AiContent;
}>();

const isEdit = computed(() => !!props.aicontent);

const types = [
    { id: 'first_cold_email', label: 'First cold email', desc: 'Professional first-contact emails', icon: Mail },
    { id: 'linkedin_connection_message', label: 'LinkedIn connection message', desc: 'Personalized connection requests', icon: MessageSquare },
    { id: 'personalized_ice_breaker', label: 'Personalized ice-breaker', desc: 'Engaging conversation starters', icon: Sparkles },
];

const form = useForm({
    title: props.aicontent?.title ?? '',
    aitype: props.aicontent?.ai_type ?? 'first_cold_email',
    language: props.aicontent?.language ?? 'English',
    write_style: props.aicontent?.write_style ?? 'Formal and respectful',
    personalized_by: props.aicontent?.connection_message_type ?? 'location',
    location: props.aicontent?.connection_message_location ?? '',
    industry: props.aicontent?.connection_message_industry ?? '',
    jobtitle: props.aicontent?.connection_message_jobtitle ?? '',
    idea: props.aicontent?.idea ?? '',
    content: props.aicontent?.contents ?? '',
    words: props.aicontent?.word_counts ?? 0,
});

const generating = ref(false);
const error = ref('');

const showWriteStyle = computed(() => form.aitype === 'first_cold_email');
const showPersonalized = computed(() => form.aitype === 'linkedin_connection_message');
const showIdea = computed(() => {
    if (form.aitype === 'first_cold_email') return true;
    if (form.aitype === 'linkedin_connection_message') return form.personalized_by === 'random';
    return false;
});
const showLocation = computed(() => showPersonalized.value && form.personalized_by === 'location');
const showIndustry = computed(() => showPersonalized.value && form.personalized_by === 'industry');
const showJobtitle = computed(() => showPersonalized.value && form.personalized_by === 'jobtitle');

function selectType(id: string) {
    form.aitype = id;
}

function recountWords() {
    form.words = form.content.trim() ? form.content.trim().split(/\s+/).length : 0;
}

function validate(): boolean {
    error.value = '';
    if (form.aitype === 'first_cold_email' && !form.idea.trim()) {
        error.value = 'Idea field is required.';
        return false;
    }
    if (form.aitype === 'linkedin_connection_message') {
        if (form.personalized_by === 'location' && !form.location.trim()) { error.value = 'Location field is required.'; return false; }
        if (form.personalized_by === 'industry' && !form.industry.trim()) { error.value = 'Industry field is required.'; return false; }
        if (form.personalized_by === 'jobtitle' && !form.jobtitle.trim()) { error.value = 'Job title field is required.'; return false; }
        if (form.personalized_by === 'random' && !form.idea.trim()) { error.value = 'Idea field is required.'; return false; }
    }
    return true;
}

async function generate() {
    if (!validate()) return;
    generating.value = true;
    error.value = '';
    try {
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const res = await fetch('/ai-messages/generate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
            body: JSON.stringify({
                language: form.language,
                aitype: form.aitype,
                idea: form.idea,
                write_style: form.write_style,
                personalized_by: form.personalized_by,
                location: form.location,
                industry: form.industry,
                jobtitle: form.jobtitle,
            }),
        });
        const data = await res.json();
        if (!res.ok) {
            error.value = data.message || 'Generation failed. Please try again.';
            return;
        }
        form.content = data.content ?? '';
        form.words = data.words ?? 0;
        recountWords();
    } catch (e) {
        error.value = 'Network error. Please try again.';
    } finally {
        generating.value = false;
    }
}

function submit() {
    recountWords();
    if (isEdit.value) {
        form.put(`/ai-messages/${props.aicontent!.id}`);
    } else {
        form.post('/ai-messages');
    }
}
</script>

<template>
    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <div v-if="error" class="rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">
            {{ error }}
        </div>

        <div>
            <label class="mb-3 block text-sm font-medium text-foreground">Select content type</label>
            <div class="grid gap-3 md:grid-cols-3">
                <button
                    v-for="t in types"
                    :key="t.id"
                    type="button"
                    class="flex items-start gap-3 rounded-lg border-2 p-4 text-left transition-all"
                    :class="form.aitype === t.id ? 'border-primary bg-primary/5' : 'border-border bg-card hover:border-primary/50'"
                    @click="selectType(t.id)"
                >
                    <component :is="t.icon" class="mt-0.5 h-5 w-5 shrink-0" :class="form.aitype === t.id ? 'text-primary' : 'text-muted-foreground'" />
                    <div>
                        <p class="text-sm font-semibold" :class="form.aitype === t.id ? 'text-primary' : 'text-foreground'">{{ t.label }}</p>
                        <p class="text-xs text-muted-foreground">{{ t.desc }}</p>
                    </div>
                </button>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-5 shadow-sm">
            <div class="grid gap-4">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-foreground">Title</span>
                    <input v-model="form.title" type="text" required placeholder="Message title" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary" />
                    <span v-if="form.errors.title" class="text-xs text-red-500">{{ form.errors.title }}</span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-foreground">Language</span>
                        <select v-model="form.language" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary">
                            <option>English</option>
                            <option>Romanian</option>
                            <option>Italian</option>
                            <option>French</option>
                            <option>Spanish</option>
                        </select>
                    </label>

                    <label v-if="showWriteStyle" class="block text-sm">
                        <span class="mb-1 block font-medium text-foreground">Writing style</span>
                        <select v-model="form.write_style" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary">
                            <option>Formal and respectful</option>
                            <option>Neutral and professional</option>
                            <option>Casual and friendly</option>
                        </select>
                    </label>

                    <label v-if="showPersonalized" class="block text-sm">
                        <span class="mb-1 block font-medium text-foreground">Personalize by</span>
                        <select v-model="form.personalized_by" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary">
                            <option value="location">Location</option>
                            <option value="mutual_connection">Mutual Connections</option>
                            <option value="mutual_interest">Mutual Interests</option>
                            <option value="industry">Industry</option>
                            <option value="jobtitle">Job title</option>
                            <option value="random">Random</option>
                        </select>
                    </label>
                </div>

                <label v-if="showLocation" class="block text-sm">
                    <span class="mb-1 block font-medium text-foreground">Location</span>
                    <input v-model="form.location" type="text" placeholder="Enter location" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary" />
                </label>
                <label v-if="showIndustry" class="block text-sm">
                    <span class="mb-1 block font-medium text-foreground">Industry</span>
                    <input v-model="form.industry" type="text" placeholder="Enter industry" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary" />
                </label>
                <label v-if="showJobtitle" class="block text-sm">
                    <span class="mb-1 block font-medium text-foreground">Job title</span>
                    <input v-model="form.jobtitle" type="text" placeholder="Enter job title" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary" />
                </label>

                <label v-if="showIdea" class="block text-sm">
                    <span class="mb-1 block font-medium text-foreground">Idea</span>
                    <textarea v-model="form.idea" rows="3" placeholder="Enter an idea or niche to generate content." class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"></textarea>
                </label>

                <div class="block text-sm">
                    <div class="mb-1 flex items-center justify-between">
                        <span class="font-medium text-foreground">Content</span>
                        <span class="text-xs text-muted-foreground">{{ form.words }} words</span>
                    </div>
                    <SimpleTextEditor
                        v-model="form.content"
                        :required="true"
                        :rows="12"
                        placeholder="Generated content appears here with line breaks. Edit, then copy or save."
                        @input="recountWords"
                    />
                    <span v-if="form.errors.content" class="text-xs text-red-500">{{ form.errors.content }}</span>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap justify-end gap-3">
                <button
                    type="button"
                    :disabled="generating"
                    class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 active:from-blue-600 active:to-blue-700 disabled:opacity-60"
                    @click="generate"
                >
                    <Loader2 v-if="generating" class="h-4 w-4 animate-spin" />
                    <Sparkles v-else class="h-4 w-4" />
                    {{ generating ? 'Generating…' : 'Generate' }}
                </button>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center rounded-lg border border-border bg-card px-4 py-2 text-sm font-medium hover:bg-muted disabled:opacity-60">
                    {{ isEdit ? 'Update' : 'Save' }}
                </button>
            </div>
        </div>
    </form>
</template>
