<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates and artifact and pushes the changes to a git repository
 */
class DrupalArtifactBuilderBuild extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    parent::configure();
    $this->setDescription('Creates an artifact and push the changes to git (or packages as tar.gz if no repository is configured).');
    $this->addOption('author', 'a', InputOption::VALUE_REQUIRED, 'Git commit author');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->log('Generating artifact');
    $this->runApplicationCommand('create', $input, $output);

    if (!empty($this->getConfiguration()->getRepository())) {
      $this->log('Adding changes to git');
      $this->runApplicationCommand('git', $input, $output);
    }
    else {
      $this->log('No repository configured - packaging artifact as tar.gz');
      $this->runApplicationCommand('package', $input, $output);
    }

    $this->log(sprintf('Artifact generation finished successfully in the %s folder', $this->getArtifactFolder()));
    $this->log("Take into account that the operation removed development packages so you may want to run 'composer install'");

    return Command::SUCCESS;
  }

  /**
   * Run an application command.
   *
   * If the command has configuration, it will be inherited.
   *
   * @param string $command_name
   * @param InputInterface $input
   * @param OutputInterface $output
   */
  protected function runApplicationCommand(string $command_name, InputInterface $input, OutputInterface $output) : void {
    $command = $this->getApplication()->find($command_name);
    if ($command instanceof ConfigurableInterface) {
      $command->setConfiguration($this->getConfiguration());
    }
    $command->run($input, $output);
  }

}
