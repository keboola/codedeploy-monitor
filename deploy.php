<?php

require_once 'vendor/autoload.php';

date_default_timezone_set('UTC');

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeployCommand extends \Symfony\Component\Console\Command\Command
{
    protected function configure()
    {
        $this
            ->setName('deploy')
            ->setDescription('Deploy revision')
            ->addOption('application-name', null, InputOption::VALUE_REQUIRED)
            ->addOption('deployment-group-name', null, InputOption::VALUE_REQUIRED)
            ->addOption('deployment-config-name', null, InputOption::VALUE_REQUIRED)
            ->addOption('s3-location', null, InputOption::VALUE_REQUIRED)
            ->addOption('region', null, InputOption::VALUE_REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = new \Aws\CodeDeploy\CodeDeployClient([
            'version' => '2014-10-06',
            'region' => $input->getOption('region'),
        ]);

        $location = $this->parseLocation($input->getOption('s3-location'));
        $requiredCols = [
          'bucket',
          'bundleType',
          'key',
        ];

        $missing = array_diff($requiredCols, array_keys($location));
        if (!empty($missing)) {
            throw new \Exception(sprintf("Missing key for s3-location: %s", implode(', ', $missing)));
        }

        $deployment = $client->createDeployment([
            'applicationName' => $input->getOption('application-name'),
            'deploymentConfigName' => $input->getOption('deployment-config-name'),
            'deploymentGroupName' => $input->getOption('deployment-group-name'),
            'revision' => [
                'revisionType' => 'S3',
                's3Location'=> $location,
            ],
        ]);

        $id = $deployment->get('deploymentId');
        $output->writeln('DeploymentId: ' . $id);

        do {
            $deployment = $client->getDeployment(['deploymentId' => $id]);
            $output->writeln('Status: ' . $deployment->search('deploymentInfo.status'));

            if ($deployment->search('deploymentInfo.status') === 'Failed') {
                $output->writeln('ErrorInformation: ' . $deployment->search('deploymentInfo.errorInformation.code'));
                $output->writeln('ErrorInformation: ' . $deployment->search('deploymentInfo.errorInformation.message'));
                throw new \Exception('Deployment failed');
            }

            sleep(1);
        } while(!in_array($deployment->search('deploymentInfo.status'), ['Succeeded', 'Stopped']));

    }

    private function parseLocation($location)
    {
        $return = [];
        foreach (explode(',', $location) as $part) {
            list($key, $value) = explode('=', $part);
            $return[$key] = $value;
        }

       return $return;
    }

}

$application = new \Symfony\Component\Console\Application();
$application->add(new DeployCommand());
$application->run();