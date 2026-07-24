<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class BonusWebController extends Controller
{
    public function upsellUnlimited(): Response
    {
        return Inertia::render('bonus/UpsellUnlimited');
    }

    public function marketAgencySetup(): Response
    {
        return Inertia::render('bonus/MarketAgencySetup', [
            'resources' => config('bonus.market_agency_resources', []),
        ]);
    }

    public function dfyCampaign(): Response
    {
        return Inertia::render('bonus/DfyCampaign', [
            'links' => config('bonus.dfy_campaign_links', []),
        ]);
    }

    public function coachProgram(): Response
    {
        return Inertia::render('bonus/CoachProgram');
    }

    public function unlimitedTraffic(): Response
    {
        return Inertia::render('bonus/UnlimitedTraffic');
    }
}
