<?php

namespace Caxy\HarvestForecast\Command;

use Caxy\ForecastApi\ForecastClient;
use Harvest\HarvestApi;
use Caxy\HarvestForecast\Service\SyncService;
use Harvest\Model\Range;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('app:sync')
            ->setDescription('Sync from harvest to forecast.')
            ->addArgument('startDate', InputArgument::OPTIONAL, 'Start date', null)
            ->addArgument('endDate', InputArgument::OPTIONAL, 'End date', null)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = require __DIR__.'/../../config.php';

        $harvest  = $this->createHarvestApi(
            $config['harvest']['user'],
            $config['harvest']['pass'],
            $config['harvest']['account']
        );
        $forecast = new ForecastClient(
            $config['forecast']['account'],
            $config['forecast']['auth']
        );
        $sync     = new SyncService($harvest, $forecast);

        $startDate = $input->getArgument('startDate');
        $endDate   = $input->getArgument('endDate');
        $range     = null;

        if (empty($startDate) && empty($endDate)) {
            $output->writeln('Defaulting date range to current month.');
            $range = Range::thisMonth();
        } else {
            if (empty($startDate)) {
                $output->writeln('Start date is required');
                exit(1);
            }

            if (empty($endDate)) {
                $endDate = date('Y-m-d');
            }

            $range = new Range($startDate, $endDate);
        }

        $output->writeln(sprintf(
            'Syncing harvest data into forecast from %s to %s',
            $range->from(),
            $range->to())
        );
        $errors = $sync->sync($range);
        $output->writeln('Sync complete!');
        if (count($errors) > 0) {
            $output->writeln(str_repeat('=', 75));
            $output->writeln('Warnings reported during sync');
            $output->writeln(str_repeat('-', 75));
            foreach ($errors as $error) {
                $output->writeln(" - $error");
            }
            $output->writeln(str_repeat('=', 75));
            $output->writeln('May need to import users or projects into Forecast and re-run.');
        }
    }

    /**
     * @param string $user
     * @param string $password
     * @param string $account
     *
     * @return HarvestApi
     */
    protected function createHarvestApi($user, $password, $account)
    {
        $api = new HarvestApi();
        $api->setUser($user);
        $api->setPassword($password);
        $api->setAccount($account);

        return $api;
    }
}
