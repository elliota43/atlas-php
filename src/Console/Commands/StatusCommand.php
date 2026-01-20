<?php

namespace SchemaOps\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('schema:status')
            ->setDescription('Show current schema status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>SchemaOps Status</info>');
        $output->writeln('Version: 0.1.0');
        $output->writeln('');

        // Check if lockfile exists
        if (file_exists(getcwd() . '/schema.lock')) {
            $output->writeln('<fg=green>✓</> Lockfile found');
        } else {
            $output->writeln('<fg=yellow>!</> No lockfile found');
        }

        // Check db connection
        $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
        $output->writeln("Database: {$dbHost}");

        return Command::SUCCESS;
    }
}