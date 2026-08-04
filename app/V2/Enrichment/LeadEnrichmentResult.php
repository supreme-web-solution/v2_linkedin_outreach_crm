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
        public readonly bool $timedOut = false,
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
     * Soft FullEnrich poll timeout with no contact found — safe to retry, do not burn quota.
     */
    public function isSoftTimeout(): bool
    {
        return $this->timedOut && ! $this->hasAnyContact();
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
            timedOut: $this->timedOut,
        );
    }

    public function merge(self $other): self
    {
        $email = $this->email ?: $other->email;
        $phone = $this->phone ?: $other->phone;

        return new self(
            email: $email,
            phone: $phone,
            instagramHandle: $this->instagramHandle ?: $other->instagramHandle,
            twitterHandle: $this->twitterHandle ?: $other->twitterHandle,
            telegramHandle: $this->telegramHandle ?: $other->telegramHandle,
            emailLookupAttempted: $this->emailLookupAttempted || $other->emailLookupAttempted,
            phoneLookupAttempted: $this->phoneLookupAttempted || $other->phoneLookupAttempted,
            sources: array_values(array_unique([...$this->sources, ...$other->sources])),
            // Timeout only sticks if we still have no usable contact.
            timedOut: ($this->timedOut || $other->timedOut) && ($email ?? '') === '' && ($phone ?? '') === '',
        );
    }
}
