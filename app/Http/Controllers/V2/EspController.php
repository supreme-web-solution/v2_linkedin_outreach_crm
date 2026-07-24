<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2EspDelivery;
use App\Models\V2EspIntegration;
use App\Models\V2Lead;
use App\V2\Services\Esp\EspProviderAdapterFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EspController extends Controller
{
    public function __construct(private readonly EspProviderAdapterFactory $adapterFactory)
    {
    }

    public function config(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $rows = V2EspIntegration::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:100'],
            'config' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $row = V2EspIntegration::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'provider' => $data['provider'],
            ],
            [
                'user_id' => $user->id,
                'config' => $data['config'] ?? [],
                'enabled' => $data['enabled'] ?? true,
            ]
        );

        return response()->json(['data' => $row]);
    }

    public function exportLeads(Request $request): StreamedResponse
    {
        $user = $request->attributes->get('v2User');
        $leadIds = $request->input('lead_ids', []);

        $query = V2Lead::query()->where('user_id', $user->id);
        if (is_array($leadIds) && !empty($leadIds)) {
            $query->whereIn('id', $leadIds);
        }

        $rows = $query->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['id', 'name', 'headline', 'company', 'location', 'email', 'public_identifier']);
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->id,
                    $row->full_name,
                    $row->headline,
                    $row->company_name,
                    $row->location,
                    $row->email,
                    $row->public_identifier,
                ]);
            }
            fclose($handle);
        }, 'v2-leads-export.csv');
    }

    public function deliveries(Request $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $rows = V2EspDelivery::query()
            ->where('organization_id', $organizationId)
            ->when($request->filled('provider'), function ($query) use ($request) {
                $query->where('provider', (string) $request->query('provider'));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', (string) $request->query('status'));
            })
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function pushLeads(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:100'],
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer'],
            'subject' => ['nullable', 'string', 'max:191'],
            'body' => ['nullable', 'string'],
        ]);

        $integration = V2EspIntegration::query()
            ->where('organization_id', $organizationId)
            ->where('provider', $data['provider'])
            ->where('enabled', true)
            ->first();

        if (!$integration) {
            return response()->json(['message' => 'ESP integration is not configured or disabled.'], 422);
        }

        $leads = V2Lead::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $data['lead_ids'])
            ->get();

        $created = [];
        foreach ($leads as $lead) {
            $recipient = trim((string) ($lead->email ?? ''));
            $status = 'failed';
            $providerDispatch = null;
            $error = $recipient === '' ? 'Lead has no email address.' : null;

            if ($recipient !== '') {
                try {
                    $providerDispatch = $this->adapterFactory
                        ->make((string) $data['provider'])
                        ->dispatch(
                            is_array($integration->config) ? $integration->config : [],
                            [
                                'recipient' => $recipient,
                                'subject' => $data['subject'] ?? null,
                                'body' => $data['body'] ?? null,
                                'first_name' => $lead->full_name ? explode(' ', (string) $lead->full_name)[0] : '',
                                'last_name' => $lead->full_name ? trim(substr((string) $lead->full_name, strlen((string) explode(' ', (string) $lead->full_name)[0]))) : '',
                                'lead_id' => $lead->id,
                            ]
                        );
                    $status = 'sent';
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                    $providerDispatch = [
                        'error' => $error,
                    ];
                }
            }

            $delivery = V2EspDelivery::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'esp_integration_id' => $integration->id,
                'lead_id' => $lead->id,
                'provider' => $data['provider'],
                'recipient' => $recipient !== '' ? $recipient : (string) ($lead->public_identifier ?: ''),
                'status' => $status,
                'external_message_id' => $status === 'sent'
                    ? (string) ($providerDispatch['provider_meta']['member_id'] ?? ('esp_'.Str::random(24)))
                    : null,
                'subject' => $data['subject'] ?? null,
                'body_preview' => Str::limit((string) ($data['body'] ?? ''), 280),
                'events' => [[
                    'status' => $status,
                    'at' => now()->toIso8601String(),
                    'error' => $error,
                ]],
                'meta' => [
                    'integration_provider' => $integration->provider,
                    'provider_dispatch' => $providerDispatch,
                    'error' => $error,
                ],
                'sent_at' => $status === 'sent' ? now() : null,
                'failed_at' => $status === 'failed' ? now() : null,
            ]);

            $created[] = $delivery;
        }

        $sent = collect($created)->where('status', 'sent')->count();
        $failed = collect($created)->where('status', 'failed')->count();
        $errors = collect($created)
            ->filter(fn ($d) => $d->status === 'failed')
            ->map(fn ($d) => (string) (is_array($d->meta) ? ($d->meta['error'] ?? 'Failed') : 'Failed'))
            ->unique()
            ->values()
            ->all();

        $summary = $sent > 0 && $failed === 0
            ? "Subscribed {$sent} contact(s) to {$data['provider']}."
            : ($sent > 0
                ? "Subscribed {$sent}, failed {$failed}."
                : "Push failed for all {$failed} contact(s).");

        $payload = [
            'message' => $summary,
            'data' => [
                'total' => count($created),
                'sent' => $sent,
                'failed' => $failed,
                'deliveries' => $created,
                'message' => $summary,
                'errors' => $errors,
            ],
        ];

        return response()->json($payload, $sent > 0 ? 201 : 422);
    }

    public function ingestDeliveryFeedback(Request $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:100'],
            'external_message_id' => ['required', 'string', 'max:191'],
            'status' => ['required', 'string', 'in:delivered,opened,clicked,bounced,failed'],
            'meta' => ['nullable', 'array'],
        ]);

        $integration = V2EspIntegration::query()
            ->where('organization_id', $organizationId)
            ->where('provider', $data['provider'])
            ->first();

        if (!$integration) {
            return response()->json(['message' => 'ESP integration not found for provider.'], 404);
        }

        if (!$this->verifyFeedbackSignature($request, $integration)) {
            return response()->json(['message' => 'Invalid ESP callback signature.'], 401);
        }

        $delivery = V2EspDelivery::query()
            ->where('organization_id', $organizationId)
            ->where('provider', $data['provider'])
            ->where('external_message_id', $data['external_message_id'])
            ->first();

        if (!$delivery) {
            return response()->json(['message' => 'Delivery not found.'], 404);
        }

        $events = is_array($delivery->events) ? $delivery->events : [];
        $events[] = [
            'status' => $data['status'],
            'at' => now()->toIso8601String(),
            'meta' => $data['meta'] ?? [],
        ];

        $payload = [
            'status' => $data['status'],
            'events' => $events,
        ];

        if ($data['status'] === 'delivered') {
            $payload['delivered_at'] = now();
        }
        if ($data['status'] === 'opened') {
            $payload['opened_at'] = now();
        }
        if ($data['status'] === 'clicked') {
            $payload['clicked_at'] = now();
        }
        if ($data['status'] === 'bounced') {
            $payload['bounced_at'] = now();
        }
        if ($data['status'] === 'failed') {
            $payload['failed_at'] = now();
        }

        $delivery->forceFill($payload)->save();

        return response()->json(['data' => $delivery]);
    }

    private function verifyFeedbackSignature(Request $request, V2EspIntegration $integration): bool
    {
        return $this->adapterFactory
            ->make($integration->provider)
            ->verifySignature(
                is_array($integration->config) ? $integration->config : [],
                $request->headers->all(),
                (string) $request->getContent()
            );
    }
}
