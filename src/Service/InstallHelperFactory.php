<?php

declare(strict_types=1);

namespace Drupal\training_profile\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\State\StateInterface;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * InstallHelperFactory.
 *
 * Factory of the InstallHelper service.
 */
class InstallHelperFactory {

  /**
   * Create the container for the InstallHelper service.
   */
  public static function create(
    AliasManagerInterface $aliasManager,
    EntityTypeManagerInterface $entityTypeManager,
    StateInterface $state,
    FileSystemInterface $fileSystem,
    ModuleHandlerInterface $moduleHandler,
    LanguageManager $languageManager,
    ?LoggerInterface $logger = NULL
  ): InstallHelper {
    // Manage langages.
    $enabledLanguages = \array_keys(
      $languageManager->getLanguages()
    );
    $defaultLanguage = $languageManager
      ->getDefaultLanguage()
      ->getId();
    // Get module path.
    $modulePath = $moduleHandler->getModule('training_profile')->getPath();

    return new InstallHelper(
      $aliasManager,
      $entityTypeManager,
      $fileSystem,
      $state,
      $enabledLanguages,
      $defaultLanguage,
      $modulePath,
      $logger ?? new NullLogger()
    );
  }

}
