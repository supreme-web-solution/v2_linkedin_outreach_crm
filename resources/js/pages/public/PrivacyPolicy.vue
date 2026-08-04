<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: null });

const page = usePage();
const appName = computed(() => (page.props.name as string) || 'Socifusion');
const lastUpdated = 'August 4, 2026';
</script>

<template>
    <Head :title="`Privacy Policy`">
        <meta
            head-key="description"
            name="description"
            :content="`Privacy Policy for ${appName} — how we collect, use, and protect your information.`"
        />
    </Head>

    <div class="min-h-svh bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <header class="border-b border-slate-200/80 bg-white/80 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-5 py-5 sm:px-6">
                <div class="h-9 w-9 overflow-hidden rounded-xl ring-1 ring-slate-200 dark:ring-slate-700">
                    <img src="/images/brand/app-logo.png" :alt="appName" class="h-full w-full object-cover" />
                </div>
                <div>
                    <p class="text-sm font-semibold tracking-tight">{{ appName }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Privacy Policy</p>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-5 py-10 sm:px-6 sm:py-14">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Privacy Policy</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Last updated: {{ lastUpdated }}</p>

            <div class="prose-privacy mt-10 space-y-10 text-[15px] leading-7 text-slate-700 dark:text-slate-300">
                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">1. Introduction</h2>
                    <p>
                        {{ appName }} (“we,” “us,” or “our”) provides a CRM and browser extension that help users manage
                        LinkedIn and multi-channel outreach, audiences, campaigns, messaging, and related sales workflows
                        (the “Services”), including our website at
                        <a href="https://socifusion.com" class="text-blue-600 underline underline-offset-2 dark:text-blue-400">socifusion.com</a>
                        and the {{ appName }} Chrome extension.
                    </p>
                    <p>
                        This Privacy Policy explains what information we collect, how we use it, how we share it, and the
                        choices you have. By using the Services, you agree to this policy.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">2. Information we collect</h2>
                    <p>We collect information in the following categories:</p>

                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Account and profile information</h3>
                    <p>
                        When you create or use an account, we collect information such as your name, email address,
                        password (stored in hashed form), team membership, and billing or license-related details needed
                        to provide access to the Services.
                    </p>

                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">CRM and outreach data</h3>
                    <p>
                        You and your team may store business contact and campaign data in the CRM, including lead and
                        audience lists, profile identifiers and URLs, names, job titles, companies, emails (including
                        enriched emails), notes, tags, conversation history, message templates, campaign settings,
                        calendars/call bookings, and related activity logs.
                    </p>

                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Connected accounts and integrations</h3>
                    <p>
                        If you connect LinkedIn or other channels, we process connection status and technical identifiers
                        needed to operate those integrations (for example account IDs provided by our messaging/integration
                        providers). Depending on the features you use, we may process profile, search, messaging, invitation,
                        and related social data that you instruct us to retrieve or act on through those integrations.
                    </p>

                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Browser extension data</h3>
                    <p>
                        The {{ appName }} Chrome extension may access information required to run LinkedIn outreach features
                        on your behalf, including:
                    </p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>Authentication and session data for LinkedIn (such as cookies needed to confirm you are logged in and to perform requested actions)</li>
                        <li>Data you select or generate in the extension UI (audiences, search criteria, message content, action settings)</li>
                        <li>Local extension storage used to keep preferences, session tokens for the CRM API, and feature state</li>
                        <li>Page context on LinkedIn when you use extension features on that site</li>
                    </ul>
                    <p>
                        The extension communicates with our CRM API at socifusion.com (and related app domains) to sync
                        your workspaces, audiences, campaigns, and account settings.
                    </p>

                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">AI-assisted content</h3>
                    <p>
                        If you use AI writing or content features, we process the prompts, context, and generated outputs
                        you submit or create so we can provide those features.
                    </p>

                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Usage, device, and technical data</h3>
                    <p>
                        We automatically collect technical information such as IP address, browser type, device information,
                        approximate location derived from IP, pages or screens viewed, referring URLs, timestamps, and
                        diagnostic logs needed to operate, secure, and improve the Services.
                    </p>

                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Communications</h3>
                    <p>
                        If you contact us or receive product emails (for example account, security, or service notices),
                        we process the content of those communications and related metadata.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">3. How we use information</h2>
                    <p>We use personal information to:</p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>Provide, operate, maintain, and improve the Services</li>
                        <li>Authenticate users and manage accounts, teams, and entitlements</li>
                        <li>Execute outreach, messaging, search, import, enrichment, and CRM workflows you request</li>
                        <li>Power AI-assisted drafting and related features you choose to use</li>
                        <li>Process payments, licenses, and customer support requests</li>
                        <li>Monitor performance, prevent abuse, and protect the security of the Services</li>
                        <li>Send important service communications</li>
                        <li>Comply with legal obligations and enforce our terms</li>
                    </ul>
                    <p>
                        We do not sell your personal information. We do not use LinkedIn account credentials or session
                        cookies for advertising networks or unrelated third-party marketing.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">4. How we share information</h2>
                    <p>We may share information with:</p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>
                            <span class="font-medium text-slate-900 dark:text-slate-100">Service providers</span>
                            that help us host infrastructure, send email, process payments, provide AI models, connect
                            messaging channels (including LinkedIn integrations), monitor errors, and deliver customer
                            support — only as needed to perform services for us and under appropriate safeguards
                        </li>
                        <li>
                            <span class="font-medium text-slate-900 dark:text-slate-100">Team members</span>
                            within your organization who are granted access to your workspace
                        </li>
                        <li>
                            <span class="font-medium text-slate-900 dark:text-slate-100">Legal and safety recipients</span>
                            when required by law, legal process, or to protect rights, safety, and integrity
                        </li>
                        <li>
                            <span class="font-medium text-slate-900 dark:text-slate-100">Business transfers</span>
                            in connection with a merger, acquisition, financing, or sale of assets, subject to
                            appropriate confidentiality protections
                        </li>
                    </ul>
                    <p>
                        Social platforms (such as LinkedIn) process data according to their own policies when you use
                        features that interact with those platforms.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">5. Cookies and similar technologies</h2>
                    <p>
                        Our website and app use cookies and similar technologies for authentication, session management,
                        security, preferences (such as appearance), and basic product analytics needed to run the Services.
                        The Chrome extension may read LinkedIn cookies strictly to perform the LinkedIn-related actions
                        you initiate while using the extension.
                    </p>
                    <p>
                        You can control cookies through your browser settings. Disabling certain cookies may limit
                        functionality of the Services.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">6. Data retention</h2>
                    <p>
                        We retain personal information for as long as your account remains active or as needed to provide
                        the Services, resolve disputes, enforce agreements, and meet legal, tax, and accounting requirements.
                        You may request deletion of your account or specific CRM data subject to legal retention needs and
                        technical limitations.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">7. Security</h2>
                    <p>
                        We use administrative, technical, and organizational measures designed to protect personal
                        information, including encrypted transport (HTTPS), access controls, and hashed passwords.
                        No method of transmission or storage is completely secure, and we cannot guarantee absolute security.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">8. International transfers</h2>
                    <p>
                        We may process and store information in countries other than where you live. Where required, we
                        use appropriate safeguards for cross-border transfers.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">9. Your rights and choices</h2>
                    <p>
                        Depending on your location, you may have rights to access, correct, delete, or export personal
                        information; object to or restrict certain processing; and withdraw consent where processing is
                        based on consent. You can usually update account details in the product, uninstall the extension
                        at any time, and disconnect integrations from your settings.
                    </p>
                    <p>
                        To exercise privacy rights, contact us using the details below. We may need to verify your identity
                        before fulfilling a request.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">10. Children’s privacy</h2>
                    <p>
                        The Services are intended for business users and are not directed to children under 16. We do not
                        knowingly collect personal information from children. If you believe a child has provided us
                        personal information, contact us and we will take appropriate steps to delete it.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">11. Third-party services</h2>
                    <p>
                        The Services may link to or integrate with third-party products (for example LinkedIn, AI providers,
                        payment processors, and hosting providers). Their collection and use of information is governed by
                        their own privacy policies. We encourage you to review those policies.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">12. Changes to this policy</h2>
                    <p>
                        We may update this Privacy Policy from time to time. We will post the updated version on this page
                        and revise the “Last updated” date. Continued use of the Services after changes become effective
                        constitutes acceptance of the updated policy.
                    </p>
                </section>

                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">13. Contact us</h2>
                    <p>
                        If you have questions about this Privacy Policy or our data practices, contact us at:
                    </p>
                    <p>
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ appName }}</span><br />
                        Email:
                        <a href="mailto:privacy@socifusion.com" class="text-blue-600 underline underline-offset-2 dark:text-blue-400">privacy@socifusion.com</a><br />
                        Website:
                        <a href="https://socifusion.com" class="text-blue-600 underline underline-offset-2 dark:text-blue-400">https://socifusion.com</a>
                    </p>
                </section>
            </div>
        </main>

        <footer class="border-t border-slate-200/80 py-8 text-center text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
            © {{ new Date().getFullYear() }} {{ appName }}. All rights reserved.
        </footer>
    </div>
</template>
