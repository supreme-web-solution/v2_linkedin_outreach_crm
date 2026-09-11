<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\V2\Ai\Services\ChannelIdentityService;
use App\V2\Ai\Services\OnboardingWizardService;
use App\V2\Ai\Services\WhatsAppCommandLinkPresenter;
use App\V2\Integrations\Unipile\UnipileException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingWebController extends Controller
{
    public function status(OnboardingWizardService $wizard): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        return response()->json($wizard->status($user, $orgId));
    }

    public function chat(Request $request, OnboardingWizardService $wizard): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $reply = $wizard->chatTurn($user, $orgId, (string) ($data['message'] ?? ''));

        return response()->json([
            'reply' => $reply,
            'status' => $wizard->status($user, $orgId),
        ]);
    }

    public function selectGoal(Request $request, OnboardingWizardService $wizard): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'goal' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($wizard->selectGoal($user, $orgId, $data['goal']));
    }

    public function connect(Request $request, OnboardingWizardService $wizard, string $channel): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        if ($channel === 'whatsapp_command') {
            $code = app(ChannelIdentityService::class)->createLinkCode($user, $orgId, 'whatsapp');

            return response()->json(array_merge(
                ['kind' => 'whatsapp_command'],
                app(WhatsAppCommandLinkPresenter::class)->payload($code),
            ));
        }

        try {
            $result = $wizard->connectUrl($user, $orgId, $channel, $request);
        } catch (UnipileException $e) {
            return response()->json([
                'kind' => 'error',
                'message' => $e->getMessage(),
                'hint' => $e->context['hint'] ?? null,
                'error_code' => $e->context['error_code'] ?? null,
            ], $e->statusCode >= 400 && $e->statusCode < 600 ? $e->statusCode : 503);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'kind' => 'error',
                'message' => 'Could not start '.$channel.' connection. Please try again.',
                'hint' => 'If this keeps failing, check UNIPILE_BASE_URL and UNIPILE_API_KEY in .env, then run php artisan config:clear.',
            ], 503);
        }

        $redirectUrl = (string) ($result['redirect_url'] ?? '');
        if ($redirectUrl === '') {
            return response()->json([
                'kind' => 'error',
                'message' => 'Unipile did not return a connection link.',
                'hint' => 'Check UNIPILE_BASE_URL / UNIPILE_API_KEY, or open Integrations and try Connect again.',
            ], 503);
        }

        return response()->json(array_merge(['kind' => 'hosted_auth'], $result));
    }

    public function complete(OnboardingWizardService $wizard): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $wizard->complete($user, $orgId);

        return response()->json($wizard->status($user, $orgId));
    }

    public function dismiss(OnboardingWizardService $wizard): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $wizard->dismiss($user, $orgId);

        return response()->json(['dismissed' => true]);
    }

    public function skipWhatsappCommand(OnboardingWizardService $wizard): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        return response()->json($wizard->skipWhatsappCommand($user, $orgId));
    }

    public function businessProfile(Request $request, OnboardingWizardService $wizard): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:10000'],
            'website_url' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,txt'],
        ]);

        try {
            $result = $wizard->submitBusinessProfile(
                $user,
                $orgId,
                $data['description'] ?? null,
                $data['website_url'] ?? null,
                $request->file('file'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function conversionAssets(Request $request, OnboardingWizardService $wizard): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'sales_page_url' => ['nullable', 'string', 'max:500'],
            'webinar_url' => ['nullable', 'string', 'max:500'],
        ]);

        if (trim((string) ($data['sales_page_url'] ?? '')) === '' && trim((string) ($data['webinar_url'] ?? '')) === '') {
            return response()->json(['message' => 'Add at least one — a sales page URL or a webinar URL.'], 422);
        }

        try {
            $result = $wizard->submitConversionAssets(
                $user,
                $orgId,
                $data['sales_page_url'] ?? null,
                $data['webinar_url'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
