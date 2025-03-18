<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use support\Db;


class UserList extends Command
{
    protected static $defaultName = 'user:list';
    protected static $defaultDescription = 'user list';

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = Db::connection('mongodb')->collection('users')->get();
        $output->writeln('MailBaby User Listing is as followsMySQL configuration information is as follows:');
        $config = config('database');
        $headers = ['username', 'password'];
        $rows = [];
        foreach ($result as $row) {
            $rows[] = [$row['username'], $row['password']];
        }
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
        return self::SUCCESS;
    }

}
