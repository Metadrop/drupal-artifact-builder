<?php

namespace DrupalArtifactBuilder;

/**
 * Allow a command to has its own configuration.
 *
 * Used to allow commands configuration to be inherit from
 * commands calling them, saving time into reading the configuration.
 */
interface ConfigurableInterface
{

  /**
   * Sets the configuration.
   *
   * @param ConfigInterface $configuration
   *   Configuration.
   */
  public function setConfiguration(ConfigInterface $configuration) : void;

  /**
   * Gets the configuration.
   *
   * @return ConfigInterface
   *   Configuration.
   */
  public function getConfiguration() : ConfigInterface;

}
