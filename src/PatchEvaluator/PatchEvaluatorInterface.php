<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler\PatchEvaluator;

interface PatchEvaluatorInterface
{
    public function evaluate(string $patch): ?string;
}