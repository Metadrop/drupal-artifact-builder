<?php

namespace DrupalArtifactBuilder;

use PHP_CodeSniffer\Tests\Core\File\testFECNClassThatImplementsAndExtends;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Creates and artifact and pushes the changes to a git repository
 */
class DrupalArtifactBuilderBuild extends BaseCommand {

  protected static $defaultName = 'build';

  protected function configure()
  {
    parent::configure();
    $this->setDescription('Creates an artifact and push the changes to git.');
    $this->addOption('repository', 'repo', InputOption::VALUE_REQUIRED,'Git repository URL / SSH');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->log('Generating artifact');
    $this->getApplication()->find('create')
      ->run($input, $output);

    $this->log('Adding changes to git');
    $this->getApplication()->find('git')
      ->run($input, $output);
    return 0;
  }

}
