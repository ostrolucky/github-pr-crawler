<?php

declare(strict_types=1);

namespace Ostrolucky\GithubPRCrawler;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

class MatrixMonitor
{
    private Table $table;
    /** @var array<string, array{0: string, 1: int, 2: int, 3?: string} */
    private array $tableRows;

    /** @param list<string> $projects */
    public function __construct(array $projects)
    {
        sort($projects);
        $this->table = new Table((new ConsoleOutput())->section());
        $this->tableRows = array_combine($projects, array_map(fn(string $project) => [$project, 0, 0], $projects));
    }

    public function __invoke(): void
    {
        foreach ($this->tableRows as &$row) {
            $row[3] = ($row[2] ? (int)($row[1]/$row[2]*100) : 0).'%';
        }
        $this->table->setRows(array_map(fn(array $r) => array_merge(...$r), array_chunk($this->tableRows, 3)));
        $this->table->render();
    }

    public function removeProject(string $project): void
    {
        unset($this->tableRows[$project]);
    }

    public function advance(string $project): void
    {
        $this->tableRows[$project][1]++;
    }

    public function setMax(string $project, int $count): void
    {
        if (empty($this->tableRows[$project][2])) {
            $this->tableRows[$project][2] = $count;
        }
    }

    public function incrementMax(string $project): void
    {
        $this->tableRows[$project][2]++;
    }


}