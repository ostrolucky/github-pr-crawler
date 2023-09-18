<?php

declare(strict_types=1);

use Amp\Cancellation;
use Amp\File\File;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\Connection\StreamLimitingPool;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\AddRequestHeader;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException as UniqueConstraintViolationExceptionAlias;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;
use function Amp\File\openFile;
use function Amp\File\read;
use function Amp\File\write;

require __DIR__ . '/vendor/autoload.php';

$connection = DriverManager::getConnection(['path' => 'db.sqlite', 'driver' => 'pdo_sqlite']);
$client = (new HttpClientBuilder())
    ->followRedirects(5)
    ->intercept(new AddRequestHeader('Authorization', 'Bearer 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'))
    ->intercept(new AddRequestHeader('Accept', 'application/json'))
    ->intercept(new class(openFile('log', 'a')) implements ApplicationInterceptor {
        public function __construct(private File $logStream)
        {
        }

        public function request(Request $request, Cancellation $cancellation, DelegateHttpClient $httpClient): Response
        {
            $cachePath = 'cache/'.md5($request->getUri()->__toString() . $request->getBody()->getContent()->read());
            if (file_exists($cachePath)) {
                return new Response(
                    '2',
                    200,
                    null,
                    [],
                    read($cachePath),
                    $request,
                );
            }

            $response = $httpClient->request($request, $cancellation);

            if ($response->isRedirect()) {
                return $response;
            }

            $this->logStream->write($request->getUri().PHP_EOL);
            $responseContent = $response->getBody()->buffer();

            if ($response->getStatus() === 200) {
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
    })
    ->usingPool(StreamLimitingPool::byHost(new UnlimitedConnectionPool(), new \Amp\Sync\LocalKeyedSemaphore(4)))
    ->build();

$makeBody = function (string $project, ?string $startCursor): string {
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
};

$fetch = function(string $url, string $method = 'GET', string $body = '') use ($client): Response {
    $request = new Request($url, $method, $body);
    $request->setBodySizeLimit(Request::DEFAULT_BODY_SIZE_LIMIT * 1000);
    $request->setTransferTimeout(60 * 5);
    $request->setInactivityTimeout(60 * 5);

    return $client->request($request);
};

// from https://github.com/nikic/php-crater/blob/master/download.php
$getTopPackages = function($min, $max) {
    $perPage = 15;
    $page = intdiv($min, $perPage);
    $id = $page * $perPage;
    while (true) {
        $page++;
        $url = 'https://packagist.org/explore/popular.json?page=' . $page;
        $json = json_decode(file_get_contents($url), true);
        foreach ($json['packages'] as $package) {
            yield $id => $package['name'];
            $id++;
            if ($id >= $max) {
                return;
            }
        }
    }
};

$nextCursors = [];
$futures = [];
$futures2 = [];
$projects = [
    'php-fig/http-factory',
    'php-fig/cache',
    'doctrine/lexer',
    'sebastianbergmann/phpunit',
    'phar-io/version',
    'theseer/tokenizer',
    'php-fig/event-dispatcher',
    'phar-io/manifest',
    'egulias/EmailValidator',
    'sebastianbergmann/type',
    'ramsey/uuid',
    'doctrine/deprecations',
    'briannesbitt/Carbon',
    'brick/math',
    'ramsey/collection',
    'aws/aws-sdk-php',
    'jmespath/jmespath.php',
    'doctrine/annotations',
    'firebase/php-jwt',
    'moneyphp/money',
];
sort($projects);
$consoleOutput = (new ConsoleOutput())->section();
$table = new Table($consoleOutput);
$tableRows = array_combine($projects, array_map(fn(string $project) => [$project, 0, 0], $projects));
$initialProjectSyncStats = $connection->fetchAllAssociativeIndexed('SELECT project, count(*) as count FROM pulls GROUP BY project');

\Revolt\EventLoop::repeat(2, function() use ($table, $consoleOutput, &$tableRows) {
    foreach ($tableRows as &$row) {
        $row[3] = ($row[2] ? (int)($row[1]/$row[2]*100) : 0).'%';
    }
//    $consoleOutput->clear();
    $table->setRows(array_map(fn(array $r) => array_merge(...$r), array_chunk($tableRows, 3)));
    $table->render();
});
do {
    foreach ($projects as $projectKey => $project) {
        if (array_key_exists($project, $nextCursors) && !$nextCursors[$project]) {
            continue;
        }

        $futures[] = \Amp\async(function() use ($initialProjectSyncStats, $table, &$tableRows, $consoleOutput, $connection, $client, &$projects, $projectKey, $project, $makeBody, $fetch, &$nextCursors, &$progresses, &$futures2) {
            while (true) {
                $response = $fetch('https://api.github.com/graphql', 'POST', $makeBody($project, $nextCursors[$project] ?? null));
                $data = json_decode(
                    $response->getBody()->read(),
                    true,
                );

                if ($response->isSuccessful()) {
                    break;
                }

                sleep((int)$response->getHeader('retry-after') ?: (int)$response->getHeader('x-ratelimit-reset') ?: 5);
            }

            $data = $data['data']['repository']['pullRequests'];

            if (($initialProjectSyncStats[$project]['count'] ?? null) === $data['totalCount']) {
                // project up to date
                unset($projects[$projectKey], $tableRows[$project]);
                return;
            }

//            $pullIds = array_map(fn(array $edge) => $edge['node']['id'], $data['edges']);
//            $existingIds = $connection->fetchAllAssociative(
//                'SELECT id FROM pulls where id IN (:ids)',
//                ['ids' => $pullIds],
//                ['ids' => ArrayParameterType::STRING],
//            );
//
//            if (count($pullIds) === count($existingIds)) {
//                // project up to date
//                unset($projects[$projectKey]);
//                return;
//            }

            if (empty($tableRows[$project][2])) {
                $tableRows[$project][2] = $data['totalCount'];
            }

            foreach ($data['edges'] as $edge) {
                $tableRows[$project][1]++;
                $dbData = [
                    'id' => $edge['node']['id'],
                    'title' => $edge['node']['title'],
                    'createdAt' => $edge['node']['createdAt'],
                    'link' => $edge['node']['permalink'],
                    'commentsCount' => $edge['node']['totalCommentsCount'],
                    'state' => $edge['node']['state'],
                    'project' => $project,
                ];

                try {
                    $connection->insert('pulls', $dbData);
                } catch (UniqueConstraintViolationExceptionAlias) {
                    $connection->update('pulls', $dbData, ['id' => $dbData['id']]);
                }

                foreach ($edge['node']['files']['nodes'] ?? [] as $fileNode) {
                    $dbData = [
                        'pull_id' => $edge['node']['id'],
                        'path' => $fileNode['path'],
                        'changeType' => $fileNode['changeType'],
                    ];

                    try {
                        $connection->insert('pull_files', $dbData);
                    }
                    catch (UniqueConstraintViolationExceptionAlias) {
                        continue;
                    }

                    if ($fileNode['changeType'] !== 'MODIFIED') {
                        continue;
                    }

                    if (!str_ends_with($fileNode['path'], '.php')) {
                        continue;
                    }

                    $tableRows[$project][2]++;
                    $futures2[] = \Amp\async(function () use ($project, $table, $consoleOutput, &$tableRows, $connection, $fetch, $edge, $client) {
                        if (!$patch = $fetch("{$edge['node']['permalink']}.diff")->getBody()->read()) {
                            $tableRows[$project][1]++;
                            return;
                        }
                        $tableRows[$project][1]++;
                        $connection->update('pulls', ['diff' => $patch], ['id' => $edge['node']['id']]);

                        if (!preg_match('#-final class (\w+)#m', $patch, $matches)) {
                            return;
                        }

                        if (!str_contains($patch, "+class $matches[1] ") && !preg_match("#\+.*/\*.*class $matches[1] #", $patch)) {
                            return;
                        }

                        file_put_contents('matches', $edge['node']['permalink'] . ' - ' . $edge['node']['title']."($matches[1].php)".PHP_EOL, FILE_APPEND);
                    });
                    break;
                }
            }

            if (!isset($data['pageInfo']['hasPreviousPage'])) {
                $nextCursors[$project] = null;
                return;
            }

            $nextCursors[$project] = $data['pageInfo']['startCursor'];

        });
    }
    \Amp\Future\await($futures);
    \Amp\Future\await($futures2);
    $futures = [];
} while (array_filter($nextCursors));