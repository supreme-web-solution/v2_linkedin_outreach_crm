<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\V2\Ai\Integrations\ZernioClient;
use App\V2\Ai\Services\ZernioWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZernioWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ZernioClient $zernio,
        ZernioWebhookService $webhook,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Zernio-Signature')
            ?? $request->header('X-Late-Signature');

        $legacySecret = $request->header('X-Zernio-Secret')
            ?? $request->header('X-Webhook-Secret')
            ?? $request->query('secret');

        $authorized = $zernio->verifyWebhookSignature($rawBody, is_string($signature) ? $signature : null)
            || $zernio->verifyLegacySecret(is_string($legacySecret) ? $legacySecret : null);

        if (! $authorized) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON payload.'], 400);
        }

        $result = $webhook->handle($payload);

        return response()->json($result['body'], $result['status']);
    }
}
