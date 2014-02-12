<?php
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

$console->register('searchdTerminate')
    ->setDefinition(
        array(
            new InputArgument('timeout', InputArgument::REQUIRED, 'Maximum searchd live time, minutes'),
        )
    )
    ->setDescription('Terminate searchd instances')
    ->setCode(
        function (InputInterface $input, OutputInterface $output) use ($app) {
            /** @var string $timeout */
            $timeout = $input->getArgument('timeout');
            /** @var \app\components\SphinxManager $sphinx */
            $sphinx = $app['sphinx'];

            foreach (glob($sphinx->getIndexDirectory() . '/user*.pid') as $pidFilename) {
                if (filemtime($pidFilename) < time() - $timeout*60) {
                    $pid = trim(file_get_contents($pidFilename));
                    $processStartedTime = @filectime('/proc/' . $pid);
                    if (!$processStartedTime) {
                        unlink($pidFilename);
                        continue;
                    }

                    $configFilename = str_replace('.pid', '.conf', $pidFilename);
                    $sphinx->terminateDaemon($configFilename);
                }
            }
        }
    );
