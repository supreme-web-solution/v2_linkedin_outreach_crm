<?php

namespace App\V2\Enrichment;

class LeadEnrichmentResult
{
    /**
     * @param  array<int, string>  $sources
     */
    public function __construct(
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $instagramHandle = null,
        public readonly ?string $twitterHandle = null,
        public readonly ?string $telegramHandle = null,
        public readonly bool $emailLookupAttempted = false,
        public readonly bool $phoneLookupAttempted = false,
        public readonly array $sources = [],
    ) {}

    public function hasAnyContact(): bool
    {
        return ($this->email ?? '') !== ''
            || ($this->phone ?? '') !== ''
            || ($this->instagramHandle ?? '') !== ''
            || ($this->twitterHandle ?? '') !== ''
            || ($this->telegramHandle ?? '') !== '';
    }

    /**
     * @param  array<int, string>  $sources
     */
    public function withSources(array $sources): self
    {
        return new self(
            email: $this->email,
            phone: $this->phone,
            instagramHandle: $this->instagramHandle,
            twitterHandle: $this->twitterHandle,
            telegramHandle: $this->telegramHandle,
            emailLookupAttempted: $this->emailLookupAttempted,
            phoneLookupAttempted: $this->phoneLookupAttempted,
            sources: array_values(array_unique([...$this->sources, ...$sources])),
        );
    }

    public function merge(self $other): self
    {
        return new self(
            email: $this->email ?: $other->email,
            phone: $this->phone ?: $other->phone,
            instagramHandle: $this->instagramHandle ?: $other->instagramHandle,
            twitterHandle: $this->twitterHandle ?: $other->twitterHandle,
            telegramHandle: $this->telegramHandle ?: $other->telegramHandle,
            emailLookupAttempted: $this->emailLookupAttempted || $other->emailLookupAttempted,
            phoneLookupAttempted: $this->phoneLookupAttempted || $other->phoneLookupAttempted,
            sources: array_values(array_unique([...$this->sources, ...$other->sources])),
        );
    }
}
