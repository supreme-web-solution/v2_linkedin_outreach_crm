<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\V2EspDelivery;
use App\Models\V2EspIntegration;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\ChannelConnectionService;
use App\V2\Services\LinkedInConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationWebController extends Controller
{
    public function __construct(
        private readonly LinkedInConnectionService $linkedin,
        private readonly ChannelConnectionService $channels,
    ) {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $accountId = (string) $request->query('account_id', '');
        $channelKey = (string) $request->query('channel', '');

        if ($accountId !== '' && ($channelKey !== '' || $request->boolean('connected'))) {
            try {
                $account = $this->channels->completeHostedConnection($user, $accountId, $channelKey !== '' ? $channelKey : null);
                $resolvedChannel = (string) (is_array($account->meta) ? ($account->meta['channel_key'] ?? $channelKey) : $channelKey);
                $label = OutreachChannelRegistry::channelLabel($resolvedChannel !== '' ? $resolvedChannel : 'email');

                return redirect()->route('integrations')->with('success', "{$label} connected successfully.");
            } catch (\Throwable $e) {
                return redirect()->route('integrations')->with('error', 'Could not save channel connection: '.$e->getMessage());
            }
        }

        $orgId = (int) ($user->current_organization_id ?? 0);

        $this->linkedin->consolidateProviderAccount($user->id, 'linkedin');

        try {
            $accountsPayload = $this->linkedin->verifyUserAccounts($user);
        } catch (\Throwable) {
            $account = $this->linkedin->consolidateProviderAccount($user->id, 'linkedin');
            $accountsPayload = $account
                ? [$this->linkedin->serializeAccount($account)]
                : [];
        }

        $espIntegrations = $orgId
            ? V2EspIntegration::where('organization_id', $orgId)
                ->latest()
                ->get()
                ->map(fn (V2EspIntegration $esp) => [
                    'id' => $esp->id,
                    'provider' => $esp->provider,
                    'enabled' => (bool) $esp->enabled,
                    'created_at' => $esp->created_at?->toIso8601String(),
                    'has_api_key' => ! empty(is_array($esp->config) ? ($esp->config['api_key'] ?? null) : null),
                ])
            : collect();

        $deliveryStats = $orgId ? [
            'total' => V2EspDelivery::where('organization_id', $orgId)->count(),
            'sent' => V2EspDelivery::where('organization_id', $orgId)->where('status', 'sent')->count(),
            'failed' => V2EspDelivery::where('organization_id', $orgId)->where('status', 'failed')->count(),
        ] : ['total' => 0, 'sent' => 0, 'failed' => 0];

        return Inertia::render('crm/Integrations', [
            'accounts' => $accountsPayload,
            'espIntegrations' => $espIntegrations,
            'hasOrg' => (bool) $orgId,
            'connected' => $request->boolean('connected') && $channelKey === '' && $accountId === '',
            'connectedChannel' => $channelKey !== '' ? $channelKey : null,
            'connectionError' => $request->boolean('error') && $channelKey === '',
            'channelConnectionError' => $request->boolean('error') && $channelKey !== '' ? $channelKey : null,
            'unipileConfigured' => $this->linkedin->isUnipileConfigured(),
            'unipileWebhookCallbackUrl' => $this->linkedin->webhookCallbackUrl(request()),
            'deliveryStats' => $deliveryStats,
            'connectedChannels' => $this->channels->summarizeForUser($user),
            'espProviders' => [
                ['key' => 'mailchimp', 'label' => 'Mailchimp', 'fields' => ['api_key', 'audience_id'], 'wired' => true],
                ['key' => 'sendgrid', 'label' => 'SendGrid', 'fields' => ['api_key', 'audience_id'], 'wired' => true],
                ['key' => 'hubspot', 'label' => 'HubSpot', 'fields' => ['api_key'], 'wired' => true],
                ['key' => 'klaviyo', 'label' => 'Klaviyo', 'fields' => ['api_key', 'audience_id'], 'wired' => true],
                ['key' => 'brevo', 'label' => 'Brevo (Sendinblue)', 'fields' => ['api_key', 'audience_id'], 'wired' => true],
                ['key' => 'activecampaign', 'label' => 'ActiveCampaign', 'fields' => ['api_key', 'api_base', 'audience_id'], 'wired' => true],
                ['key' => 'mailerlite', 'label' => 'MailerLite', 'fields' => ['api_key', 'audience_id'], 'wired' => true],
                ['key' => 'convertkit', 'label' => 'ConvertKit', 'fields' => ['api_key', 'audience_id'], 'wired' => true],
                ['key' => 'getresponse', 'label' => 'GetResponse', 'fields' => ['api_key', 'audience_id'], 'wired' => true],
            ],
        ]);
    }

    public function startUnipileHostedAuth(Request $request): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if (! $this->linkedin->isUnipileConfigured()) {
            return redirect()->route('integrations')->with('error', 'LinkedIn messaging is not available on this server.');
        }

        try {
            $result = $this->linkedin->createHostedAuthLink($user, $request);
            $url = $result['url'] ?? $result['link'] ?? null;

            if (! $url) {
                return redirect()->route('integrations')->with('error', 'Could not start LinkedIn secure login. Please try again.');
            }

            if ($request->header('X-Inertia')) {
                return Inertia::location($url);
            }

            return redirect()->away($url);
        } catch (\Throwable $e) {
            return redirect()->route('integrations')->with('error', 'Could not start LinkedIn connection: '.$e->getMessage());
        }
    }

    public function connectUnipileCookie(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'li_at' => ['required', 'string', 'max:2048'],
            'user_agent' => ['nullable', 'string', 'max:512'],
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);

        try {
            $this->linkedin->connectViaCookie(
                $user,
                $data['li_at'],
                trim((string) ($data['user_agent'] ?? $request->userAgent() ?? '')) ?: 'LinkedEmpire/2.0',
                $orgId
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Connection failed: '.$e->getMessage());
        }

        return back()->with('success', 'LinkedIn connected successfully.');
    }

    public function verifyUnipile(): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        try {
            $results = $this->linkedin->verifyUserAccounts($user);
            $disconnected = collect($results)->first(fn ($row) => ($row['live_status'] ?? null) === 'disconnected' || ($row['status'] ?? null) === 'disconnected');

            if ($disconnected) {
                return back()->with('error', 'LinkedIn is disconnected. Paste a fresh li_at cookie or use the browser extension to detect and save your session.');
            }

            return back()->with('success', 'LinkedIn connection verified.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not verify LinkedIn connection: '.$e->getMessage());
        }
    }

    public function disconnectUnipile(int $id): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        try {
            $this->linkedin->disconnect($user, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return back()->with('error', 'Account not found.');
        }

        return back()->with('success', 'LinkedIn account disconnected.');
    }

    public function startChannelHostedAuth(Request $request, string $channel): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if (! $this->channels->isUnipileConfigured()) {
            return redirect()->route('integrations')->with('error', 'Channel messaging is not available on this server.');
        }

        try {
            $result = $this->channels->createHostedAuthLink(
                $user,
                $channel,
                $request,
                '/integrations?connected=1&channel='.$channel,
                '/integrations?error=1&channel='.$channel,
            );
            $url = $result['url'] ?? $result['link'] ?? null;

            if (! $url) {
                return redirect()->route('integrations')->with('error', 'Could not start channel connection. Please try again.');
            }

            if ($request->header('X-Inertia')) {
                return Inertia::location($url);
            }

            return redirect()->away($url);
        } catch (\Throwable $e) {
            return redirect()->route('integrations')->with('error', 'Could not start connection: '.$e->getMessage());
        }
    }

    public function disconnectChannel(string $channel): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        try {
            $this->channels->disconnect($user, $channel);
        } catch (\InvalidArgumentException) {
            return back()->with('error', 'Unknown channel.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return back()->with('error', 'This channel is not connected.');
        }

        $label = OutreachChannelRegistry::channelLabel($channel);

        return back()->with('success', "{$label} disconnected.");
    }

    public function storeEsp(Request $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);

        if ($orgId <= 0) {
            return back()->with('error', 'Connect a workspace before configuring ESP integrations.');
        }

        $data = $request->validate([
            'provider' => ['required', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'api_base' => ['nullable', 'string', 'max:500'],
            'audience_id' => ['nullable', 'string', 'max:191'],
            'from_email' => ['nullable', 'email', 'max:191'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'portal_id' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $config = array_filter([
            'api_key' => $data['api_key'] ?? null,
            'api_base' => $data['api_base'] ?? null,
            'audience_id' => $data['audience_id'] ?? null,
            'from_email' => $data['from_email'] ?? null,
            'from_name' => $data['from_name'] ?? null,
            'portal_id' => $data['portal_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $existing = V2EspIntegration::where('organization_id', $orgId)
            ->where('provider', $data['provider'])
            ->first();

        if ($existing && empty($config['api_key'])) {
            $existingConfig = is_array($existing->config) ? $existing->config : [];
            $config = array_merge($existingConfig, array_diff_key($config, ['api_key' => '']));
        }

        V2EspIntegration::updateOrCreate(
            [
                'organization_id' => $orgId,
                'provider' => $data['provider'],
            ],
            [
                'user_id' => $user->id,
                'config' => $config,
                'enabled' => $data['enabled'] ?? true,
            ]
        );

        return back()->with('success', ucfirst($data['provider']).' integration saved.');
    }

    public function toggleEsp(int $id): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);

        $esp = V2EspIntegration::where('organization_id', $orgId)
            ->where('id', $id)
            ->firstOrFail();

        $esp->forceFill(['enabled' => ! $esp->enabled])->save();

        return back()->with('success', $esp->enabled ? 'Integration enabled.' : 'Integration disabled.');
    }

    public function destroyEsp(int $id): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);

        V2EspIntegration::where('organization_id', $orgId)
            ->where('id', $id)
            ->firstOrFail()
            ->delete();

        return back()->with('success', 'ESP integration removed.');
    }
}
