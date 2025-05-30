<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates and artifact and pushes the changes to a git repository.
 */
class DrupalArtifactBuilderBuild extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->setDescription('Creates an artifact and push the changes to git.');
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);
    $this->assertRepository();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) : int {
    $this->log('Generating artifact');
    $this->runApplicationCommand('create', $input, $output);

    $this->log('Adding changes to git');
    $this->runApplicationCommand('git', $input, $output);

    $this->log(sprintf('Artifact generation finished successfully in the %s folder', self::ARTIFACT_FOLDER));
    $this->log("Take into account that the operation removed development packages so you may want to run 'composer install'");
    $this->log("Please, complete the process with:\n  - Adding a tag (if needed)\n  - Merging with master (if this is a prod release)\n  - git push\n");

    return 0;
  }

  /**
   * Run an application command.
   *
   * Used to create and push to git the artifact using the existing coomands
   * from the own repository.
   *
   * If the command mentioned has configuration, it will be inherit.
   *
   * @param string $command_name
   *   Command name.
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   Input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Output.
   */
  protected function runApplicationCommand(string $command_name, InputInterface $input, OutputInterface $output) : void {
    $command = $this->getApplication()->find($command_name);
    if ($command instanceof ConfigurableInterface) {
      $command->setConfiguration($this->getConfiguration());
    }
    $command->run($input, $output);
  }

}
