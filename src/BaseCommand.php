<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use DrupalArtifactBuilder\Config\ConfigurableInterface;
use DrupalArtifactBuilder\Config\ConfigInterface;
use DrupalArtifactBuilder\Config\Config;

/**
 * Generates an artifact from a site already that is already setup.
 */
class BaseCommand extends Command implements ConfigurableInterface {

  protected static $defaultName = 'build';

  const ARTIFACT_REPOSITORY_FOLDER = 'drupal-artifact-builder-repository';

  /**
   * Folder with the codebase.
   *
   * @var string
   */
  protected string $rootFolder;

  /**
   * Configuration.
   *
   * @var \DrupalArtifactBuilder\Config\ConfigInterface
   */
  protected ConfigInterface $config;

  /**
   * Used to show messages during the artifact building.
   *
   * @var \Symfony\Component\Console\Output\OutputInterface
   */
  protected OutputInterface $output;

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    parent::configure();
    $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'The path to the configuration file.', '.drupal-artifact-builder.yml');
    $this->addOption('include', 'i', InputOption::VALUE_OPTIONAL, 'Separated by commas list of files or folders that must be additionally included into the artifact.');
    $this->addOption('repository', 'repo', InputOption::VALUE_OPTIONAL, 'Git repository URL / SSH');
    $this->addOption('branch', 'b', InputOption::VALUE_REQUIRED, 'Branch to checkout for the artifact.');
    $this->addOption('artifact-folder', NULL, InputOption::VALUE_REQUIRED, 'Destination folder for the artifact.', ConfigInterface::DEFAULT_ARTIFACT_FOLDER);
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(ConfigInterface $configuration) : void {
    $this->config = $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() : ConfigInterface {
    return $this->config;
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output): void {
    $this->output = $output;
    $this->rootFolder = getcwd();
    if (!isset($this->config)) {
      $this->setupConfig($input->getOption('config'));
    }

    if ($input->hasOption('include') && !empty($input->getOption('include'))) {
      $this->getConfiguration()->setInclude(explode(', ', $input->getOption('include')));
    }

    if ($input->hasOption('artifact-folder') && $input->getOption('artifact-folder') !== ConfigInterface::DEFAULT_ARTIFACT_FOLDER) {
      $this->getConfiguration()->setArtifactFolder($input->getOption('artifact-folder'));
    }

    // Assert the site is working okay before starting to create the artifact.
    $this->assertRootLocation();
  }

  /**
   * Returns the artifact destination folder path relative to root.
   *
   * @return string
   */
  protected function getArtifactFolder() : string {
    return $this->getConfiguration()->getArtifactFolder();
  }

  /**
   * Runs a shell command from the root folder.
   *
   * @param string $command
   *
   * @return \Symfony\Component\Process\Process
   *   It can be used to obtain the command output if needed.
   *
   * @throws \Symfony\Component\Process\Exception\ProcessFailedException
   *   When the command fails.
   */
  protected function runCommand(string $command) : Process {
    $this->log(sprintf('Running shell command: «%s»', $command));

    $process = Process::fromShellCommandline($command);
    $process->setTimeout(600);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }
    return $process;
  }

  /**
   * Runs a shell command in a specific directory.
   *
   * @param string $command
   * @param string $folder
   *   Absolute path of the working directory.
   *
   * @return Process
   *
   * @throws ProcessFailedException
   */
  protected function runCommandInFolder(string $command, string $folder) : Process {
    $this->log(sprintf('Running in %s: «%s»', $folder, $command));

    $process = Process::fromShellCommandline($command, $folder);
    $process->setTimeout(600);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }
    return $process;
  }

  /**
   * Setup the configuration.
   *
   * @param string $configuration_filepath
   *   Path where the configuration file is located.
   */
  protected function setupConfig(string $configuration_filepath) {
    $this->log(sprintf('Selected configuration file: %s', $configuration_filepath));

    if (file_exists($configuration_filepath)) {
      $this->log(sprintf('Configuration file found at %s', $configuration_filepath));
      $config = Config::createFromConfigurationFile($configuration_filepath);
    }
    else {
      $this->log(sprintf('No configuration file found at %s. Using command line parameters.', $configuration_filepath));
      $config = new Config();
    }
    $this->setConfiguration($config);
  }

  /**
   * Logs that will show the user the artifact building progress.
   *
   * @param string $message
   */
  protected function log(string $message) {
    $this->output->writeln(sprintf('[-->] %s', $message));
  }

  /**
   * Ensure script is launched inside a codebase and not in an arbitrary folder.
   *
   * @throws \RuntimeException
   *   When the script is not launched inside a codebase.
   */
  protected function assertRootLocation() {
    if (!file_exists('docroot') && !file_exists('web')) {
      throw new \RuntimeException('It seems this command has not been launched the repository root folder. Please run it from root folder.');
    }

    foreach (['config', 'composer.json'] as $path) {
      if (!file_exists($path)) {
        throw new \RuntimeException('It seems this command has not been launched the repository root folder. Please run it from root folder.');
      }
    }
  }

  /**
   * Assert the repository is set.
   *
   * @throws \Exception
   */
  protected function assertRepository() {
    if (empty($this->getConfiguration()->getRepository())) {
      throw new \Exception('Repository must be defined to continue!');
    }
  }

  /**
   * Calculate where is the docroot folder.
   *
   * @return string
   */
  protected function calculateDocrootFolder() {
    foreach (['docroot', 'web'] as $docrootFolder) {
      if (file_exists($docrootFolder) && !is_link($docrootFolder)) {
        return $docrootFolder;
      }
    }
    throw new \Exception('Docroot folder not found');
  }

  /**
   * Get the name of the repository branch.
   *
   * @param InputInterface $input
   *
   * @return string
   */
  protected function getBranch(InputInterface $input) : string {
    $branch_from_input = $input->getOption('branch');
    if (!empty($branch_from_input)) {
      return $branch_from_input;
    }

    $current_branch = trim($this->runCommand('git branch --show-current')->getOutput());
    if (!empty($current_branch)) {
      return $current_branch;
    }

    throw new \RuntimeException("Could not detect a branch. Either you didn't set --branch option or you are in detached mode");
  }

  /**
   * Get the file or folders that are required to add to the artifact.
   *
   * @return string[]
   *   Relative path.
   */
  protected function getRequiredFiles() : array {
    return [
      'config',
      'drush',
      'vendor',
      'scripts',
      'composer.json',
      'composer.lock',
    ];
  }

  /**
   * Get the symlinks that may be commited to the artifact.
   *
   * @return string[]
   *   Relative path of the symlinks.
   */
  protected function getSymlinks() : array {
    return ['docroot', 'web', 'public_html'];
  }

  /**
   * Check if git command exists.
   *
   * @return bool
   */
  protected function gitCommandExist() {
    return $this->runCommand('which git')->isSuccessful();
  }

  /**
   * Check if we are in a git repository.
   *
   * @return bool
   */
  protected function isGitRepository() {
    $command_output = $this->runCommand('git rev-parse --is-inside-work-tree || true')->getOutput();
    return trim($command_output) === 'true';
  }

}
