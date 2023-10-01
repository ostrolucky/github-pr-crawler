<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler;

use Amp\Http\Client\Connection\StreamLimitingPool;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\AddRequestHeader;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Sync\LocalKeyedSemaphore;
use Ostrolucky\GithubPRCrawler\Bridge\Amp\Http\CacheInterceptor;
use Ostrolucky\GithubPRCrawler\Bridge\Amp\Http\LogInterceptor;

class GithubApiClient
{
    private const GRAPHQL_URL = 'https://api.github.com/graphql';
    private DelegateHttpClient $httpClient;

    public function __construct()
    {
        $this->httpClient = (new HttpClientBuilder())
            ->followRedirects(5)
            ->intercept(new AddRequestHeader('Authorization', "Bearer {$_ENV['GITHUB_TOKEN']}"))
            ->intercept(new AddRequestHeader('Accept', 'application/json'))
            ->intercept(new CacheInterceptor(__DIR__.'/../cache'))
            ->intercept(new LogInterceptor(__DIR__.'/../log'))
            ->usingPool(StreamLimitingPool::byHost(new UnlimitedConnectionPool(), new LocalKeyedSemaphore(4)))
            ->build();
    }

    public function fetchGraphQL(string $project, ?string $before): array
    {
        while (true) {
            $response = $this->fetch(self::GRAPHQL_URL, 'POST', $this->createGraphQLBody($project, $before));

            $data = json_decode($response->getBody()->buffer(), true);

            if ($response->isSuccessful()) {
                break;
            }

            sleep((int)$response->getHeader('retry-after') ?: (int)$response->getHeader('x-ratelimit-reset') ?: 5);
        }

        return $data['data']['repository']['pullRequests'];
    }

    public function fetch(string $url, string $method = 'GET', string $body = ''): Response
    {
        $request = new Request($url, $method, $body);
        $request->setBodySizeLimit(Request::DEFAULT_BODY_SIZE_LIMIT * 1000);
        $request->setTransferTimeout(60 * 5);
        $request->setInactivityTimeout(60 * 5);

        return $this->httpClient->request($request);
    }

    private function createGraphQLBody(string $project, ?string $startCursor): string
    {
        $before = $startCursor ? 'before: "'.$startCursor.'"' : '';
        [$owner, $name] = explode('/', $project);

        return json_encode(['query' => <<<GRAPHQL
            query {
                repository(owner: "$owner", name: "$name") {
                    pullRequests(last: 50, $before) {
                        totalCount
                        edges {
                            node {
                                id
                                title
                                permalink
                                state
                                totalCommentsCount
                                createdAt
                                files(last: 100) {
                                    totalCount
                                    nodes {
                                        path
                                        changeType
                                    }
                                }
                            }
                        }
                        pageInfo {
                            hasPreviousPage
                            startCursor
                        }
                    }
                }
            }
        GRAPHQL]);
    }
}