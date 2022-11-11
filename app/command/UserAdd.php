<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use support\Db;


class UserAdd extends Command
{
    protected static $defaultName = 'user:add';
    protected static $defaultDescription = 'Creates a new entry in the users table';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'New Username');
        $this->addArgument('password', InputArgument::OPTIONAL, 'New Password');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $password = $input->getArgument('password');
        $result = Db::connection('mongodb')->collection('users')->insert(['username' => $name, 'password' =>$password]);
        $output->writeln('Hello user:add returnerd '.var_export($result, true));
        return self::SUCCESS;
    }

}
