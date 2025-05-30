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

  const ARTIFACT_FOLDER = 'deploy-artifact';

  const ARTIFACT_REPOSITORY_FOLDER = 'deploy-artifact-repository';

  const FILES_TO_CLEAN = [
    'CHANGELOG.txt',
    'COPYRIGHT.txt',
    'INSTALL.txt',
    'INSTALL.mysql.txt',
    'INSTALL.pgsql.txt',
    'INSTALL.sqlite.txt',
    'LICENSE.txt',
    'README.txt',
    'CHANGELOG.txt',
    'UPDATE.txt',
    'USAGE.txt',
    'PATCHES.txt',
  ];


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
  protected function configure() {
    parent::configure();
    $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'The path to the configuration file.', '.drupal-artifact-builder.yml');
    $this->addOption('include', 'i', InputOption::VALUE_OPTIONAL, 'Separated by commas list of files or folders that must be additionally included into the artifact.');
    $this->addOption('repository', 'repo', InputOption::VALUE_OPTIONAL, 'Git repository URL / SSH');
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
  protected function initialize(InputInterface $input, OutputInterface $output) {
    // Variables initialization.
    $this->output = $output;
    $this->rootFolder = getcwd();
    if (!isset($this->config)) {
      $this->setupConfig($input->getOption('config'));
    }

    if ($input->hasOption('include') && !empty($input->getOption('include'))) {
      $this->getConfiguration()->setInclude(explode(', ', $input->getOption('include')));
    }

    // Assert the site is working okay before starting to create the artifact.
    $this->assertRootLocation();
    $this->assertArtifactContentIsClean();
  }

  /**
   * Runs a shell command.
   *
   * @param string $command
   *   Command.
   *
   * @return \Symfony\Component\Process\Process
   *   It can be used to obtain the command output if needed.
   *
   * @throws \Symfony\Component\Process\Exception\ProcessFailedException
   *   When the command fails.
   */
  protected function runCommand(string $command) {
    $this->log(sprintf('Running shell command: «%s»', $command));

    $process = Process::fromShellCommandline($command);
    $process->setTimeout(300);
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
   *   Message.
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
   * Assert the repository does not contains changes / untracked files.
   *
   * This is only checked in the artifact managed files. Files that are
   * not added to the artifacct are ignored.
   *
   * @throws \Exception
   */
  protected function assertArtifactContentIsClean() {
    $artifact_content = array_unique(array_merge(
      [$this->calculateDocrootFolder()],
      $this->getRequiredFiles(),
      $this->getSymlinks(),
      $this->getConfiguration()->getInclude(),
    ));

    if ($this->gitCommandExist() && $this->isGitRepository()) {
      $files_changed = trim($this->runCommand(sprintf("git status -s %s", implode(' ', $artifact_content)))->getOutput());
      if (strlen($files_changed > 0)) {
        $list = implode("\n", array_map(function ($item) {
          $parts = explode(' ', $item);
          return $parts[1];
        }, explode("\n", $files_changed)));
        throw new \Exception("There are changes in the repository (changed and/or untracked files), please run this artifact generation script with folder tree clean. Files changed:\n$list");
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
   *   Docroot folder location.
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
   * Get the file or folders that are required to add to the artifact.
   *
   * @return string[]
   *   Relative path.
   */
  protected function getRequiredFiles() {
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
  protected function getSymlinks() {
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
    return $command_output === 'true';
  }

}
