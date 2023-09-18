<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler\ProjectsProvider;

/**
 * @see https://github.com/nikic/php-crater/blob/master/download.php
 * TODO: Transform packagist project slug to github project slug
 * TODO: Implement pagination support
 */
class TopPackagistProjectsProvider implements ProjectsProviderInterface
{
    public function __construct(private int $maxResults)
    {
    }

    private const PAGE_SIZE = 15;

    public function provideProjects(): array
    {
        return array_column(
            json_decode(file_get_contents("https://packagist.org/explore/popular.json?page=1"), true),
            'name',
        );
    }
}