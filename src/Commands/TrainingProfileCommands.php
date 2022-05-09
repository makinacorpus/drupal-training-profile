<?php

declare(strict_types=1);

namespace Drupal\training_profile\Commands;

use Drupal\training_profile\Service\InstallHelper;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for managing training contents.
 */
class TrainingProfileCommands extends DrushCommands {

  /**
   * InstallHelper service.
   *
   * @var \Drupal\training_profile\Service\InstallHelper
   */
  protected $installHelper;

  /**
   * {@inheritdoc}
   */
  public function __construct(InstallHelper $installHelper) {
    $this->installHelper = $installHelper;
  }

  /**
   * Create the initial content.
   *
   * @command training:create_content
   * @aliases training:cc
   */
  public function createContents() : void {
    $this->io()->text("Start import...");
    $this->installHelper->importContents();
    $this->io()->text("Import is finished.");
  }

  /**
   * Delete the initial content.
   *
   * @command training:delete_content
   * @aliases training:dc
   */
  public function deleteContents() : void {
    $this->io()->text("Start removing...");
    $this->installHelper->deleteContents();
    $this->io()->text("Data was successfully removed.");
  }

}
