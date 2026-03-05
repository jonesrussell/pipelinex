<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class NorthCloudClient
{
    private string $crawlerUrl;

    private string $classifierUrl;

    private string $internalSecret;

    public function __construct()
    {
        $this->crawlerUrl = config('services.north_cloud.crawler_url');
        $this->classifierUrl = config('services.north_cloud.classifier_url');
        $this->internalSecret = config('services.north_cloud.internal_secret');
    }

    public function fetch(string $url, int $timeout = 15): array
    {
        $response = Http::withHeaders(['X-Internal-Secret' => $this->internalSecret])
            ->timeout($timeout + 5)
            ->post($this->crawlerUrl.'/api/internal/v1/fetch', [
                'url' => $url,
                'timeout' => $timeout,
            ]);

        $response->throw();

        return $response->json();
    }

    public function extract(string $html, string $url, ?string $title = null): array
    {
        $response = Http::withHeaders(['X-Internal-Secret' => $this->internalSecret])
            ->timeout(30)
            ->post($this->classifierUrl.'/api/internal/v1/extract', [
                'html' => $html,
                'url' => $url,
                'title' => $title,
                'source_name' => 'pipelinex',
            ]);

        $response->throw();

        return $response->json();
    }

    public function healthy(): bool
    {
        try {
            $crawler = Http::timeout(5)->get($this->crawlerUrl.'/health');
            $classifier = Http::timeout(5)->get($this->classifierUrl.'/health');

            return $crawler->ok() && $classifier->ok();
        } catch (\Throwable) {
            return false;
        }
    }
}
