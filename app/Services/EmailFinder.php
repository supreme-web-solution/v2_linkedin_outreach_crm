<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class EmailFinder
{
    private $key;

    public function __construct(
        public $data
    )
    {
        $this->first_name = $data['firstName'];
        $this->last_name = $data['lastName'];
        $this->website = $data['website'];
        $this->key = config('services.skrapp.key');
    }

    public function findEmail()
    {
        $url = 'https://api.skrapp.io/api/v2/find';

        $headers = [
            'X-Access-Key' => $this->key,
            'Content-Type' => 'application/json'
        ];

        $params = [
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'domain' => $this->website
        ];

        return Http::withHeaders($headers)
            ->withQueryParameters($params)
            ->get($url)
            ->throw()
            ->json();
    }
}