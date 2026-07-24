<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\V2AutoResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutoResponsesWebController extends Controller
{
    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $rules = $orgId
            ? V2AutoResponse::where('organization_id', $orgId)
                ->where('user_id', $user->id)
                ->latest()
                ->get()
                ->map(fn (V2AutoResponse $r) => [
                    'id' => $r->id,
                    'message_type' => $r->message_type,
                    'message_keywords' => $r->message_keywords,
                    'message_body' => $r->message_body,
                    'enabled' => (bool) $r->enabled,
                    'created_at' => $r->created_at?->toIso8601String(),
                ])
            : collect();

        return Inertia::render('crm/AutoResponses', [
            'rules' => $rules,
            'hasOrg' => (bool) $orgId,
            'messageTypes' => [
                ['value' => 'contains', 'label' => 'Contains keyword'],
                ['value' => 'exact', 'label' => 'Exact match'],
                ['value' => 'starts_with', 'label' => 'Starts with'],
                ['value' => 'regex', 'label' => 'Regex pattern'],
                ['value' => 'any', 'label' => 'Any message'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        if ($orgId <= 0) {
            return back()->with('error', 'Connect a workspace first.');
        }

        $data = $request->validate([
            'message_type' => ['required', 'string', 'max:50'],
            'message_keywords' => ['nullable', 'string', 'max:255'],
            'message_body' => ['required', 'string'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        V2AutoResponse::create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
            'message_type' => $data['message_type'],
            'message_keywords' => $data['message_keywords'] ?? null,
            'message_body' => $data['message_body'],
            'attachments' => [],
            'enabled' => $data['enabled'] ?? true,
        ]);

        return back()->with('success', 'Auto-response rule created.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $rule = V2AutoResponse::where('organization_id', $orgId)
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'message_type' => ['nullable', 'string', 'max:50'],
            'message_keywords' => ['nullable', 'string', 'max:255'],
            'message_body' => ['nullable', 'string'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $rule->forceFill(array_filter([
            'message_type' => $data['message_type'] ?? null,
            'message_keywords' => array_key_exists('message_keywords', $data) ? $data['message_keywords'] : null,
            'message_body' => $data['message_body'] ?? null,
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : null,
        ], fn ($v) => $v !== null))->save();

        return back()->with('success', 'Rule updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        V2AutoResponse::where('organization_id', $orgId)
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail()
            ->delete();

        return back()->with('success', 'Rule deleted.');
    }

    public function toggle(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $rule = V2AutoResponse::where('organization_id', $orgId)
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        $rule->forceFill(['enabled' => ! $rule->enabled])->save();

        return back()->with('success', $rule->enabled ? 'Rule enabled.' : 'Rule disabled.');
    }
}
