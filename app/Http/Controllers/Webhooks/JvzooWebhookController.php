<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeLicenseMail;
use App\Models\User;
use App\Models\V2Product;
use App\Models\V2ProductTransaction;
use App\V2\Services\EntitlementService;
use App\V2\Services\UserBootstrapService;
use App\V2\Services\UserDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class JvzooWebhookController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly UserBootstrapService $bootstrap,
        private readonly UserDeletionService $deletion,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->verify($request)) {
            Log::warning('[JVZoo] IPN verification failed');

            return response()->json(['message' => 'Verification failed.'], 403);
        }

        $data = $request->all();
        $type = (string) ($data['ctransaction'] ?? 'SALE');
        $email = (string) ($data['ccustemail'] ?? '');
        $productId = (string) ($data['cproditem'] ?? '');
        $transactionId = (string) ($data['ctransreceipt'] ?? '');

        if ($email === '' || $productId === '' || $transactionId === '') {
            return response()->json(['message' => 'Missing required fields.'], 422);
        }

        $product = V2Product::query()->where('product_id', $productId)->first();
        if (! $product) {
            Log::warning('[JVZoo] Unknown product', ['product_id' => $productId]);

            return response()->json(['message' => 'Product not found.'], 404);
        }

        if (V2ProductTransaction::query()
            ->where('transaction_id', $transactionId)
            ->where('transaction_type', $type)
            ->exists()) {
            return response()->json(['message' => 'Already processed.']);
        }

        return match ($type) {
            'SALE' => $this->handleSale($email, $product, $transactionId, $data),
            'RFND' => $this->handleRefund($email, $product, $transactionId, $data),
            default => response()->json(['message' => 'Unsupported transaction type.'], 422),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleSale(string $email, V2Product $product, string $transactionId, array $payload): JsonResponse
    {
        $plainPassword = null;
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $plainPassword = Str::random(12);
            $user = User::create([
                'name' => Str::before($email, '@'),
                'email' => $email,
                'password' => bcrypt($plainPassword),
                'created_by' => 1,
            ]);
        }

        $this->entitlements->grant($user, $product->entitlements ?? []);
        $this->bootstrap->ensurePersonalOrganization($user);

        V2ProductTransaction::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->product_id,
            'transaction_id' => $transactionId,
            'transaction_type' => 'SALE',
            'payload' => $payload,
        ]);

        if ($plainPassword) {
            Mail::to($email)->send(new WelcomeLicenseMail($user, $plainPassword));
        }

        return response()->json(['message' => 'Sale processed.']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleRefund(string $email, V2Product $product, string $transactionId, array $payload): JsonResponse
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        V2ProductTransaction::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->product_id,
            'transaction_id' => $transactionId,
            'transaction_type' => 'RFND',
            'payload' => $payload,
        ]);

        try {
            $this->deletion->delete($user);
        } catch (\Throwable $e) {
            Log::error('[JVZoo] Refund delete failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Refund processed.']);
    }

    private function verify(Request $request): bool
    {
        $secret = (string) config('billing.jvzoo_secret', '');
        if ($secret === '') {
            return app()->environment('local') && config('app.debug');
        }

        $cverify = (string) ($request->input('cverify') ?? '');
        if ($cverify === '') {
            return false;
        }

        $ipnFields = [];
        foreach ($request->all() as $key => $value) {
            if ($key === 'cverify') {
                continue;
            }
            $ipnFields[] = $key;
        }

        sort($ipnFields);
        $pop = '';
        foreach ($ipnFields as $field) {
            $pop .= $request->input($field).'|';
        }
        $pop .= $secret;

        if ('UTF-8' !== mb_detect_encoding($pop)) {
            $pop = mb_convert_encoding($pop, 'UTF-8');
        }

        $calced = strtoupper(substr(sha1($pop), 0, 8));

        return hash_equals($calced, strtoupper($cverify));
    }
}
