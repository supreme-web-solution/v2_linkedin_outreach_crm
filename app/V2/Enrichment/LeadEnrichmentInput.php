<?php

namespace App\V2\Enrichment;

class LeadEnrichmentInput
{
    public function __construct(
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $linkedinUrl = null,
        public readonly ?string $linkedinIdentifier = null,
        public readonly ?string $companyName = null,
        public readonly ?string $companyDomain = null,
        public readonly ?string $existingEmail = null,
        public readonly ?string $existingPhone = null,
    ) {}

    public function linkedinUrlOrBuild(): ?string
    {
        if ($this->linkedinUrl) {
            return $this->linkedinUrl;
        }

        $id = trim((string) ($this->linkedinIdentifier ?? ''));
        if ($id === '') {
            return null;
        }

        if (str_contains($id, 'linkedin.com')) {
            return $id;
        }

        return 'https://www.linkedin.com/in/'.$id;
    }

    public function needsEmail(): bool
    {
        return ($this->existingEmail ?? '') === '';
    }

    public function needsPhone(): bool
    {
        return ($this->existingPhone ?? '') === '';
    }

    public function needsExternalEnrichment(): bool
    {
        return $this->needsEmail() || $this->needsPhone();
    }
}
