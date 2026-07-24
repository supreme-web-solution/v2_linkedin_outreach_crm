<?php

namespace App\V2\Services\Esp;

use App\V2\Services\Esp\Adapters\EspProviderAdapterInterface;
use App\V2\Services\Esp\Adapters\GenericEspProviderAdapter;
use App\V2\Services\Esp\Adapters\HubspotEspProviderAdapter;
use App\V2\Services\Esp\Adapters\MailchimpEspProviderAdapter;
use App\V2\Services\Esp\Adapters\SendgridEspProviderAdapter;

class EspProviderAdapterFactory
{
    public function make(string $provider): EspProviderAdapterInterface
    {
        return match (strtolower(trim($provider))) {
            'mailchimp' => app(MailchimpEspProviderAdapter::class),
            'sendgrid' => app(SendgridEspProviderAdapter::class),
            'hubspot' => app(HubspotEspProviderAdapter::class),
            default => app(GenericEspProviderAdapter::class),
        };
    }
}
