<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\FetchAudienceEmailBatchJob;
use App\Jobs\FetchAudienceEmailJob;
use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\SnLeadsCompany;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2Lead;
use App\Models\V2LeadSource;
use App\V2\Services\UnipileProfileEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class LeadsWebController extends Controller
{
    public function index(): Response
    {
        $userId = Auth::id();

        $audiences = Audience::where('user_id', $userId)
            ->select('id', 'audience_name', 'audience_id', 'source', 'created_at')
            ->selectRaw('(select count(*) from audience_lists where audience_lists.audience_id = audiences.audience_id) as total_leads')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'list_name' => $a->audience_name ?: 'Untitled audience',
                'list_hash' => (string) $a->audience_id,
                'total_leads' => (int) $a->total_leads,
                'source' => 'Audience',
                'src' => 'aud',
                'created_at' => optional($a->created_at)->toIso8601String(),
            ]);

        $snLists = SnLeadList::where('user_id', $userId)
            ->select('id', 'name', 'list_hash', 'created_at')
            ->selectRaw('(select count(*) from sn_leads where sn_leads.sn_list_id = sn_leads_lists.list_hash) as total_leads')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'list_name' => $l->name ?: 'Untitled list',
                'list_hash' => (string) $l->list_hash,
                'total_leads' => (int) $l->total_leads,
                'source' => 'Sales Navigator',
                'src' => 'sn',
                'created_at' => optional($l->created_at)->toIso8601String(),
            ]);

        $lists = $audiences->concat($snLists)->sortBy('list_name')->values();

        $stats = [
            'total_lists' => $lists->count(),
            'audience_lists' => $audiences->count(),
            'sn_lists' => $snLists->count(),
            'total_leads' => (int) ($audiences->sum('total_leads') + $snLists->sum('total_leads')),
        ];

        return Inertia::render('crm/Leads/Index', [
            'lists' => $lists,
            'stats' => $stats,
        ]);
    }

    public function show(Request $request, string $listId): Response
    {
        $src = $request->query('src', 'aud');
        $emailFilter = $request->query('email_filter', 'all');
        $search = (string) $request->query('search', '');

        $listName = 'Leads';
        $counts = [];
        $listRecordId = null;

        if ($src === 'aud') {
            $audience = Audience::where('audience_id', $listId)->where('user_id', Auth::id())->first();
            $listName = $audience?->audience_name ?: 'Audience';

            $query = AudienceList::where('audience_id', $listId);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('con_first_name', 'like', "%{$search}%")
                        ->orWhere('con_last_name', 'like', "%{$search}%")
                        ->orWhere('con_job_title', 'like', "%{$search}%")
                        ->orWhere('con_location', 'like', "%{$search}%")
                        ->orWhere('con_email', 'like', "%{$search}%");
                });
            }
            $this->applyEmailFilter($query, $emailFilter);

            $leads = $query->latest()->paginate(20)->appends($request->query())
                ->through(fn (AudienceList $row) => $this->transformAudLead($row));

            $counts = $this->emailFilterCounts($listId);
        } else {
            $list = SnLeadList::where('list_hash', $listId)->where('user_id', Auth::id())->first();
            $listName = $list?->name ?: 'Sales Navigator';
            $listRecordId = $list?->id;

            $query = SnLead::where('sn_list_id', $listId);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('headline', 'like', "%{$search}%")
                        ->orWhere('geolocation', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $leads = $query->latest()->paginate(20)->appends($request->query())
                ->through(fn (SnLead $row) => $this->transformSnLead($row));
        }

        return Inertia::render('crm/Leads/Show', [
            'leads' => $leads,
            'listId' => (string) $listId,
            'listRecordId' => $listRecordId,
            'listName' => $listName,
            'src' => $src,
            'emailFilter' => $emailFilter,
            'search' => $search,
            'counts' => $counts,
            'dailyLimit' => $this->dailyLimitPayload(),
            'pendingCount' => $src === 'aud' ? $this->getPendingEmailFetchCount(Auth::id()) : 0,
        ]);
    }

    public function updateList(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'list_name' => ['required', 'string', 'max:255'],
            'src' => ['required', 'in:aud,sn'],
        ]);

        if ($data['src'] === 'aud') {
            Audience::where('id', $id)->where('user_id', Auth::id())->update(['audience_name' => $data['list_name']]);
        } else {
            SnLeadList::where('id', $id)->where('user_id', Auth::id())->update(['name' => $data['list_name']]);
        }

        return back()->with('success', 'List renamed successfully.');
    }

    public function removeList(Request $request, string $listId): RedirectResponse
    {
        $src = $request->query('src', 'aud');

        if ($src === 'aud') {
            $audience = Audience::where('audience_id', $listId)->where('user_id', Auth::id())->first();
            if ($audience) {
                AudienceList::where('audience_id', $listId)->delete();
                $audience->delete();
            }
        } else {
            $list = SnLeadList::where('list_hash', $listId)->where('user_id', Auth::id())->first();
            if ($list) {
                $snLids = SnLead::where('sn_list_id', $listId)->pluck('sn_lid')->filter()->values();
                $leadIds = SnLead::where('sn_list_id', $listId)->pluck('id');
                SnLeadsCompany::whereIn('sn_lead_id', $leadIds)->delete();
                SnLead::where('sn_list_id', $listId)->delete();
                if ($snLids->isNotEmpty()) {
                    $v2LeadIds = V2Lead::query()
                        ->where('user_id', Auth::id())
                        ->whereIn('provider_profile_id', $snLids)
                        ->pluck('id');
                    if ($v2LeadIds->isNotEmpty()) {
                        V2LeadSource::query()
                            ->where('source_external_id', $listId)
                            ->whereIn('lead_id', $v2LeadIds)
                            ->delete();
                    }
                }
                $list->delete();
            }
        }

        return redirect()->route('leads')->with('success', 'Lead list removed successfully.');
    }

    public function removeLead(Request $request, int $leadId): RedirectResponse
    {
        $src = $request->query('src', 'aud');

        if ($src === 'aud') {
            AudienceList::where('id', $leadId)->delete();
        } else {
            $lead = SnLead::query()->find($leadId);
            if ($lead && SnLeadList::query()->where('list_hash', $lead->sn_list_id)->where('user_id', Auth::id())->exists()) {
                SnLeadsCompany::where('sn_lead_id', $leadId)->delete();
                if ($lead->sn_lid) {
                    V2LeadSource::query()
                        ->where('source_external_id', $lead->sn_list_id)
                        ->whereHas('lead', fn ($q) => $q->where('user_id', Auth::id())->where('provider_profile_id', $lead->sn_lid))
                        ->delete();
                }
                $lead->delete();
            }
        }

        return back()->with('success', 'Lead removed successfully.');
    }

    public function updateLeadStatus(Request $request, int $leadId): RedirectResponse
    {
        $data = $request->validate([
            'src' => ['required', 'in:sn,aud'],
            'outreach_status' => ['required', 'string', 'in:new,contacted,connected,replied,not_interested'],
        ]);

        if ($data['src'] === 'sn') {
            $lead = SnLead::query()->find($leadId);
            if (! $lead || ! SnLeadList::query()->where('list_hash', $lead->sn_list_id)->where('user_id', Auth::id())->exists()) {
                abort(404);
            }
            $lead->forceFill(['outreach_status' => $data['outreach_status']])->save();
        } else {
            abort(422, 'Status updates for audience leads use email enrichment filters.');
        }

        return back()->with('success', 'Lead status updated.');
    }

    public function removeLeadBulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'src' => ['required', 'in:aud,sn'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        if ($data['src'] === 'aud') {
            AudienceList::whereIn('id', $data['ids'])->delete();
        } else {
            SnLeadsCompany::whereIn('sn_lead_id', $data['ids'])->delete();
            SnLead::whereIn('id', $data['ids'])->delete();
        }

        return back()->with('success', count($data['ids']).' leads removed.');
    }

    public function export(Request $request, string $listId): JsonResponse
    {
        $src = $request->query('src', 'aud');

        if ($src === 'sn') {
            $rows = SnLead::where('sn_list_id', $listId)
                ->leftJoin('sn_leads_companies as c', 'c.sn_lead_id', '=', 'sn_leads.id')
                ->get([
                    'sn_leads.first_name', 'sn_leads.last_name', 'sn_leads.email', 'sn_leads.headline',
                    'sn_leads.geolocation', 'sn_leads.lid', 'c.company_name', 'c.company_website',
                    'c.company_industries', 'c.company_headquaters',
                ])
                ->map(fn ($r) => [
                    'first_name' => $r->first_name,
                    'last_name' => $r->last_name,
                    'email' => $r->email,
                    'headline' => $r->headline,
                    'location' => $r->geolocation,
                    'profile_url' => $r->lid ? 'https://www.linkedin.com/in/'.$r->lid : '',
                    'company_name' => $r->company_name,
                    'company_website' => $r->company_website,
                    'company_industries' => $r->company_industries,
                    'company_headquarters' => $r->company_headquaters,
                ]);
        } else {
            $rows = AudienceList::where('audience_id', $listId)->get()
                ->map(fn (AudienceList $r) => [
                    'first_name' => $r->con_first_name,
                    'last_name' => $r->con_last_name,
                    'email' => $r->con_email,
                    'occupation' => $r->con_job_title,
                    'location' => $r->con_location,
                    'profile_url' => $r->con_public_identifier ? 'https://www.linkedin.com/in/'.$r->con_public_identifier : ($r->con_profile_url ?? ''),
                    'company_url' => $r->con_company_url,
                    'network_distance' => $r->con_distance,
                ]);
        }

        return response()->json(['data' => $rows]);
    }

    public function fetchEmail(Request $request, string $listId): JsonResponse
    {
        if ($request->query('src', 'aud') === 'sn') {
            return $this->fetchSnLeadEmail($request, $listId);
        }

        if ($request->query('src', 'aud') !== 'aud') {
            return response()->json(['status' => 'error', 'message' => 'Email fetching is only available for audience or Sales Navigator leads.'], 400);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $audience = Audience::where('user_id', $user->id)->where('audience_id', $listId)->firstOrFail();

        $request->validate([
            'audience_list_id' => 'required|integer|exists:audience_lists,id',
        ]);

        $item = AudienceList::where('id', $request->audience_list_id)
            ->where('audience_id', $audience->audience_id)
            ->firstOrFail();

        if (! empty($item->con_email)) {
            return response()->json(['status' => 'success', 'message' => 'Email already exists', 'email' => $item->con_email]);
        }

        if (! empty($item->email_fetch_attempted_at)) {
            if ($item->email_fetch_status === 'pending') {
                return response()->json(['status' => 'error', 'message' => 'Email fetch is already in progress.', 'already_pending' => true], 409);
            }
            if ($item->email_fetch_status === 'completed' && empty($item->con_email)) {
                return response()->json(['status' => 'error', 'message' => 'Email fetch already attempted. No email found.', 'already_completed' => true], 409);
            }
        }

        $publicIdentifier = $item->con_public_identifier;
        if (empty($publicIdentifier) && ! empty($item->con_profile_url) && preg_match('/\/in\/([^\/\?]+)/', $item->con_profile_url, $m)) {
            $publicIdentifier = $m[1];
        }
        if (empty($publicIdentifier)) {
            return response()->json(['status' => 'error', 'message' => 'Profile identifier not found. Cannot fetch email.'], 400);
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        if ($user->daily_profile_email_scraping_count >= $dailyLimit) {
            return response()->json(['status' => 'error', 'message' => "Daily email scraping limit reached ({$dailyLimit} profiles/day)."], 429);
        }

        $pendingCount = $this->getPendingEmailFetchCount($user->id);
        if ($pendingCount >= 5) {
            return response()->json([
                'status' => 'error',
                'message' => "You have {$pendingCount} email scraping jobs in progress. Please wait before starting more.",
                'concurrent_limit_reached' => true,
                'pending_count' => $pendingCount,
            ], 429);
        }

        $item->update(['email_fetch_attempted_at' => now(), 'email_fetch_status' => 'pending']);

        try {
            FetchAudienceEmailJob::dispatch($item->id, $publicIdentifier);

            return response()->json(['status' => 'success', 'message' => 'Email fetch job queued.', 'pending' => true]);
        } catch (\Throwable $th) {
            Log::error('Failed to dispatch email fetch job', ['audience_list_id' => $item->id, 'error' => $th->getMessage()]);

            return response()->json(['status' => 'error', 'message' => 'Failed to fetch email: '.$th->getMessage()], 500);
        }
    }

    private function fetchSnLeadEmail(Request $request, string $listId): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        SnLeadList::query()
            ->where('list_hash', $listId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $request->validate([
            'lead_id' => 'required|integer|exists:sn_leads,id',
        ]);

        $lead = SnLead::query()
            ->where('id', $request->integer('lead_id'))
            ->where('sn_list_id', $listId)
            ->firstOrFail();

        if (! empty($lead->email)) {
            return response()->json(['status' => 'success', 'message' => 'Email already exists', 'email' => $lead->email]);
        }

        $identifier = trim((string) ($lead->lid ?: $lead->sn_lid ?: ''));
        if ($identifier === '') {
            return response()->json(['status' => 'error', 'message' => 'Profile identifier not found. Cannot fetch email.'], 400);
        }

        if (! V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            return response()->json(['status' => 'error', 'message' => 'Connect LinkedIn via Integrations first.'], 422);
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        if ($user->daily_profile_email_scraping_count >= $dailyLimit) {
            return response()->json(['status' => 'error', 'message' => "Daily email lookup limit reached ({$dailyLimit} profiles/day)."], 429);
        }

        try {
            $email = app(UnipileProfileEmailService::class)->fetchEmailForUser($user, $identifier);
        } catch (\Throwable $th) {
            Log::error('Failed to fetch SN lead email via Unipile', [
                'sn_lead_id' => $lead->id,
                'identifier' => $identifier,
                'error' => $th->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Failed to fetch email: '.$th->getMessage()], 500);
        }

        $user->increment('daily_profile_email_scraping_count');

        if ($email) {
            $lead->forceFill(['email' => $email])->save();

            V2Lead::query()
                ->where('user_id', $user->id)
                ->where(function ($query) use ($lead, $identifier) {
                    $query->where('public_identifier', $identifier)
                        ->orWhere('provider_profile_id', $identifier)
                        ->orWhere('provider_profile_id', $lead->sn_lid);
                })
                ->update(['email' => $email]);

            return response()->json([
                'status' => 'success',
                'message' => 'Email found via LinkedIn profile.',
                'email' => $email,
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'No email on this LinkedIn profile (member has not shared one).',
            'already_completed' => true,
        ], 404);
    }

    public function fetchEmailBatch(Request $request, string $listId): JsonResponse
    {
        if ($request->query('src', 'aud') !== 'aud') {
            return response()->json(['status' => 'error', 'message' => 'Batch email fetching only supported for audience leads.'], 400);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $audience = Audience::where('user_id', $user->id)->where('audience_id', $listId)->firstOrFail();

        $request->validate([
            'audience_list_ids' => 'required|array|min:1|max:50',
            'audience_list_ids.*' => 'required|integer|exists:audience_lists,id',
        ]);

        $items = AudienceList::whereIn('id', $request->input('audience_list_ids'))
            ->where('audience_id', $audience->audience_id)
            ->get();

        $needing = $items->filter(fn ($i) => empty($i->con_email) && empty($i->email_fetch_attempted_at));

        if ($needing->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'All selected profiles already have emails or have been attempted.'], 400);
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();
        $profileCount = $needing->count();
        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);

        if ($user->daily_profile_email_scraping_count + $profileCount > $dailyLimit) {
            $remaining = max(0, $dailyLimit - $user->daily_profile_email_scraping_count);

            return response()->json([
                'status' => 'error',
                'message' => "Daily limit reached. You can scrape {$remaining} more profiles today.",
                'daily_limit_reached' => true,
                'remaining' => $remaining,
            ], 400);
        }

        try {
            FetchAudienceEmailBatchJob::dispatch($needing->pluck('id')->toArray(), $user->id);

            return response()->json([
                'status' => 'success',
                'message' => "Batch email fetch queued for {$profileCount} profile(s).",
                'profile_count' => $profileCount,
            ]);
        } catch (\Throwable $th) {
            Log::error('Failed to dispatch batch email fetch job', ['error' => $th->getMessage()]);

            return response()->json(['status' => 'error', 'message' => 'Failed to fetch emails: '.$th->getMessage()], 500);
        }
    }

    public function checkEmail(string $listId, int $audienceListId): JsonResponse
    {
        if (request()->query('src', 'aud') !== 'aud') {
            return response()->json(['has_email' => false, 'email' => null], 400);
        }

        $audience = Audience::where('user_id', Auth::id())->where('audience_id', $listId)->firstOrFail();
        $item = AudienceList::where('id', $audienceListId)->where('audience_id', $audience->audience_id)->firstOrFail();

        return response()->json([
            'has_email' => ! empty($item->con_email),
            'email' => $item->con_email ?? null,
            'email_fetch_status' => $item->email_fetch_status,
            'email_fetch_completed' => ! empty($item->email_fetch_attempted_at) && empty($item->con_email),
        ]);
    }

    public function getDailyLimit(): JsonResponse
    {
        return response()->json($this->dailyLimitPayload());
    }

    public function getPendingCount(): JsonResponse
    {
        return response()->json(['status' => 'success', 'pending_count' => $this->getPendingEmailFetchCount(Auth::id())]);
    }

    private function applyEmailFilter($query, string $filter): void
    {
        switch ($filter) {
            case 'with_email':
                $query->whereNotNull('con_email')->where('con_email', '!=', '');
                break;
            case 'without_email':
                $query->where(fn ($q) => $q->whereNull('con_email')->orWhere('con_email', '=', ''))
                    ->where(fn ($q) => $q->where('email_fetch_status', 'completed')->orWhereNotNull('email_fetch_attempted_at'));
                break;
            case 'not_found':
                $query->where('email_fetch_status', 'completed')
                    ->where(fn ($q) => $q->whereNull('con_email')->orWhere('con_email', '=', ''));
                break;
            case 'not_fetched':
                $query->whereNull('email_fetch_status')->whereNull('email_fetch_attempted_at');
                break;
            case 'pending':
                $query->whereIn('email_fetch_status', ['pending', 'processing']);
                break;
        }
    }

    /**
     * @return array<string,int>
     */
    private function emailFilterCounts(string $listId): array
    {
        $base = fn () => AudienceList::where('audience_id', $listId);

        return [
            'all' => $base()->count(),
            'with_email' => $base()->whereNotNull('con_email')->where('con_email', '!=', '')->count(),
            'without_email' => $base()
                ->where(fn ($q) => $q->whereNull('con_email')->orWhere('con_email', '=', ''))
                ->where(fn ($q) => $q->where('email_fetch_status', 'completed')->orWhereNotNull('email_fetch_attempted_at'))
                ->count(),
            'not_fetched' => $base()->whereNull('email_fetch_status')->whereNull('email_fetch_attempted_at')->count(),
            'pending' => $base()->whereIn('email_fetch_status', ['pending', 'processing'])->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function transformAudLead(AudienceList $row): array
    {
        $name = trim(($row->con_first_name ?? '').' '.($row->con_last_name ?? ''));

        return [
            'id' => $row->id,
            'name' => $name !== '' ? $name : 'Unknown',
            'email' => $row->con_email,
            'headline' => $row->con_job_title,
            'location' => $row->con_location,
            'profileid' => $row->con_id,
            'public_identifier' => $row->con_public_identifier,
            'profile_url' => $row->con_public_identifier
                ? 'https://www.linkedin.com/in/'.$row->con_public_identifier
                : $row->con_profile_url,
            'network_distance' => $row->con_distance,
            'email_fetch_status' => $row->email_fetch_status,
            'email_fetch_attempted_at' => optional($row->email_fetch_attempted_at)->toIso8601String(),
            'source' => 'aud',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function transformSnLead(SnLead $row): array
    {
        $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));

        return [
            'id' => $row->id,
            'name' => $name !== '' ? $name : 'Unknown',
            'email' => $row->email,
            'headline' => $row->headline,
            'location' => $row->geolocation,
            'profileid' => $row->lid,
            'public_identifier' => $row->lid,
            'profile_url' => $row->lid ? 'https://www.linkedin.com/in/'.$row->lid : null,
            'network_distance' => $row->degree,
            'outreach_status' => $row->outreach_status ?? 'new',
            'email_fetch_status' => null,
            'email_fetch_attempted_at' => null,
            'source' => 'sn',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function dailyLimitPayload(): array
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);

        if (! $user) {
            return ['daily_limit' => $dailyLimit, 'used' => 0, 'remaining' => $dailyLimit, 'can_scrape' => true, 'reset_date' => null];
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $used = (int) ($user->daily_profile_email_scraping_count ?? 0);
        $remaining = max(0, $dailyLimit - $used);

        return [
            'daily_limit' => $dailyLimit,
            'used' => $used,
            'remaining' => $remaining,
            'can_scrape' => $remaining > 0,
            'reset_date' => $user->daily_profile_email_scraping_reset_at,
        ];
    }

    private function checkAndResetDailyLimit(User $user): void
    {
        $today = now()->toDateString();
        $resetDate = $user->daily_profile_email_scraping_reset_at
            ? \Carbon\Carbon::parse($user->daily_profile_email_scraping_reset_at)->toDateString()
            : null;

        if ($resetDate !== $today) {
            $user->update([
                'daily_profile_email_scraping_count' => 0,
                'daily_profile_email_scraping_reset_at' => $today,
            ]);
        }
    }

    private function getPendingEmailFetchCount(int $userId): int
    {
        $userAudienceIds = Audience::where('user_id', $userId)->pluck('audience_id')->toArray();
        if (empty($userAudienceIds)) {
            return 0;
        }

        $stuckCutoff = now()->subMinutes(10);

        AudienceList::whereIn('audience_id', $userAudienceIds)
            ->whereIn('email_fetch_status', ['pending', 'processing'])
            ->where(fn ($q) => $q->where('email_fetch_attempted_at', '<', $stuckCutoff)->orWhereNull('email_fetch_attempted_at'))
            ->update(['email_fetch_status' => null, 'email_fetch_attempted_at' => null]);

        return AudienceList::whereIn('audience_id', $userAudienceIds)
            ->whereIn('email_fetch_status', ['pending', 'processing'])
            ->where('email_fetch_attempted_at', '>=', $stuckCutoff)
            ->count();
    }
}
