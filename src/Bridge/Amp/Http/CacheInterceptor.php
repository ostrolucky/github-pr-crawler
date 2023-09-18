<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler\Bridge\Amp\Http;

use Amp\Cancellation;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use function Amp\File\read;
use function Amp\File\write;

class CacheInterceptor implements ApplicationInterceptor
{
    public function __construct(private string $cacheDir)
    {
    }

    public function request(Request $request, Cancellation $cancellation, DelegateHttpClient $httpClient): Response
    {
        $cachePath = "$this->cacheDir/".md5($request->getUri() . $request->getBody()->getContent()->read());
        if (file_exists($cachePath)) {
            return new Response('2', 200, null, [], read($cachePath), $request);
        }

        $response = $httpClient->request($request, $cancellation);

        if ($response->isRedirect()) {
            return $response;
        }

        $responseContent = $response->getBody()->buffer();

        if ($response->isSuccessful()) {
            write($cachePath, $responseContent);
        }

        return new Response(
            $response->getProtocolVersion(),
            $response->getStatus(),
            $response->getReason(),
            $response->getHeaders(),
            $responseContent,
            $request,
            $response->getTrailers(),
        );
    }
}