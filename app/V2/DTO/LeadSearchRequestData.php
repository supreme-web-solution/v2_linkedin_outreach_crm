<?php

namespace App\V2\DTO;

use Illuminate\Http\Request;

class LeadSearchRequestData
{
    /**
     * Validate and extract all supported search parameters from the request.
     *
     * Supported filter-based parameters (Unipile classic LinkedIn search):
     *   keywords         – free-text keywords
     *   title            – job title filter
     *   current_company  – current company name / ID
     *   past_company     – past company name / ID
     *   school           – school / university name
     *   location         – location text or ID (passed as-is to Unipile)
     *   network_depths   – array of connection degrees: "F" (1st), "S" (2nd), "O" (3rd+)
     *   open_link        – boolean, only return open / "Open to connect" profiles
     *   limit            – max results to return (1–100)
     *   persist_results  – whether to upsert results into v2_leads (default true)
     *   audience_name    – label for the CRM audience / list (user-facing, not provider names)
     *
     * URL-based search (Unipile passes the full LinkedIn search URL to LinkedIn):
     *   linkedin_url     – any linkedin.com/search/results/people?... URL
     *
     * Profile lookup (import a single person by their LinkedIn profile URL):
     *   profile_url      – a linkedin.com/in/username URL
     *
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        return $request->validate([
            // ── Filter-based search ────────────────────────────────────────
            'keywords'        => ['nullable', 'string', 'max:255'],
            'title'           => ['nullable', 'string', 'max:255'],
            'current_company' => ['nullable', 'string', 'max:255'],
            'past_company'    => ['nullable', 'string', 'max:255'],
            'school'          => ['nullable', 'string', 'max:255'],
            'location'        => ['nullable', 'string', 'max:255'],
            'network_depths'  => ['nullable', 'array'],
            'network_depths.*'=> ['string', 'in:F,S,O'],
            'open_link'       => ['nullable', 'boolean'],
            'limit'           => ['nullable', 'integer', 'min:1', 'max:100'],
            'persist_results' => ['nullable', 'boolean'],
            'audience_name'   => ['nullable', 'string', 'max:120'],
            'source_name'     => ['nullable', 'string', 'max:120'],
            // ── URL-based search ──────────────────────────────────────────
            'linkedin_url'    => ['nullable', 'string', 'url', 'max:2048'],
            // ── Single profile import ─────────────────────────────────────
            'profile_url'     => ['nullable', 'string', 'url', 'max:2048'],
        ]);
    }
}
