<?php

namespace DrupalArtifactBuilder\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Configuration extracted from .drupal-artifact.yml file.
 */
class Config implements ConfigInterface {

  /**
   * Branch where the artifact is created from.
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
   * @param array|null $branches_map
   *   Map between source and target branches.
   */
  public function __construct(
    protected ?string $repository = NULL,
    protected array $include = [],
    protected ?string $author = NULL,
    protected array $branches_map = [],
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
    return $this->author ?? self::DEFAULT_COMMIT_AUTHOR;
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
    return $this->branches_map[$this->branch] ?? $this->branch;
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

    foreach ($string_fields as $string_field) {
      if (isset($configuration[$string_field]) && !is_string($configuration[$string_field])) {
        throw new \InvalidArgumentException(sprintf('"%s" configuration key must be a string, %s given', $string_field, gettype($configuration['repository'])));
      }
    }

    $array_fields = [
      'include',
      'branches_map',
    ];

    foreach ($array_fields as $array_field) {
      if (isset($configuration[$array_field]) && !is_array($configuration[$array_field])) {
        throw new \InvalidArgumentException(sprintf('"%s" config key must be an array, %s given!', $array_field, gettype($configuration[$array_field])));
      }
    }


    return new self($configuration['repository'] ?? NULL, $configuration['include'] ?? [], $configuration['author'] ?? NULL, $configuration['branches_map'] ?? []);
  }

}
