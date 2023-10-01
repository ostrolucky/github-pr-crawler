<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler;

use Ostrolucky\GithubPRCrawler\PatchEvaluator\PatchEvaluatorInterface;
use function Amp\async;
use function Amp\Future\await;

class Crawler {
    private GithubApiClient $client;

    public function __construct(
        private PatchEvaluatorInterface $patchEvaluator,
        private array $projects,
        private MatrixMonitor $monitor,
    )
    {
        $this->client = new GithubApiClient();
    }

    public function crawl(): void
    {
        do {
            foreach ($this->projects as $project) {
                $futures[] = async(function() use ($project, &$nextCursors, &$futures) {
                    $data = $this->client->fetchGraphQL($project, $nextCursors[$project] ?? null);

                    $this->monitor->setMax($project, $data['totalCount']);

                    foreach ($data['edges'] as $edge) {
                        $this->monitor->advance($project);
                        foreach ($edge['node']['files']['nodes'] ?? [] as $fileNode) {
                            if ($fileNode['changeType'] !== 'MODIFIED') {
                                continue;
                            }

                            if (!str_ends_with($fileNode['path'], '.php')) {
                                continue;
                            }

                            $this->monitor->incrementMax($project);
                            $futures[] = async(function () use ($project, $edge) {
                                if (!$patch = $this->client->fetch("{$edge['node']['permalink']}.diff")->getBody()->buffer()) {
                                    $this->monitor->advance($project);
                                    return;
                                }

                                $this->monitor->advance($project);
                                if ($class = $this->patchEvaluator->evaluate($patch)) {
                                    file_put_contents(
                                        'matches',
                                        sprintf(
                                            "%s - %s (%s.php)\n",
                                            $edge['node']['permalink'],
                                            $edge['node']['title'],
                                            $class,
                                        ),
                                        FILE_APPEND,
                                    );
                                }
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
            await($futures);
            $futures = [];
        } while (array_filter($nextCursors));
    }
}