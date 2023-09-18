<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler\ProjectsProvider;

interface ProjectsProviderInterface
{
    /**
     * @return list<string> List of projects in form of GH's "{owner}/{repository} format
     */
    public function provideProjects(): array;
}