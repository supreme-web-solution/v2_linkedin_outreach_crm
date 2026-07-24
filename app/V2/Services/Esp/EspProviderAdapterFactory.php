<?php

namespace App\V2\Services\Esp;

use App\V2\Services\Esp\Adapters\ActiveCampaignEspProviderAdapter;
use App\V2\Services\Esp\Adapters\BrevoEspProviderAdapter;
use App\V2\Services\Esp\Adapters\ConvertKitEspProviderAdapter;
use App\V2\Services\Esp\Adapters\EspProviderAdapterInterface;
use App\V2\Services\Esp\Adapters\GenericEspProviderAdapter;
use App\V2\Services\Esp\Adapters\GetResponseEspProviderAdapter;
use App\V2\Services\Esp\Adapters\HubspotEspProviderAdapter;
use App\V2\Services\Esp\Adapters\KlaviyoEspProviderAdapter;
use App\V2\Services\Esp\Adapters\MailchimpEspProviderAdapter;
use App\V2\Services\Esp\Adapters\MailerLiteEspProviderAdapter;
use App\V2\Services\Esp\Adapters\SendgridEspProviderAdapter;

class EspProviderAdapterFactory
{
    public function make(string $provider): EspProviderAdapterInterface
    {
        return match (strtolower(trim($provider))) {
            'mailchimp' => app(MailchimpEspProviderAdapter::class),
            'sendgrid' => app(SendgridEspProviderAdapter::class),
            'hubspot' => app(HubspotEspProviderAdapter::class),
            'klaviyo' => app(KlaviyoEspProviderAdapter::class),
            'brevo' => app(BrevoEspProviderAdapter::class),
            'activecampaign' => app(ActiveCampaignEspProviderAdapter::class),
            'mailerlite' => app(MailerLiteEspProviderAdapter::class),
            'convertkit' => app(ConvertKitEspProviderAdapter::class),
            'getresponse' => app(GetResponseEspProviderAdapter::class),
            default => app(GenericEspProviderAdapter::class),
        };
    }
}
