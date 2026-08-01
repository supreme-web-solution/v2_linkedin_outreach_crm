<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\FetchAudienceEmailBatchJob;
use App\Jobs\FetchAudienceEmailJob;
use App\Jobs\FetchSnEmailBatchJob;
use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\SnLeadsCompany;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2Lead;
use App\Models\V2LeadSource;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\V2\Outreach\OutreachImportListService;
use App\V2\Outreach\OutreachContactEnrichmentService;
use App\V2\Outreach\OutreachLeadContactResolver;
use App\V2\Outreach\OutreachLeadReadinessService;
use App\V2\Services\DashboardStatsService;
use App\V2\Services\EmailEnrichmentLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class LeadsWebController extends Controller
{
    public function index(DashboardStatsService $dashboardStats, OutreachImportListService $importListService): Response
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

        $importLists = collect($importListService->listsForUser($userId))
            ->map(fn (array $list) => [
                'id' => $list['id'],
                'list_name' => $list['list_name'],
                'list_hash' => $list['list_hash'],
                'total_leads' => $list['total_leads'],
                'source' => $list['source'],
                'src' => 'csv',
                'created_at' => $list['created_at'],
            ])
            ->sortBy('list_name')
            ->values();

        $stats = [
            'total_lists' => $lists->count() + $importLists->count(),
            'audience_lists' => $audiences->count(),
            'sn_lists' => $snLists->count(),
            'import_lists' => $importLists->count(),
            'total_leads' => $dashboardStats->leadCountForUser($userId),
            'linkedin_leads' => $dashboardStats->linkedinLeadCountForUser($userId),
            'imported_leads' => $dashboardStats->importedLeadCountForUser($userId),
        ];

        return Inertia::render('crm/Leads/Index', [
            'lists' => $lists,
            'importLists' => $importLists,
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
        $leads = null;

        if ($src === 'csv') {
            $importList = V2OutreachImportList::query()
                ->where('list_hash', $listId)
                ->where('user_id', Auth::id())
                ->firstOrFail();
            $listName = $importList->name ?: 'Imported list';
            $listRecordId = $importList->id;

            $query = V2OutreachImportLead::query()->where('import_list_id', $importList->id);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('linkedin_id', 'like', "%{$search}%")
                        ->orWhere('instagram_handle', 'like', "%{$search}%")
                        ->orWhere('telegram_handle', 'like', "%{$search}%")
                        ->orWhere('twitter_handle', 'like', "%{$search}%");
                });
            }

            $leads = $query->latest('id')->paginate(20)->appends($request->query())
                ->through(fn (V2OutreachImportLead $row) => [
                    'id' => $row->id,
                    'full_name' => $row->full_name,
                    'profile_url' => $row->profile_url,
                    'contacts' => $this->contactsFromImportLead($row),
                ]);
        } elseif ($src === 'aud') {
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

            $overlays = app(OutreachLeadContactResolver::class)->overlaysForLists((int) Auth::id(), [
                ['list_hash' => $listId, 'list_src' => 'aud'],
            ]);

            $leads = $query->latest()->paginate(20)->appends($request->query())
                ->through(fn (AudienceList $row) => $this->transformAudLead($row, $overlays));

            $counts = $this->emailFilterCounts($listId);
        } else {
            $list = SnLeadList::where('list_hash', $listId)->where('user_id', Auth::id())->first();
            $listName = $list?->name ?: 'Sales Navigator';
            $listRecordId = $list?->id;

            $query = SnLead::where('sn_list_id', $listId)->with('company');
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('headline', 'like', "%{$search}%")
                        ->orWhere('geolocation', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $overlays = app(OutreachLeadContactResolver::class)->overlaysForLists((int) Auth::id(), [
                ['list_hash' => $listId, 'list_src' => 'sn'],
            ]);

            $leads = $query->latest()->paginate(20)->appends($request->query())
                ->through(fn (SnLead $row) => $this->transformSnLead($row, $overlays));
        }

        $pendingCount = in_array($src, ['aud', 'sn'], true)
            ? app(EmailEnrichmentLimiter::class)->pendingJobCount(Auth::id())
            : 0;

        $contactStats = in_array($src, ['aud', 'sn'], true)
            ? $this->contactStatsForList($listId, $src, (int) Auth::id(), $pendingCount)
            : null;

        $importEnrichmentStats = $src === 'csv'
            ? app(OutreachLeadReadinessService::class)->enrichmentStatsForImportList($listId, (int) Auth::id())
            : null;

        return Inertia::render($src === 'csv' ? 'crm/Leads/ImportShow' : 'crm/Leads/Show', [
            'leads' => $leads,
            'listId' => (string) $listId,
            'listRecordId' => $listRecordId,
            'listName' => $listName,
            'src' => $src,
            'emailFilter' => $emailFilter,
            'search' => $search,
            'counts' => $counts,
            'contactStats' => $contactStats,
            'importEnrichmentStats' => $importEnrichmentStats,
            'dailyLimit' => $src === 'csv' ? null : $this->dailyLimitPayload(),
            'pendingCount' => $pendingCount,
            'enrichBatchSize' => max(1, (int) config('services.email_scraping.batch_size', 25)),
        ]);
    }

    public function updateList(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'list_name' => ['required', 'string', 'max:255'],
            'src' => ['required', 'in:aud,sn,csv'],
        ]);

        if ($data['src'] === 'aud') {
            Audience::where('id', $id)->where('user_id', Auth::id())->update(['audience_name' => $data['list_name']]);
        } elseif ($data['src'] === 'sn') {
            SnLeadList::where('id', $id)->where('user_id', Auth::id())->update(['name' => $data['list_name']]);
        } else {
            V2OutreachImportList::where('id', $id)->where('user_id', Auth::id())->update(['name' => $data['list_name']]);
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
        } elseif ($src === 'csv') {
            $importList = V2OutreachImportList::where('list_hash', $listId)->where('user_id', Auth::id())->first();
            if ($importList) {
                V2OutreachImportLead::where('import_list_id', $importList->id)->delete();
                $importList->delete();
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

    public function removeListsBulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lists' => ['required', 'array', 'min:1'],
            'lists.*.list_hash' => ['required', 'string'],
            'lists.*.src' => ['required', 'in:aud,sn,csv'],
        ]);

        $removed = 0;

        foreach ($data['lists'] as $list) {
            $listId = (string) $list['list_hash'];
            $src = (string) $list['src'];

            if ($src === 'aud') {
                $audience = Audience::where('audience_id', $listId)->where('user_id', Auth::id())->first();
                if ($audience) {
                    AudienceList::where('audience_id', $listId)->delete();
                    $audience->delete();
                    $removed++;
                }
            } elseif ($src === 'csv') {
                $importList = V2OutreachImportList::where('list_hash', $listId)->where('user_id', Auth::id())->first();
                if ($importList) {
                    V2OutreachImportLead::where('import_list_id', $importList->id)->delete();
                    $importList->delete();
                    $removed++;
                }
            } else {
                $listModel = SnLeadList::where('list_hash', $listId)->where('user_id', Auth::id())->first();
                if ($listModel) {
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
                    $listModel->delete();
                    $removed++;
                }
            }
        }

        return back()->with('success', "{$removed} list(s) removed.");
    }

    public function removeLead(Request $request, int $leadId): RedirectResponse
    {
        $src = $request->query('src', 'aud');

        if ($src === 'aud') {
            AudienceList::where('id', $leadId)->delete();
        } elseif ($src === 'csv') {
            V2OutreachImportLead::query()
                ->where('id', $leadId)
                ->whereHas('importList', fn ($q) => $q->where('user_id', Auth::id()))
                ->delete();
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
            'src' => ['required', 'in:aud,sn,csv'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        if ($data['src'] === 'aud') {
            AudienceList::whereIn('id', $data['ids'])->delete();
        } elseif ($data['src'] === 'csv') {
            V2OutreachImportLead::query()
                ->whereIn('id', $data['ids'])
                ->whereHas('importList', fn ($q) => $q->where('user_id', Auth::id()))
                ->delete();
        } else {
            SnLeadsCompany::whereIn('sn_lead_id', $data['ids'])->delete();
            SnLead::whereIn('id', $data['ids'])->delete();
        }

        return back()->with('success', count($data['ids']).' leads removed.');
    }

    public function export(Request $request, string $listId): JsonResponse
    {
        $src = $request->query('src', 'aud');

        if ($src === 'csv') {
            $importList = V2OutreachImportList::query()
                ->where('list_hash', $listId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $rows = V2OutreachImportLead::query()
                ->where('import_list_id', $importList->id)
                ->get()
                ->map(fn (V2OutreachImportLead $r) => [
                    'full_name' => $r->full_name,
                    'email' => $r->email,
                    'phone' => $r->phone,
                    'linkedin_url' => $r->profile_url,
                    'instagram' => $r->instagram_handle,
                    'telegram' => $r->telegram_handle,
                    'twitter' => $r->twitter_handle,
                ]);
        } elseif ($src === 'sn') {
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
        return $this->enrich($request, $listId);
    }

    public function enrich(Request $request, string $listId): JsonResponse
    {
        $src = $request->query('src', 'aud');

        if ($src === 'sn') {
            return $this->enrichSnLead($request, $listId);
        }

        if ($src === 'csv') {
            return $this->enrichImportLead($request, $listId);
        }

        if ($src !== 'aud') {
            return response()->json(['status' => 'error', 'message' => 'Enrichment is only available for audience, Sales Navigator, or imported leads.'], 400);
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
                return response()->json(['status' => 'error', 'message' => 'Enrichment is already in progress.', 'already_pending' => true], 409);
            }
            if ($item->email_fetch_status === 'completed' && empty($item->con_email)) {
                return response()->json(['status' => 'error', 'message' => 'Enrichment already attempted. No work email found.', 'already_completed' => true], 409);
            }
        }

        $publicIdentifier = $item->con_public_identifier;
        if (empty($publicIdentifier) && ! empty($item->con_profile_url) && preg_match('/\/in\/([^\/\?]+)/', $item->con_profile_url, $m)) {
            $publicIdentifier = $m[1];
        }
        if (empty($publicIdentifier)) {
            return response()->json(['status' => 'error', 'message' => 'Profile identifier not found. Cannot enrich.'], 400);
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        if ($user->daily_profile_email_scraping_count >= $dailyLimit) {
            return response()->json(['status' => 'error', 'message' => "Daily enrichment limit reached ({$dailyLimit} profiles/day)."], 429);
        }

        $pendingCount = app(EmailEnrichmentLimiter::class)->pendingJobCount($user->id);
        $batchSize = app(EmailEnrichmentLimiter::class)->batchSize();
        if ($pendingCount >= $batchSize) {
            return response()->json([
                'status' => 'error',
                'message' => "You have {$pendingCount} enrichment jobs in progress. Please wait before starting more.",
                'concurrent_limit_reached' => true,
                'pending_count' => $pendingCount,
            ], 429);
        }

        $item->update(['email_fetch_attempted_at' => now(), 'email_fetch_status' => 'pending']);

        try {
            FetchAudienceEmailJob::dispatch($item->id, $publicIdentifier);

            return response()->json(['status' => 'success', 'message' => 'Enrichment job queued.', 'pending' => true]);
        } catch (\Throwable $th) {
            Log::error('Failed to dispatch enrichment job', ['audience_list_id' => $item->id, 'error' => $th->getMessage()]);

            return response()->json(['status' => 'error', 'message' => 'Failed to enrich: '.$th->getMessage()], 500);
        }
    }

    private function enrichSnLead(Request $request, string $listId): JsonResponse
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

        if (! empty($lead->email_fetch_attempted_at)) {
            if (in_array($lead->email_fetch_status, ['pending', 'processing'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Enrichment is already in progress.',
                    'already_pending' => true,
                ], 409);
            }

            if ($lead->email_fetch_status === 'completed' && empty($lead->email)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Enrichment already attempted. No work email found.',
                    'already_completed' => true,
                ], 409);
            }
        }

        $identifier = trim((string) ($lead->lid ?: $lead->sn_lid ?: ''));
        if ($identifier === '') {
            return response()->json(['status' => 'error', 'message' => 'Profile identifier not found. Cannot enrich.'], 400);
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        if ($user->daily_profile_email_scraping_count >= $dailyLimit) {
            return response()->json(['status' => 'error', 'message' => "Daily enrichment limit reached ({$dailyLimit} profiles/day)."], 429);
        }

        $pendingCount = app(EmailEnrichmentLimiter::class)->pendingJobCount($user->id);
        $batchSize = app(EmailEnrichmentLimiter::class)->batchSize();
        if ($pendingCount >= $batchSize) {
            return response()->json([
                'status' => 'error',
                'message' => "You have {$pendingCount} enrichment jobs in progress. Please wait before starting more.",
                'concurrent_limit_reached' => true,
                'pending_count' => $pendingCount,
            ], 429);
        }

        $lead->update(['email_fetch_attempted_at' => now(), 'email_fetch_status' => 'processing']);

        try {
            $enrichmentService = app(\App\V2\Services\LeadEnrichmentService::class);
            $persister = app(\App\V2\Services\LeadEnrichmentPersister::class);
            $lead->loadMissing('company');
            $result = $enrichmentService->enrich($user, $enrichmentService->inputFromSnLead($lead));
            $persister->persistSnLead($lead, $result, $user->id);
        } catch (\Throwable $th) {
            $lead->update(['email_fetch_status' => 'failed']);

            Log::error('Failed to enrich SN lead', [
                'sn_lead_id' => $lead->id,
                'identifier' => $identifier,
                'error' => $th->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Failed to enrich: '.$th->getMessage()], 500);
        }

        $user->increment('daily_profile_email_scraping_count');
        $lead->refresh();

        if ($result->hasAnyContact()) {
            $lead->refresh();
            $verify = app(\App\V2\Outreach\OutreachContactEnrichmentService::class)->verifyContactsForRow($user, [
                'src' => 'sn',
                'list_hash' => $listId,
                'record_id' => $lead->id,
                'phone' => $lead->phone,
                'whatsapp_provider_id' => $lead->whatsapp_provider_id,
                'instagram_handle' => $lead->instagram_handle,
                'instagram_provider_id' => $lead->instagram_provider_id,
                'telegram_handle' => $lead->telegram_handle,
                'telegram_provider_id' => $lead->telegram_provider_id,
                'twitter_handle' => $lead->twitter_handle,
                'twitter_provider_id' => $lead->twitter_provider_id,
                'linkedin_key' => trim((string) ($lead->lid ?: $lead->sn_lid ?: '')),
            ]);
            $lead->refresh();

            $extras = [];
            if ($verify['whatsapp_verified']) {
                $extras[] = 'WhatsApp verified';
            }
            if ($verify['handles_resolved'] > 0) {
                $extras[] = $verify['handles_resolved'].' social handle(s) resolved';
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Contact details enriched.'.($result->email ? ' Email found.' : '').($result->phone ? ' Phone found.' : '')
                    .($extras !== [] ? ' '.implode('. ', $extras).'.' : ''),
                'email' => $lead->email,
                'sources' => $result->sources,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Enrichment complete. No work email or phone was found for this profile.',
            'email' => null,
            'completed' => true,
        ]);
    }

    public function fetchEmailBatch(Request $request, string $listId): JsonResponse
    {
        return $this->enrichBatch($request, $listId);
    }

    public function enrichBatch(Request $request, string $listId): JsonResponse
    {
        $src = $request->query('src', 'aud');

        if ($src === 'sn') {
            return $this->enrichSnBatch($request, $listId);
        }

        if ($src === 'csv') {
            return $this->enrichImportBatch($request, $listId);
        }

        if ($src !== 'aud') {
            return response()->json(['status' => 'error', 'message' => 'Batch enrichment only supported for audience, Sales Navigator, or imported leads.'], 400);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $audience = Audience::where('user_id', $user->id)->where('audience_id', $listId)->firstOrFail();

        $readiness = app(OutreachLeadReadinessService::class);

        $request->validate([
            'auto_batch' => 'sometimes|boolean',
            'audience_list_ids' => 'required_unless:auto_batch,true|array|min:1|max:50',
            'audience_list_ids.*' => 'required|integer|exists:audience_lists,id',
        ]);

        $batchSize = max(1, (int) config('services.email_scraping.batch_size', 25));

        $audienceListIds = $request->boolean('auto_batch')
            ? $readiness->nextAudienceListIdsForEmailFetch($audience->audience_id, $batchSize)
            : $request->input('audience_list_ids', []);

        if ($audienceListIds === []) {
            return response()->json(['status' => 'error', 'message' => 'No contacts left to enrich in this list.'], 400);
        }

        $items = AudienceList::whereIn('id', $audienceListIds)
            ->where('audience_id', $audience->audience_id)
            ->get();

        $needing = $items->filter(fn ($i) => empty($i->con_email) && empty($i->email_fetch_attempted_at));

        if ($needing->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'All selected profiles are already enriched or in progress.'], 400);
        }

        $profileCount = $needing->count();
        $capacity = app(EmailEnrichmentLimiter::class)->queueCapacity($user, $profileCount);

        if (! $capacity['allowed']) {
            return response()->json([
                'status' => 'error',
                'message' => $capacity['message'],
                'daily_limit_reached' => $capacity['remaining_daily'] <= 0,
                'remaining' => $capacity['remaining_daily'],
                'pending_count' => $capacity['pending_jobs'],
            ], $capacity['pending_jobs'] >= app(EmailEnrichmentLimiter::class)->batchSize() ? 429 : 400);
        }

        $idsToQueue = $needing->pluck('id')->take($capacity['max_queue_now'])->values()->all();

        AudienceList::query()
            ->whereIn('id', $idsToQueue)
            ->update(['email_fetch_attempted_at' => now(), 'email_fetch_status' => 'pending']);

        try {
            FetchAudienceEmailBatchJob::dispatch($idsToQueue, $user->id);

            $queued = count($idsToQueue);
            $message = $queued < $profileCount
                ? "Queued {$queued} of {$profileCount} profile(s) for enrichment today (daily limit). Remaining profiles can be queued tomorrow."
                : "Queued enrichment for {$queued} profile(s).";

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'profile_count' => $queued,
                'skipped' => $profileCount - $queued,
            ]);
        } catch (\Throwable $th) {
            Log::error('Failed to dispatch batch enrichment job', ['error' => $th->getMessage()]);

            return response()->json(['status' => 'error', 'message' => 'Failed to enrich: '.$th->getMessage()], 500);
        }
    }

    private function enrichSnBatch(Request $request, string $listId): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        SnLeadList::query()
            ->where('list_hash', $listId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $readiness = app(OutreachLeadReadinessService::class);

        $request->validate([
            'auto_batch' => 'sometimes|boolean',
            'lead_ids' => 'required_unless:auto_batch,true|array|min:1|max:50',
            'lead_ids.*' => 'required|integer|exists:sn_leads,id',
        ]);

        $batchSize = max(1, (int) config('services.email_scraping.batch_size', 25));

        $leadIds = $request->boolean('auto_batch')
            ? $readiness->nextSnLeadIdsForEmailFetch($listId, $batchSize)
            : $request->input('lead_ids', []);

        if ($leadIds === []) {
            return response()->json(['status' => 'error', 'message' => 'No contacts left to enrich in this list.'], 400);
        }

        $items = SnLead::query()
            ->whereIn('id', $leadIds)
            ->where('sn_list_id', $listId)
            ->get();

        $needing = $items->filter(function (SnLead $lead) {
            if (! empty($lead->email)) {
                return false;
            }

            if (in_array($lead->email_fetch_status, ['pending', 'processing'], true)) {
                return false;
            }

            if ($lead->email_fetch_status === 'completed') {
                return false;
            }

            if (empty($lead->email_fetch_status) && ! empty($lead->phone_fetch_attempted_at)) {
                return false;
            }

            return true;
        });

        if ($needing->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'All selected profiles are already enriched or in progress.'], 400);
        }

        $profileCount = $needing->count();
        $capacity = app(EmailEnrichmentLimiter::class)->queueCapacity($user, $profileCount);

        if (! $capacity['allowed']) {
            return response()->json([
                'status' => 'error',
                'message' => $capacity['message'],
                'daily_limit_reached' => $capacity['remaining_daily'] <= 0,
                'remaining' => $capacity['remaining_daily'],
                'pending_count' => $capacity['pending_jobs'],
            ], $capacity['pending_jobs'] >= app(EmailEnrichmentLimiter::class)->batchSize() ? 429 : 400);
        }

        $idsToQueue = $needing->pluck('id')->take($capacity['max_queue_now'])->values()->all();

        SnLead::query()
            ->whereIn('id', $idsToQueue)
            ->update(['email_fetch_attempted_at' => now(), 'email_fetch_status' => 'pending']);

        try {
            FetchSnEmailBatchJob::dispatch($idsToQueue, $user->id, $listId);

            $queued = count($idsToQueue);
            $message = $queued < $profileCount
                ? "Queued {$queued} of {$profileCount} profile(s) for enrichment today (daily limit). Remaining profiles can be queued tomorrow."
                : "Queued enrichment for {$queued} profile(s).";

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'profile_count' => $queued,
                'skipped' => $profileCount - $queued,
            ]);
        } catch (\Throwable $th) {
            Log::error('Failed to dispatch SN batch enrichment job', ['error' => $th->getMessage()]);

            return response()->json(['status' => 'error', 'message' => 'Failed to enrich: '.$th->getMessage()], 500);
        }
    }

    private function enrichImportLead(Request $request, string $listId): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $importList = V2OutreachImportList::query()
            ->where('list_hash', $listId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $request->validate([
            'import_lead_id' => ['required', 'integer'],
        ]);

        $leadId = (int) $request->integer('import_lead_id');

        V2OutreachImportLead::query()
            ->where('id', $leadId)
            ->where('import_list_id', $importList->id)
            ->firstOrFail();

        $readiness = app(OutreachLeadReadinessService::class);
        $rows = $readiness->collectLeadRows([['list_hash' => $listId, 'list_src' => 'csv']], $user->id);
        $row = collect($rows)->firstWhere('record_id', $leadId);

        if (! is_array($row)) {
            return response()->json(['status' => 'error', 'message' => 'Contact not found in this list.'], 404);
        }

        $result = app(OutreachContactEnrichmentService::class)->verifyContactsForRow($user, $row);

        return response()->json([
            'status' => 'success',
            'message' => $this->importEnrichmentMessage($result, 1),
            'result' => $result,
        ]);
    }

    private function enrichImportBatch(Request $request, string $listId): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $importList = V2OutreachImportList::query()
            ->where('list_hash', $listId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $readiness = app(OutreachLeadReadinessService::class);

        $batchSize = max(1, (int) config('services.email_scraping.batch_size', 25));

        $request->validate([
            'auto_batch' => ['sometimes', 'boolean'],
            'import_lead_ids' => ['required_unless:auto_batch,true', 'array', 'min:1', 'max:'.$batchSize],
            'import_lead_ids.*' => ['required', 'integer'],
        ]);

        $leadIds = $request->boolean('auto_batch')
            ? $readiness->nextImportLeadIdsForEnrichment($importList->id, $batchSize)
            : array_map('intval', $request->input('import_lead_ids', []));

        if ($leadIds === []) {
            return response()->json(['status' => 'error', 'message' => 'No contacts left to enrich in this list.'], 400);
        }

        $rows = $readiness->collectLeadRows([['list_hash' => $listId, 'list_src' => 'csv']], $user->id);
        $byId = collect($rows)->keyBy('record_id');

        $service = app(OutreachContactEnrichmentService::class);
        $totals = [
            'whatsapp_verified' => 0,
            'handles_resolved' => 0,
            'handles_failed' => 0,
            'handles_skipped' => 0,
            'processed' => 0,
        ];

        foreach ($leadIds as $index => $leadId) {
            $row = $byId->get($leadId);
            if (! is_array($row)) {
                continue;
            }

            if ($index > 0) {
                usleep(300_000);
            }

            $result = $service->verifyContactsForRow($user, $row);
            $totals['whatsapp_verified'] += $result['whatsapp_verified'] ? 1 : 0;
            $totals['handles_resolved'] += (int) ($result['handles_resolved'] ?? 0);
            $totals['handles_failed'] += (int) ($result['handles_failed'] ?? 0);
            $totals['handles_skipped'] += (int) ($result['handles_skipped'] ?? 0);
            $totals['processed']++;
        }

        if ($totals['processed'] === 0) {
            return response()->json(['status' => 'error', 'message' => 'No eligible contacts in selection.'], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => $this->importEnrichmentMessage($totals, $totals['processed']),
            'result' => $totals,
        ]);
    }

    /**
     * @param  array<string, int|bool>  $result
     */
    private function importEnrichmentMessage(array $result, int $count): string
    {
        $parts = [];

        $wa = (int) ($result['whatsapp_verified'] ?? 0);
        if ($wa > 0) {
            $parts[] = "{$wa} WhatsApp".($wa === 1 ? '' : ' numbers').' verified';
        }

        $resolved = (int) ($result['handles_resolved'] ?? 0);
        if ($resolved > 0) {
            $parts[] = "{$resolved} social handle".($resolved === 1 ? '' : 's').' resolved';
        }

        $failed = (int) ($result['handles_failed'] ?? 0);
        if ($failed > 0) {
            $parts[] = "{$failed} could not be resolved";
        }

        if ($parts === []) {
            return $count === 1
                ? 'Nothing to enrich for this contact — add a phone or social handle first, or connect channels under Integrations.'
                : 'No contacts were enriched — add phones or social handles, or connect channels under Integrations.';
        }

        return ucfirst(implode('. ', $parts)).'.';
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
        $payload = app(\App\V2\Services\DailyEnrichmentQuotaService::class)->payloadForUser(Auth::user());

        return response()->json($payload ?? [
            'daily_limit' => (int) config('services.email_scraping.daily_limit_per_user', 100),
            'used' => 0,
            'remaining' => (int) config('services.email_scraping.daily_limit_per_user', 100),
            'effective_remaining' => (int) config('services.email_scraping.daily_limit_per_user', 100),
            'in_flight' => 0,
            'can_scrape' => true,
            'percent' => 0,
            'reset_date' => null,
        ]);
    }

    public function getPendingCount(): JsonResponse
    {
        return response()->json(['status' => 'success', 'pending_count' => app(EmailEnrichmentLimiter::class)->pendingJobCount(Auth::id())]);
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
     * @param  array<string, array<string, mixed>>  $overlays
     * @return array<string,mixed>
     */
    private function transformAudLead(AudienceList $row, array $overlays = []): array
    {
        $name = trim(($row->con_first_name ?? '').' '.($row->con_last_name ?? ''));
        $resolver = app(OutreachLeadContactResolver::class);
        $profileId = trim((string) ($row->con_public_identifier ?: $row->con_id ?: ''));
        $linkedinKey = $resolver->normalizeLinkedinKey($profileId);
        $contacts = $this->mergedContacts($resolver->mergeRow([
            'email' => trim((string) ($row->con_email ?? '')),
            'phone' => trim((string) ($row->con_phone ?? '')),
            'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
            'whatsapp_verify_status' => '',
            'instagram_handle' => '',
            'instagram_provider_id' => '',
            'telegram_handle' => '',
            'telegram_provider_id' => '',
            'twitter_handle' => '',
            'twitter_provider_id' => '',
            'email_fetch_status' => (string) ($row->email_fetch_status ?? ''),
            'phone_fetch_status' => (string) ($row->phone_fetch_status ?? ''),
            'phone_fetch_attempted' => ! empty($row->phone_fetch_attempted_at),
        ], $overlays[strtolower($linkedinKey)] ?? null));
        $company = $this->companyPayload($row->con_company_name, $row->con_company_url);

        return [
            'id' => $row->id,
            'name' => $name !== '' ? $name : 'Unknown',
            'email' => $contacts['email'],
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
            'contacts' => $contacts,
            'company_name' => $company['company_name'],
            'company_domain' => $company['company_domain'],
            'company_logo_url' => $company['company_logo_url'],
            'source' => 'aud',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $overlays
     * @return array<string,mixed>
     */
    private function transformSnLead(SnLead $row, array $overlays = []): array
    {
        $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));
        $resolver = app(OutreachLeadContactResolver::class);
        $profileId = trim((string) ($row->lid ?? ''));
        $linkedinKey = $resolver->normalizeLinkedinKey($profileId ?: $row->sn_lid);
        $emailFetchStatus = (string) ($row->email_fetch_status ?? '');
        $emailFetchAttemptedAt = $row->email_fetch_attempted_at;
        if ($emailFetchStatus === '' && ! empty($row->phone_fetch_attempted_at) && trim((string) ($row->email ?? '')) === '') {
            $emailFetchStatus = 'completed';
            $emailFetchAttemptedAt = $emailFetchAttemptedAt ?? $row->phone_fetch_attempted_at;
        }
        $contacts = $this->mergedContacts($resolver->mergeRow([
            'email' => trim((string) ($row->email ?? '')),
            'phone' => trim((string) ($row->phone ?? '')),
            'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
            'whatsapp_verify_status' => '',
            'instagram_handle' => trim((string) ($row->instagram_handle ?? '')),
            'instagram_provider_id' => trim((string) ($row->instagram_provider_id ?? '')),
            'telegram_handle' => trim((string) ($row->telegram_handle ?? '')),
            'telegram_provider_id' => trim((string) ($row->telegram_provider_id ?? '')),
            'twitter_handle' => trim((string) ($row->twitter_handle ?? '')),
            'twitter_provider_id' => trim((string) ($row->twitter_provider_id ?? '')),
            'email_fetch_status' => $emailFetchStatus,
            'phone_fetch_status' => (string) ($row->phone_fetch_status ?? ''),
            'phone_fetch_attempted' => ! empty($row->phone_fetch_attempted_at),
        ], $overlays[strtolower($linkedinKey)] ?? null));
        $snCompany = $row->relationLoaded('company') ? $row->company : null;
        $company = $this->companyPayload(
            $snCompany?->company_name,
            $snCompany?->company_website,
            $snCompany?->company_logo,
        );

        return [
            'id' => $row->id,
            'name' => $name !== '' ? $name : 'Unknown',
            'email' => $contacts['email'],
            'headline' => $row->headline,
            'location' => $row->geolocation,
            'profileid' => $row->lid,
            'public_identifier' => $row->lid,
            'profile_url' => $row->lid ? 'https://www.linkedin.com/in/'.$row->lid : null,
            'network_distance' => $row->degree,
            'outreach_status' => $row->outreach_status ?? 'new',
            'email_fetch_status' => $emailFetchStatus !== '' ? $emailFetchStatus : null,
            'email_fetch_attempted_at' => optional($emailFetchAttemptedAt)->toIso8601String(),
            'contacts' => $contacts,
            'company_name' => $company['company_name'],
            'company_domain' => $company['company_domain'],
            'company_logo_url' => $company['company_logo_url'],
            'source' => 'sn',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function contactsFromImportLead(V2OutreachImportLead $row): array
    {
        return $this->mergedContacts([
            'email' => trim((string) ($row->email ?? '')),
            'phone' => trim((string) ($row->phone ?? '')),
            'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
            'whatsapp_verify_status' => trim((string) ($row->whatsapp_verify_status ?? '')),
            'instagram_handle' => trim((string) ($row->instagram_handle ?? '')),
            'instagram_provider_id' => trim((string) ($row->instagram_provider_id ?? '')),
            'telegram_handle' => trim((string) ($row->telegram_handle ?? '')),
            'telegram_provider_id' => trim((string) ($row->telegram_provider_id ?? '')),
            'twitter_handle' => trim((string) ($row->twitter_handle ?? '')),
            'twitter_provider_id' => trim((string) ($row->twitter_provider_id ?? '')),
            'email_fetch_status' => '',
            'phone_fetch_status' => '',
        ]);
    }

    /**
     * @param  array<string, string>  $merged
     * @return array<string, string|null>
     */
    private function mergedContacts(array $merged): array
    {
        return [
            'email' => ($merged['email'] ?? '') !== '' ? $merged['email'] : null,
            'phone' => ($merged['phone'] ?? '') !== '' ? $merged['phone'] : null,
            'whatsapp_provider_id' => ($merged['whatsapp_provider_id'] ?? '') !== '' ? $merged['whatsapp_provider_id'] : null,
            'whatsapp_verify_status' => ($merged['whatsapp_verify_status'] ?? '') !== '' ? $merged['whatsapp_verify_status'] : null,
            'instagram_handle' => ($merged['instagram_handle'] ?? '') !== '' ? $merged['instagram_handle'] : null,
            'instagram_provider_id' => ($merged['instagram_provider_id'] ?? '') !== '' ? $merged['instagram_provider_id'] : null,
            'telegram_handle' => ($merged['telegram_handle'] ?? '') !== '' ? $merged['telegram_handle'] : null,
            'telegram_provider_id' => ($merged['telegram_provider_id'] ?? '') !== '' ? $merged['telegram_provider_id'] : null,
            'twitter_handle' => ($merged['twitter_handle'] ?? '') !== '' ? $merged['twitter_handle'] : null,
            'twitter_provider_id' => ($merged['twitter_provider_id'] ?? '') !== '' ? $merged['twitter_provider_id'] : null,
            'email_fetch_status' => ($merged['email_fetch_status'] ?? '') !== '' ? $merged['email_fetch_status'] : null,
            'phone_fetch_attempted' => (bool) ($merged['phone_fetch_attempted'] ?? false),
            'phone_fetch_status' => ($merged['phone_fetch_attempted'] ?? false) && ($merged['phone_fetch_status'] ?? '') !== ''
                ? $merged['phone_fetch_status']
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contactStatsForList(string $listId, string $src, int $userId, int $queuePending): array
    {
        $rows = app(OutreachLeadReadinessService::class)->collectLeadRows(
            [['list_hash' => $listId, 'list_src' => $src]],
            $userId,
        );

        $total = count($rows);
        $emailsFound = 0;
        $phonesFound = 0;
        $emailPending = 0;
        $phonePending = 0;
        $emailSearched = 0;
        $phoneSearched = 0;

        foreach ($rows as $row) {
            if (($row['email'] ?? '') !== '') {
                $emailsFound++;
            }
            if (($row['phone'] ?? '') !== '') {
                $phonesFound++;
            }
            if (in_array($row['email_fetch_status'] ?? '', ['pending', 'processing'], true)) {
                $emailPending++;
            }
            if (in_array($row['phone_fetch_status'] ?? '', ['pending', 'processing'], true)) {
                $phonePending++;
            }
            if ($row['email_fetch_attempted'] ?? false) {
                $emailSearched++;
            }
            if ($row['phone_fetch_attempted'] ?? false) {
                $phoneSearched++;
            }
        }

        $emailPending = max($emailPending, $queuePending);
        $processed = min($total, $emailSearched + $emailPending);
        $fetchable = app(OutreachLeadReadinessService::class)->countEmailFetchableRows($rows, $src);

        return [
            'total' => $total,
            'running' => $emailPending > 0 || $phonePending > 0,
            'processed' => $processed,
            'fetchable' => $fetchable,
            'emails' => [
                'found' => $emailsFound,
                'total' => $total,
                'pending' => $emailPending,
                'searched' => $emailSearched,
                'fill_percent' => $total > 0 ? (int) round($emailsFound / $total * 100) : 0,
                'hit_rate' => $emailSearched > 0 ? (int) round($emailsFound / $emailSearched * 100) : 0,
            ],
            'phones' => [
                'found' => $phonesFound,
                'total' => $total,
                'pending' => $phonePending,
                'searched' => $phoneSearched,
                'fill_percent' => $total > 0 ? (int) round($phonesFound / $total * 100) : 0,
                'hit_rate' => $phoneSearched > 0 ? (int) round($phonesFound / $phoneSearched * 100) : 0,
            ],
        ];
    }

    /**
     * @return array{company_name: ?string, company_domain: ?string, company_logo_url: ?string}
     */
    private function companyPayload(?string $name, ?string $website, ?string $logo = null): array
    {
        $domain = $this->domainFromUrl($website ?? '');
        if ($domain === '' && $name !== null && str_contains($name, '.')) {
            $domain = $this->domainFromUrl($name);
        }
        $logoUrl = $logo ?: ($domain !== '' ? 'https://www.google.com/s2/favicons?domain='.$domain.'&sz=64' : null);

        return [
            'company_name' => $name ?: null,
            'company_domain' => $domain ?: null,
            'company_logo_url' => $logoUrl,
        ];
    }

    private function domainFromUrl(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://') && str_contains($value, '.')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }

        return strtolower(preg_replace('/^www\./', '', $host) ?? '');
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
}

