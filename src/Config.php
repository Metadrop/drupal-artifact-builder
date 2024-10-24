<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Yaml\Yaml;

/**
 * Configuration extracted from .drupal-artifact.yml file.
 */
class Config implements ConfigInterface {

  /**
   * Branch where the artifact will be pushed.
   *
   * @var string
   */
  protected string $branch;

  /**
   * Constructs teh configuration.
   *
   * @param string|null $repository
   *   Repository used to push the artifacts.
   * @param array $include
   *   Extra files or folders included into the artifact.
   * @param string|null $author
   *   Artifact commit author.
   */
  public function __construct(
    protected ?string $repository = NULL,
    protected array $include = [],
    protected ?string $author = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function setRepository(string $repository) : void {
    $this->repository = $repository;
  }

  /**
   * {@inheritdoc}
   */
  public function setInclude(array $include) : void {
    $this->include = $include;
  }

  /**
   * {@inheritdoc}
   */
  public function getRepository() : string {
    return $this->repository;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthor(string $author) : void {
    $this->author = $author;
  }

  public function getAuthor() : string {
    return $this->author;
  }

  /**
   * {@inheritdoc}
   */
  public function setBranch(string $branch) : void {
    $this->branch = $branch;
  }

  /**
   * Branch where the artifact will be created.
   *
   * Its value will be set with the current branch.
   *
   * @var string
   */
  public function getBranch() : string {
    return $this->branch;
  }

  /**
   * Extra file/folders that will be added to the artifact.
   *
   * @return array|string[]
   *   Relative path to the file or folder.
   */
  public function getInclude() : array {
    return $this->include;
  }

  /**
   * Creates a configuration isntance given a YAML configuration file.
   *
   * @param string $config_file
   *   Configuration file.
   *
   * @return self
   *   Configuration ready to use.
   */
  public static function createFromConfigurationFile(string $config_file) {
    $configuration = Yaml::parseFile($config_file);

    $string_fields = [
      'repository',
      'author',
    ];

    if (isset($configuration['repository']) && !is_string($configuration['repository'])) {
      throw new \InvalidArgumentException(sprintf('"repository" configuration key must be a string, %s given', gettype($configuration['repository'])));
    }

    if (isset($configuration['include']) && !is_array($configuration['include'])) {
      throw new \InvalidArgumentException('"include" config key must be a string, %s given!');
    }

    return new self($configuration['repository'] ?? NULL, $configuration['include'] ?? []);
  }

}
