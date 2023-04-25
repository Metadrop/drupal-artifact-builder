<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates a full maintenance report.
 */
class DrupalArtifactBuilder extends BaseCommand {

  protected static $defaultName = 'build';

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->setDescription('Helper to generate drupal artifacts.');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $output->writeln('@TODO!');
    return 0;
  }


}
