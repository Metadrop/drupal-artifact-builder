<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates an artifact from a site already that is already setup.
 */
class DrupalArtifactBuilderCreate extends BaseCommand {

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
  protected function execute(InputInterface $input, OutputInterface $output) : int {
    $this->generateArtifact();
    return 0;
  }

  /**
   * Generates the artifact.
   *
   * @throws \Exception
   */
  protected function generateArtifact() {
    // Create the folder with the artifact.
    $this->createArtifactFolder();

    $this->log('Cleaning previous artifact');
    $this->log('##########################');

    // Cleanup artifact content to start from scratch.
    try {
      $this->runCommand(sprintf('rm -rf %s/*', static::ARTIFACT_FOLDER));
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Removing of dependencies failed');
    }

    // Copy all needed files.
    $this->log('Starting source copy to artifact repository folder');
    $this->log('##################################################');
    foreach ($this->getRequiredFiles() as $folder) {
      $this->copy($folder);
    }

    foreach ($this->getSymlinks() as $file) {
      if (file_exists($file)) {
        $this->copy($file);
      }
    }

    foreach ($this->getConfiguration()->getInclude() as $path) {
      $this->copy($path);
    }

    $this->log('Artifact generated successfully');
  }

  /**
   * Create the folder that will contains the artifact.
   */
  protected function createArtifactFolder() {
    $this->log('Creating artifact folder');
    $this->runCommand(sprintf('mkdir -p %s', static::ARTIFACT_FOLDER));
  }

  /**
   * Copy file or folder from codebase to artifact.
   *
   * @param string $path
   *   File or folder path from codebase.
   */
  protected function copy(string $path) {
    $this->log(sprintf('Copying %s...', $path));

    $this->runCommand(sprintf('cp -a "%s" "%s/%s"', $path, self::ARTIFACT_FOLDER, $path));
    $this->log('Copy successful');
  }

}
