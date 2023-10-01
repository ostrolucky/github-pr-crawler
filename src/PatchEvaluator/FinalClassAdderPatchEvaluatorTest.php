<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler\PatchEvaluator;

use PHPUnit\Framework\TestCase;

class FinalClassAdderPatchEvaluatorTest extends TestCase
{
    private FinalClassAdderPatchEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FinalClassAdderPatchEvaluator();
    }

    public function testEvaluate(): void
    {
        // https://github.com/symfony/symfony/pull/23310
        self::assertSame('Definition', $this->evaluator->evaluate(<<<PATCH
            diff --git a/src/Symfony/Component/Workflow/Definition.php b/src/Symfony/Component/Workflow/Definition.php
            index 5f8571b329a0..df9e78bf6e68 100644
            --- a/src/Symfony/Component/Workflow/Definition.php
            +++ b/src/Symfony/Component/Workflow/Definition.php
            @@ -19,7 +19,7 @@
              * @author Grégoire Pineau <lyrixx@lyrixx.info>
              * @author Tobias Nyholm <tobias.nyholm@gmail.com>
              */
            -class Definition
            +final class Definition
             {
                 private \$places = array();
                 private \$transitions = array();
        PATCH));
        // https://github.com/doctrine/orm/pull/1215
        self::assertSame('Query', $this->evaluator->evaluate(<<<PATCH
            diff --git a/lib/Doctrine/ORM/Query.php b/lib/Doctrine/ORM/Query.php
            index 6839b480df..8c638e0412 100644
            --- a/lib/Doctrine/ORM/Query.php
            +++ b/lib/Doctrine/ORM/Query.php
            @@ -35,7 +35,7 @@
              * @author  Konsta Vesterinen <kvesteri@cc.hut.fi>
              * @author  Roman Borschel <roman@code-factory.org>
              */
            -class Query extends AbstractQuery
            +final class Query extends AbstractQuery
             {
        PATCH));
        // https://github.com/doctrine/orm/pull/6180
        self::assertSame('Query', $this->evaluator->evaluate(<<<PATCH
            diff --git a/lib/Doctrine/ORM/Query.php b/lib/Doctrine/ORM/Query.php
            index 12fb3f560e..4f054ef433 100644
            --- a/lib/Doctrine/ORM/Query.php
            +++ b/lib/Doctrine/ORM/Query.php
            @@ -35,7 +35,7 @@
              * @author  Konsta Vesterinen <kvesteri@cc.hut.fi>
              * @author  Roman Borschel <roman@code-factory.org>
              */
            -/* final */class Query extends AbstractQuery
            +final class Query extends AbstractQuery
             {
        PATCH));
    }
}