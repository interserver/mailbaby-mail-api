<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use support\Db;


class UserPass extends Command
{
    protected static $defaultName = 'user:pass';
    protected static $defaultDescription = 'Modifies the password for a given uyser.';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Name description');
        $this->addArgument('password', InputArgument::OPTIONAL, 'New Password');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $password = $input->getArgument('password');

        $output->writeln('Hello user:pass');
        return self::SUCCESS;
    }

}
