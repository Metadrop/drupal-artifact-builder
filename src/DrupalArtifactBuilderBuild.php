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

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->setDescription('Creates an artifact and push the changes to git.');
    $this->addOption('repository', 'repo', InputOption::VALUE_REQUIRED,'Git repository URL / SSH');
    $this->addOption('commits-number', 'cn', InputOption::VALUE_REQUIRED,'Number of commits to keep from artifact', 5);
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


    $this->log(sprintf('Artifact generation finished successfully in the %s folder', self::ARTIFACT_FOLDER));
    $this->log("Take into account that the operation removed development packages so you may want to run 'composer install'");
    $this->log("Please, complete the process with:\n  - Adding a tag (if needed)\n  - Merging with master (if this is a prod release)\n  - git push\n");

    return 0;
  }

}
