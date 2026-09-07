<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\V2\Ai\Services\ChannelIdentityService;
use App\V2\Ai\Services\OnboardingWizardService;
use App\V2\Ai\Services\WhatsAppCommandLinkPresenter;
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

        $result = $wizard->connectUrl($user, $orgId, $channel, $request);

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
}
