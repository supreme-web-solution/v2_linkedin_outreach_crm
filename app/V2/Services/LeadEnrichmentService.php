<?php

namespace App\V2\Services;

use App\Models\SnLead;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Enrichment\LeadEnrichmentInput;
use App\V2\Enrichment\LeadEnrichmentResult;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;

class LeadEnrichmentService
{
    public function __construct(
        private readonly UnipileProfileEmailService $emailService,
        private readonly LinkedInProfileSocialExtractor $socialExtractor,
        private readonly FullEnrichClient $fullEnrich,
    ) {}

    public function enrich(User $user, LeadEnrichmentInput $input): LeadEnrichmentResult
    {
        $result = new LeadEnrichmentResult;

        if (V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            $unipile = $this->enrichFromUnipile($user, $input);
            $result = $result->merge($unipile);
        }

        $stillNeeds = new LeadEnrichmentInput(
            firstName: $input->firstName,
            lastName: $input->lastName,
            linkedinUrl: $input->linkedinUrl,
            linkedinIdentifier: $input->linkedinIdentifier,
            companyName: $input->companyName,
            companyDomain: $input->companyDomain,
            existingEmail: $input->existingEmail ?: $result->email,
            existingPhone: $input->existingPhone ?: $result->phone,
        );

        if ($this->fullEnrich->isConfigured() && $stillNeeds->needsExternalEnrichment()) {
            $result = $result->merge($this->fullEnrich->enrich($stillNeeds));
        }

        return $this->finalizeEnrichmentResult($input, $result);
    }

    private function finalizeEnrichmentResult(LeadEnrichmentInput $input, LeadEnrichmentResult $result): LeadEnrichmentResult
    {
        $softTimeout = $result->isSoftTimeout();

        return new LeadEnrichmentResult(
            email: $result->email,
            phone: $result->phone,
            instagramHandle: $result->instagramHandle,
            twitterHandle: $result->twitterHandle,
            telegramHandle: $result->telegramHandle,
            // Soft timeout is retryable — do not mark the lookup as a finished "not found".
            emailLookupAttempted: $softTimeout ? false : ($result->emailLookupAttempted || $input->needsEmail()),
            phoneLookupAttempted: $softTimeout ? $result->phoneLookupAttempted : ($result->phoneLookupAttempted || $input->needsPhone()),
            sources: $result->sources,
            timedOut: $softTimeout,
        );
    }

    private function enrichFromUnipile(User $user, LeadEnrichmentInput $input): LeadEnrichmentResult
    {
        $identifier = trim((string) ($input->linkedinIdentifier ?? ''));
        if ($identifier === '' && $input->linkedinUrl && preg_match('/\/in\/([^\/\?]+)/', $input->linkedinUrl, $m)) {
            $identifier = $m[1];
        }

        if ($identifier === '') {
            return new LeadEnrichmentResult;
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountId($user->id);
        if (! $accountId) {
            return new LeadEnrichmentResult;
        }

        /** @var UnipileProvider $provider */
        $provider = app(ProviderManager::class)->get('unipile', UnipileProvider::class);

        $profile = preg_match('/^(ACo|ADo|ACw|AE)/i', $identifier)
            ? $provider->getProfileByIdentifier($identifier, ['account_id' => $accountId, 'linkedin_sections' => '*'])
            : $provider->getProfileByIdentifier(
                preg_match('/linkedin\.com\/in\/([^\/\?]+)/i', $identifier, $m) ? $m[1] : $identifier,
                ['account_id' => $accountId, 'linkedin_sections' => '*'],
            );

        $social = $this->socialExtractor->extract($profile);

        return new LeadEnrichmentResult(
            email: $input->needsEmail() ? $provider->extractProfileEmail($profile) : null,
            phone: $input->needsPhone() ? $provider->extractProfilePhone($profile) : null,
            instagramHandle: $social['instagram_handle'],
            twitterHandle: $social['twitter_handle'],
            telegramHandle: $social['telegram_handle'],
            emailLookupAttempted: $input->needsEmail(),
            phoneLookupAttempted: $input->needsPhone(),
            sources: ['unipile'],
        );
    }

    public function inputFromAudienceList(\App\Models\AudienceList $item): LeadEnrichmentInput
    {
        $domain = '';
        if (! empty($item->con_company_url)) {
            $host = parse_url($item->con_company_url, PHP_URL_HOST);
            $domain = is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : '';
        }

        return new LeadEnrichmentInput(
            firstName: $item->con_first_name,
            lastName: $item->con_last_name,
            linkedinUrl: $item->con_profile_url,
            linkedinIdentifier: $item->con_public_identifier ?: $item->con_id,
            companyName: $item->con_company_name,
            companyDomain: $domain ?: null,
            existingEmail: $item->con_email,
            existingPhone: $item->con_phone,
        );
    }

    public function inputFromSnLead(SnLead $lead): LeadEnrichmentInput
    {
        $company = $lead->relationLoaded('company') ? $lead->company : null;
        $domain = '';
        if ($company?->company_website) {
            $host = parse_url($company->company_website, PHP_URL_HOST);
            $domain = is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : '';
        }

        return new LeadEnrichmentInput(
            firstName: $lead->first_name,
            lastName: $lead->last_name,
            linkedinIdentifier: $lead->lid ?: $lead->sn_lid,
            companyName: $company?->company_name,
            companyDomain: $domain ?: null,
            existingEmail: $lead->email,
            existingPhone: $lead->phone,
        );
    }
}
