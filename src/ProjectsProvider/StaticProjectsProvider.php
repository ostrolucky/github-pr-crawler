<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler\ProjectsProvider;

class StaticProjectsProvider implements ProjectsProviderInterface
{
    public function provideProjects(): array
    {
        return [
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
    }
}