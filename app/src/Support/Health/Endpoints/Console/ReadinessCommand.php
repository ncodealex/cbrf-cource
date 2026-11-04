<?php

declare(strict_types=1);

namespace App\Support\Health\Endpoints\Console;

use App\Support\Health\Application\ReadinessChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:health:ready', description: 'Проверить готовность приложения и инфраструктуры')]
final class ReadinessCommand extends Command
{
    public function __construct(
        private readonly ReadinessChecker $readinessChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->readinessChecker->check();
        foreach ($report->checks as $name => $status) {
            $output->writeln($name.': '.$status);
        }

        return $report->isReady() ? Command::SUCCESS : Command::FAILURE;
    }
}
