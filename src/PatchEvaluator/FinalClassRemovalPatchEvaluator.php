<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler\PatchEvaluator;

class FinalClassRemovalPatchEvaluator implements PatchEvaluatorInterface
{

    public function evaluate(string $patch): ?string
    {
        if (!preg_match('#-final class (\w+)#m', $patch, $matches)) {
            return null;
        }

        if (!preg_match("#\+class $matches[1][\s\n]#", $patch) && !preg_match("#\+.*/\*.*class $matches[1] #", $patch)) {
            return null;
        }

        return $matches[1];
    }
}