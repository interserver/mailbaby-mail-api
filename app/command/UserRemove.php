<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use support\Db;


class UserRemove extends Command
{
    protected static $defaultName = 'user:remove';
    protected static $defaultDescription = 'Removes a user from the system';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Name description');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $result = Db::connection('mongodb')
            ->collection('users')
            ->where('username', $name)
            ->delete();
        $output->writeln('Removing a User returned:'.var_export($result, true));
        $output->writeln('Hello user:remove');
        return self::SUCCESS;
    }

}
