<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler\Bridge\Amp\Http;

use Amp\Cancellation;
use Amp\File\File;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use function Amp\File\openFile;

class LogInterceptor implements ApplicationInterceptor
{
    private File $logStream;

    public function __construct(string $logFile)
    {
        $this->logStream = openFile($logFile, 'a');
    }

    public function request(Request $request, Cancellation $cancellation, DelegateHttpClient $httpClient): Response
    {
        $response = $httpClient->request($request, $cancellation);

        if (!$response->isRedirect()) {
            $this->logStream->write($request->getUri() . PHP_EOL);
        }

        return $response;
    }
}