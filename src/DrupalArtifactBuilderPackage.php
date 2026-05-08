<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Packages the artifact as a tar.gz archive.
 */
class DrupalArtifactBuilderPackage extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    parent::configure();
    $this->setDescription('Packages the artifact as artifact.tar.gz.');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->assertArtifactExists();
    $this->packageArtifact();
    return Command::SUCCESS;
  }

  /**
   * Packages the artifact folder as a tar.gz file in the project root.
   */
  protected function packageArtifact() {
    $outputFile = $this->rootFolder . '/artifact.tar.gz';
    $this->log(sprintf('Packaging artifact to %s', $outputFile));

    $this->runCommand(sprintf(
      'tar -czf %s -C %s %s',
      escapeshellarg($outputFile),
      escapeshellarg($this->rootFolder),
      escapeshellarg($this->getArtifactFolder())
    ));

    $this->log(sprintf('Artifact packaged successfully: %s', $outputFile));
  }

  /**
   * Assert that the artifact exists before packaging.
   *
   * @throws \Exception
   */
  protected function assertArtifactExists() {
    if (!file_exists($this->getArtifactFolder()) || count(glob(sprintf('%s/%s', $this->getArtifactFolder(), '*'))) == 0) {
      throw new \Exception('Artifact does not exist. Run the create command first.');
    }
  }

}
