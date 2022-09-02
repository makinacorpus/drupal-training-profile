<?php

declare(strict_types=1);

namespace Drupal\training_profile\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Defines a helper class for importing default content.
 *
 * @internal
 *  This code is only for use by the training profile, for creating content.
 */
class InstallHelper {

  /**
   * Data: source directory.
   */
  const DATA_DIRECTORY = 'default_content/data';

  /**
   * Entities: source directory.
   */
  const ENTITIES_DIRECTORY = 'default_content/entities';

  /**
   * Medias: source directory.
   */
  const MEDIA_SRC_DIRECTORY = 'default_content/data/medias';

  /**
   * Medias: upload directory.
   */
  const MEDIA_UPLOAD_DIRECTORY = 'public://images/training_content';

  /**
   * Entities that are not concerned by the translation.
   */
  const NON_TRANSLATABLE_ENTITIES = [
    'file',
    'media',
    'user',
  ];

  /**
   * Name of the state file.
   */
  const STATE_FILE = 'training_content_uuids';

  /**
   * AliasManagerInterface.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * EntityTypeManagerInterface.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * StateInterface.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * LoggerInterface.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * FileSystemInterface.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Array of enabled languages.
   *
   * @var array
   */
  protected $enabledLanguages;

  /**
   * Default langcode site.
   *
   * @var string
   */
  protected $defaultLanguage;

  /**
   * Relative path of the module.
   *
   * @var string
   */
  protected $modulePath;

  /**
   * Drupal ids map.
   *
   * Allow you to retrieves the Drupal id of an entity.
   * [entityType => [bundle => [media_csvId => media_drupalId]]].
   *
   * @var array
   */
  protected $drupalIdMap;

  /**
   * Constructs a new InstallHelper self.
   *
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param array $enabledLanguages
   *   An array of enabled languages.
   * @param string $defaultLanguage
   *   The default language langcode.
   * @param string $modulePath
   *   The relative path of the module.
   * @param \Psr\Log\LoggerInterface $logger
   *   The training_profile logger instance.
   */
  public function __construct(
    AliasManagerInterface $aliasManager,
    EntityTypeManagerInterface $entityTypeManager,
    FileSystemInterface $fileSystem,
    StateInterface $state,
    array $enabledLanguages,
    string $defaultLanguage,
    string $modulePath,
    LoggerInterface $logger
  ) {
    $this->aliasManager = $aliasManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->fileSystem = $fileSystem;
    $this->state = $state;
    $this->enabledLanguages = $enabledLanguages;
    $this->defaultLanguage = $defaultLanguage;
    $this->modulePath = $modulePath;
    $this->logger = $logger;
    $this->drupalIdMap = [];
  }

  /**
   * Imports training contents.
   */
  public function importContents(): void {
    $this
      ->prepareDirectoryForMedia()
      ->importContentsFromFile('file', 'image')
      ->importContentsFromFile('media', 'image')
      ->importContentsFromFile('taxonomy_term', 'type')
      ->importContentsFromFile('taxonomy_term', 'generation')
      ->importContentsFromFile('taxonomy_term', 'tags')
      ->importContentsFromFile('user', 'user')
      ->importContentsFromFile('node', 'page')
      ->importContentsFromFile('node', 'article')
      ->importContentsFromFile('node', 'pokemon')
      ->importContentsFromFile('node', 'pokemon', 'update')
      ->importContentsFromFile('menu_link_content', 'main')
      ->importContentsFromFile('menu_link_content', 'footer')
      ->importContentsFromFile('menu_link_content', 'mentions')
      ->importContentsFromFile('menu_link_content', 'generations')
      ->importContentsFromFile('block_content', 'social_networks')
      ->importContentsFromFile('block_content', 'generation');
  }

  /**
   * Deletes any content imported by this module.
   *
   * @return $this
   */
  public function deleteContents(): void {
    $uuids = $this->state->get('training_content_uuids', []);
    $byEntityType = \array_reduce(\array_keys($uuids), function ($carry, $uuid) use ($uuids) {
      $entityTypeId = $uuids[$uuid];
      $carry[$entityTypeId][] = $uuid;

      return $carry;
    }, []);
    foreach ($byEntityType as $entityTypeId => $entity_uuids) {
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      $entities = $storage->loadByProperties(['uuid' => $entity_uuids]);
      $storage->delete($entities);
    }

    $this->state->delete(self::STATE_FILE);
  }

  /**
   * Prepare the upload directory.
   *
   * @return $this
   */
  protected function prepareDirectoryForMedia(): ?self {
    $realpath = $this->fileSystem->realpath(self::MEDIA_UPLOAD_DIRECTORY);
    if (!\file_exists($realpath)) {
      $this->fileSystem->prepareDirectory(
        $realpath,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
      );
    }

    return $this;
  }

  /**
   * Imports contents from csv files.
   *
   * @param string $entityType
   *   Entity type to be imported.
   * @param string $bundle
   *   Bundle machine name to be imported.
   * @param string $op
   *   Operation performed on the entity: create|update.
   *
   * @return $this
   */
  protected function importContentsFromFile(
    string $entityType,
    string $bundle,
    string $op = 'create'
  ): ?self {
    $pathToCsv = ('update' === $op)
      ? '%s/update/%s.csv'
      : '%s/%s.csv';

    $filename = \sprintf(
      $pathToCsv,
      $entityType,
      $bundle
    );
    // Read all multilingual content from files.
    list($allContent, $translatedLanguages) = $this->readMultilingualContent($entityType, $filename);

    // Log and return.
    if (\is_null($allContent)) {
      $this->log(
        'No content found.',
        'alert',
        __FUNCTION__,
        $entityType,
        $bundle,
        ['All', $filename]
      );

      return $this;
    }

    // Start with default language content.
    // Default language is no longer needed in the list of languages.
    if ('und' === reset($translatedLanguages)) {
      $translatedLanguages = [];
      $defaultLanguage = 'und';
    }
    else {
      $key = \array_search($this->defaultLanguage, $translatedLanguages);
      unset($translatedLanguages[$key]);
      $defaultLanguage = $this->defaultLanguage;
    }
    foreach ($allContent[$defaultLanguage] as $line) {
      // Get structured data as an entity.
      $structuredContent = $this->processContent(
        $entityType,
        $bundle,
        $line,
        $defaultLanguage,
        $op
      );

      // Log and return.
      if (\is_null($structuredContent)) {
        $this->log(
          'No content found.',
          'alert',
          __FUNCTION__,
          $entityType,
          $bundle,
          [$defaultLanguage, $filename, $op]
        );

        return $this;
      }

      switch ($op) {
        case 'create':
          // Create the default language entity.
          $entity = $this->entityTypeManager
            ->getStorage($entityType)
            ->create($structuredContent);
          $entity->save();

          // Records the uuid of the created entity.
          // Save the id, so we can reference it in another entities.
          $this->storeUuid([$entity->uuid() => $entityType]);
          $entityType !== 'menu_link_content'
            ? $this->mapDrupalId(
                $entityType,
                $bundle,
                $line['id'],
                $entity->id()
              )
            : $this->mapDrupalId(
                $entityType,
                $bundle,
                $line['id'],
                $entity->uuid()
              );
          break;

        // Retrieve entity and save fields.
        case 'update':
          $drupalId = $this->getDrupalId(
            $entityType,
            $bundle,
            $line['id'],
          );
          // Log and return.
          if (\is_null($drupalId)) {
            $this->log(
              'No entity found.',
              'alert',
              __FUNCTION__,
              $entityType,
              $bundle,
              [$defaultLanguage, $line['id'], $op]
            );

            return $this;
          }

          $entity = $this->entityTypeManager
            ->getStorage($entityType)
            ->load($drupalId);
          foreach ($structuredContent as $field => $value) {
            $entity->set($field, $value);
          }
          $entity->save();
          break;

        default:
          return $this;
      }

      if (!empty($translatedLanguages)) {
        // Go through all the languages that have translations.
        foreach ($translatedLanguages as $translatedLanguage) {
          // Find translated content id that corresponds to original content.
          $translationId = \array_search($line['id'], \array_column($allContent[$translatedLanguage], 'id'));

          // Check if translation was found.
          if ($translationId !== FALSE) {

            // Process that translation.
            $translatedEntityLine = $allContent[$translatedLanguage][$translationId];
            $translationStructuredContent = $this->processContent(
              $entityType,
              $bundle,
              $translatedEntityLine,
              $translatedLanguage,
              $op
            );

            // Log and return.
            if (\is_null($translationStructuredContent)) {
              $this->log(
                'Translation created from an empty array.',
                'alert',
                __FUNCTION__,
                $entityType,
                $bundle,
                [$translatedLanguage, $filename, $op]
              );

              return $this;
            }

            switch ($op) {
              // Add translation to the entity.
              case 'create':
                $entity->addTranslation(
                  $translatedLanguage,
                  $translationStructuredContent
                );
                $entity->save();
                break;

              // Load the translation and save the field value.
              case 'update':
                $drupalId = $this->getDrupalId(
                  $entityType,
                  $bundle,
                  $line['id'],
                );
                // Log and return.
                if (\is_null($drupalId)) {
                  $this->log(
                    'No entity found.',
                    'alert',
                    __FUNCTION__,
                    $entityType,
                    $bundle,
                    [$translatedLanguage, $line['id'], $op]
                  );

                  return $this;
                }

                $entity = $this->entityTypeManager
                  ->getStorage($entityType)
                  ->load($drupalId);
                $translation = $entity->getTranslation($translatedLanguage);
                foreach ($translationStructuredContent as $field => $value) {
                  $translation->set($field, $value);
                }
                $translation->save();
                break;

              default:
                return $this;
            }
          }
        }
      }
    }

    return $this;
  }

  /**
   * Transforms the rows of the csv file into a table, indexed by language.
   *
   * @param string $entityType
   *   Entity type to be imported.
   * @param string $filename
   *   Name of the file to be imported.
   *
   * @return array
   *   An array of two items:
   *     1. All multilingual content that was read from the files.
   *     2. List of language codes that need to be imported.
   */
  protected function readMultilingualContent(
    string $entityType,
    string $filename
  ): ?array {
    $defaultContentPath = \sprintf(
      '%s/%s',
      $this->modulePath,
      self::ENTITIES_DIRECTORY
    );

    // Get all enabled languages.
    // Load all the content from any CSV files that exist.
    $translatedLanguages = \in_array(
      $entityType,
      self::NON_TRANSLATABLE_ENTITIES
    )
      ? ['und']
      : $this->enabledLanguages;
    foreach ($translatedLanguages as $language) {
      $path = \sprintf(
        '%s/%s/%s',
        $defaultContentPath,
        $language,
        $filename
      );
      if (
          \file_exists($path) &&
          ($handle = \fopen($path, 'r')) !== FALSE
      ) {
        $header = \fgetcsv($handle);
        $row = 0;
        while (($content = \fgetcsv($handle)) !== FALSE) {
          $keyedContent[$language][$row] = \array_combine($header, $content);
          $row++;
        }
        \fclose($handle);
      }
      else {
        // Remove that language from list of languages to be translated.
        $key = \array_search($language, $translatedLanguages);
        unset($translatedLanguages[$key]);

        $this->log(
          'Unable to find the file.',
          'warning',
          __FUNCTION__,
          NULL,
          NULL,
          [$defaultContentPath, $path]
        );
      }
    }

    return [$keyedContent, $translatedLanguages];
  }

  /**
   * Formats the content of a row into an array corresponding to the entity.
   *
   * @param string $entityType
   *   The type of entity to be created.
   * @param string $bundle
   *   The type of bundle to be created.
   * @param array $content
   *   The data of the row.
   * @param string $langcode
   *   The language code.
   * @param string $op
   *   Operation performed on the entity: create|update.
   *
   * @return array
   *   The data of the entity, ready to be registered.
   */
  protected function processContent(
    string $entityType,
    string $bundle,
    array $content,
    string $langcode,
    string $op
  ): ?array {
    switch ($entityType) {
      case 'file':
        return $this->processFiles(
          $bundle,
          $content
        );

      case 'media':
        return $this->processMedias(
          $bundle,
          $content
        );

      case 'user':
        return $this->processUser(
          $entityType,
          $bundle,
          $content,
          $op
        );

      case 'paragraph':
        return $this->processParagraphs(
          $entityType,
          $bundle,
          $content,
          $langcode,
        );

      case 'taxonomy_term':
        return $this->processTerm(
          $entityType,
          $bundle,
          $content,
          $langcode,
          $op,
        );

      case 'node':
        return $this->processNode(
          $entityType,
          $bundle,
          $content,
          $langcode,
          $op
        );

      case 'menu_link_content':
        return $this->processMenu(
          $bundle,
          $content,
          $langcode,
          $op
        );

      case 'block_content':
        return $this->processBlock(
          $entityType,
          $bundle,
          $content,
          $langcode,
          $op
        );

      default:
        return NULL;
    }
  }

  /**
   * Process file into a Drupal file entity.
   *
   * @param string $bundle
   *   Bundle name of media entity.
   * @param array $data
   *   The data of the row.
   *
   * @return array
   *   Data structured as the Drupal target file entity.
   */
  protected function processFiles(
    string $bundle,
    array $data
  ): ?array {
    $filepath = \sprintf(
      '%s/%s/%s',
      $this->modulePath,
      self::MEDIA_SRC_DIRECTORY,
      \trim($data['filename'])
    );

    // Copy files.
    try {
      $location = \sprintf(
        '%s/%s',
        self::MEDIA_UPLOAD_DIRECTORY,
        \trim($data['filename'])
      );
      $uri = $this->fileSystem->copy(
        $filepath,
        $location,
        FileSystemInterface::EXISTS_REPLACE
      );
    }
    catch (FileException $e) {
      // Log and return.
      $this->log(
        'Unable to copy the file.',
        'alert',
        __FUNCTION__,
        'file',
        $bundle,
        [$filepath]
      );

      return NULL;
    }

    // Process data.
    switch ($bundle) {
      case 'image':
        return $this->processImage($uri, $data);

      case 'document':
        return $this->processFile($uri, $data);

      default:
        return NULL;
    }
  }

  /**
   * Process image into a Drupal file entity.
   *
   * @param string $uri
   *   The uri of the file.
   * @param array $data
   *   The data of the row.
   *
   * @return array
   *   Data structured as a image file entity type.
   */
  protected function processImage(string $uri, array $data): ?array {
    $values = [
      'uri' => $uri,
      'filename' => \trim($data['filename']),
      'status' => 1,
    ];

    return $values;
  }

  /**
   * Process document into a Drupal file entity.
   *
   * @param string $uri
   *   The uri of the file.
   * @param array $data
   *   The data of the row.
   *
   * @return array
   *   Data structured as a document file entity type.
   */
  protected function processFile(string $uri, array $data): ?array {
    $values = [
      'uri' => $uri,
      'description' => \trim($data['description']),
      'display' => 1,
    ];

    return $values;
  }

  /**
   * Creates media type entity.
   *
   * @param string $bundle
   *   The bundle to be created.
   * @param array $data
   *   The data of the row.
   *
   * @return array
   *   Data structured as a media entity.
   */
  protected function processMedias(
    string $bundle,
    array $data
  ): ?array {
    switch ($bundle) {
      case 'image':
        return $this->processMedia(
          $bundle,
          $data
        );

      case 'document':
        return $this->processDocument(
          $bundle,
          $data
        );

      default:
        return NULL;
    }
  }

  /**
   * Process image into an image media entity.
   *
   * @param string $bundle
   *   The type of bundle to be created.
   * @param array $data
   *   The data of the row.
   *
   * @return array
   *   Data structured as a media entity.
   */
  protected function processMedia(
    string $bundle,
    array $data
  ): ?array {
    $targetId = $this->getDrupalId('file', $bundle, \trim($data['target_id']));
    if (\is_null($targetId)) {
      // Log and return.
      $this->log(
        'Unable to find a target_id.',
        'alert',
        __FUNCTION__,
        'file',
        $bundle,
        [$this->defaultLanguage]
      );

      return NULL;
    }

    return [
      'name' => \trim($data['title']),
      'bundle' => $bundle,
      'langcode' => $this->defaultLanguage,
      'field_media_image' => [
        'target_id' => $targetId,
        'alt' => \trim($data['alt']),
      ],
    ];
  }

  /**
   * Process document into an document media entity.
   *
   * @param string $bundle
   *   The type of bundle to be created.
   * @param array $data
   *   The data of the row.
   *
   * @return array
   *   Data structured as a media entity.
   */
  protected function processDocument(
    string $bundle,
    array $data
  ): ?array {
    $targetId = $this->getDrupalId('file', $bundle, \trim($data['target_id']));
    if (\is_null($targetId)) {
      // Log and return.
      $this->log(
        'Unable to find a target_id.',
        'alert',
        __FUNCTION__,
        'file',
        $bundle,
        [$this->defaultLanguage]
      );

      return NULL;
    }

    return [
      'name' => \trim($data['title']),
      'bundle' => $bundle,
      'langcode' => $this->defaultLanguage,
      'field_media_file' => [
        'target_id' => $targetId,
        'display' => TRUE,
        'description' => \trim($data['description']),
      ],
    ];
  }

  /**
   * Process user and create account.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The type of bundle to be created.
   * @param array $data
   *   The data of the row.
   * @param string $op
   *   Operation performed on the entity: create|update.
   *
   * @return array
   *   Data structured as a user.
   */
  protected function processUser(
    string $entityType,
    string $bundle,
    array $data,
    string $op
  ): array {
    $values = [];
    if ('create' === $op) {
      $mail = \sprintf(
        '%s@mail.fr',
        \strtolower(\str_replace(' ', '', $data['username']))
      );
      $values = [
        'type' => $entityType,
        'name' => \trim($data['username']),
        'mail' => $mail,
        'langcode' => $this->defaultLanguage,
      ];

      if (isset($data['roles']) && !empty($data['roles'])) {
        $roles = \array_filter(\explode(',', \trim($data['roles'])));
        foreach ($roles as $role) {
          $values['roles'][] = ['target_id' => $role];
        }
      }
    }

    $this->getFieldsvalues(
      $values,
      $entityType,
      $bundle,
      $data,
      $this->defaultLanguage
    );

    return $values;
  }

  /**
   * Process paragraph.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The type of bundle to be created.
   * @param array $data
   *   The data of the row.
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Data structured as a paragraph.
   */
  protected function processParagraphs(
    string $entityType,
    string $bundle,
    array $data,
    string $langcode
  ): array {
    $values = [
      'type' => $bundle,
      'langcode' => $langcode,
    ];

    if (
      !empty(\trim($data['parent_type'])) &&
      !empty(\trim($data['parent_id'])) &&
      !empty(\trim($data['parent_field_name']))
    ) {
      $parentType = \explode(':', \trim($data['parent_type']));
      $targetEntity = $parentType[0];
      $targetBundle = $parentType[1];
      $parentId = $this->getDrupalId($targetEntity, $targetBundle, \trim($data['parent_id']));

      if (\is_null($parentId)) {
        // Log and return.
        $this->log(
          'Unable to find a parent for this paragraph.',
          'alert',
          __FUNCTION__,
          $target_entity,
          $target_bundle,
          [\trim($data['id'])]
        );
      }
      $values['parent_id'] = $parentId;
      $values['parent_type'] = $targetEntity;
      $values['parent_field_name'] = \trim($data['parent_field_name']);
    }

    $this->getFieldsvalues(
      $values,
      $entityType,
      $bundle,
      $data,
      $langcode
    );

    return $values;
  }

  /**
   * Process terms for a given vocabulary.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $vocabulary
   *   The concerned vocabulary.
   * @param array $data
   *   The data of the row.
   * @param string $langcode
   *   The language code.
   * @param string $op
   *   Operation performed on the entity: create|update.
   *
   * @return array
   *   Data structured as a term.
   */
  protected function processTerm(
    string $entityType,
    string $vocabulary,
    array $data,
    string $langcode,
    string $op
  ): ?array {
    if ('create' === $op) {
      $alias = \sprintf(
        '/%s/%s',
        Html::getClass($vocabulary),
        Html::getClass(\trim($data['term']))
      );
      $values = [
        'name' => \trim($data['term']),
        'description' => '',
        'vid' => $vocabulary,
        'path' => [
          'alias' => $alias,
        ],
        'langcode' => $langcode,
      ];
    }

    $this->getFieldsvalues(
      $values,
      $entityType,
      $vocabulary,
      $data,
      $langcode
    );

    return $values;
  }

  /**
   * Process node.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The type of bundle to be created.
   * @param array $data
   *   The data of the row.
   * @param string $langcode
   *   The language code.
   * @param string $op
   *   Operation performed on the entity: create|update.
   *
   * @return array
   *   Data structured as a node.
   */
  protected function processNode(
    string $entityType,
    string $bundle,
    array $data,
    string $langcode,
    string $op
  ): array {
    $values = [];
    if ('create' === $op) {
      $values = [
        'type' => $bundle,
        'title' => \trim($data['title']),
        'moderation_state' => 'published',
        'langcode' => $langcode,
      ];
    }

    $this->getFieldsvalues(
      $values,
      $entityType,
      $bundle,
      $data,
      $langcode
    );

    return $values;
  }

  /**
   * Process block data into block content structure.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The type of bundle to be created.
   * @param array $data
   *   The data of the row.
   * @param string $langcode
   *   The language code.
   * @param string $op
   *   Operation performed on the entity: create|update.
   *
   * @return array
   *   Data structured as a block.
   */
  protected function processBlock(
    string $entityType,
    string $bundle,
    array $data,
    string $langcode,
    string $op
  ): array {
    $values = [];
    if ('create' === $op) {
      $values = [
        'uuid' => \trim($data['uuid']),
        'info' => \trim($data['info']),
        'type' => $bundle,
        'langcode' => $langcode,
      ];
    }
    $this->getFieldsvalues(
      $values,
      $entityType,
      $bundle,
      $data,
      $langcode
    );

    return $values;
  }

  /**
   * Create the menu links from the data of a line in the reference file.
   *
   * @param string $menuName
   *   The menu name.
   * @param array $data
   *   The data of the row.
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Data structured as a menu_link_content entity.
   */
  protected function processMenu(
    string $menuName,
    array $data,
    string $langcode
  ): ?array {
    $values = [
      'title' => \trim($data['title']),
      'link' => ['uri' => \trim($data['link'])],
      'menu_name' => $menuName,
      'weight' => \trim($data['weight']),
      'expanded' => TRUE,
    ];

    if (!empty(\trim($data['parent']))) {
      $uuid = $this->getDrupalId('menu_link_content', $menuName, \trim($data['parent']));

      if (0 === $uuid) {
        // Log and return.
        $this->log(
          'Unable to find a parent for this menu link.',
          'alert',
          __FUNCTION__,
          'menu_link_content',
          $menuName,
          [\trim($data['parent'])]
        );

        return $values;
      }

      $values['parent'] = \sprintf('menu_link_content:%s', $uuid);
    }

    return $values;
  }

  /**
   * Get the content for fields.
   *
   * @param array $values
   *   The structured entity data.
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The type of bundle to be created.
   * @param array $data
   *   The data of the row.
   * @param string $langcode
   *   The language code.
   */
  protected function getFieldsvalues(
    array &$values,
    string $entityType,
    string $bundle,
    array $data,
    string $langcode
  ): void {
    $this->getMetadata($values, $data);
    $this->getBasicFields($values, $data);
    $this->getHtmlFields($values, $entityType, $bundle, $data, $langcode);
    $this->getLinkFields($values, $data);
    $this->getReferenceFields($values, $data);
    $this->getParagraphsFields($values, $data);
  }

  /**
   * Get all metadatas content.
   *
   * @param array $values
   *   The type of bundle to be created.
   * @param array $data
   *   The data of the row.
   */
  protected function getMetadata(
    array &$values,
    array $data
  ): void {
    // Set article author if exist.
    if (isset($data['author']) && !empty(\trim($data['author']))) {
      $values['uid'] = $this->getDrupalId('user', 'user', \trim($data['author']));
    }

    // Set node alias if exists.
    if (
      isset($data['slug']) &&
      !empty(\trim($data['slug'])
    )) {
      $values['path'] = [
        'alias' => \trim($data['slug']),
        'pathauto' => 0,
      ];
    }
  }

  /**
   * Get the value of all basic fields, whatever their cardinality.
   *
   * @param array $values
   *   The structured data of the entity.
   * @param array $data
   *   The data of the row.
   */
  protected function getBasicFields(array &$values, array $data): void {
    $fields = $this->filterFields('field', $data);
    if (!empty($fields)) {
      foreach ($fields as $fieldName => $inputs) {
        $inputs = \trim($inputs);
        $elements = \explode('|', $inputs);
        foreach ($elements as $input) {
          $values[$fieldName][] = ['value' => $input];
        }
      }
    }
  }

  /**
   * Get the value of all html type fields.
   *
   * @param array $values
   *   The structured data of the entity.
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The type of bundle to be created.
   * @param array $data
   *   The data of the row.
   * @param string $langcode
   *   Current language code.
   */
  protected function getHtmlFields(
    array &$values,
    string $entityType,
    string $bundle,
    array $data,
    string $langcode
  ): void {
    $contents = $this->filterFields('html', $data);
    if (empty($contents)) {
      return;
    }

    foreach ($contents as $content => $filename) {
      $elements = \explode('-', $content);
      $fieldName = \trim($elements[1]);
      $path = \sprintf(
        '%s/%s/%s/%s/%s/%s',
        $this->modulePath,
        self::DATA_DIRECTORY,
        $langcode,
        $entityType,
        $bundle,
        $filename
      );
      // Log and return.
      if (!\file_exists($path)) {
        $this->log(
          'Unable to find the csv file.',
          'alert',
          __FUNCTION__,
          $entityType,
          $bundle,
          [$filename]
        );

        return;
      }

      $value = \file_get_contents($path);
      // Log and return.
      if (!$value) {
        $this->log(
          'Unable to get content of the csv file.',
          'alert',
          __FUNCTION__,
          $entityType,
          $bundle,
          [$filename]
        );

        return;
      }

      if ($fieldName == 'description') {
        $values[$fieldName] = $value;
      }
      else {
        $values[$fieldName] = [
          [
            'value' => $value,
            'format' => 'basic_html',
          ],
        ];
      }
    }
  }

  /**
   * Get the value of all link fields, whatever their cardinality.
   *
   * @param array $values
   *   The structured data of the entity.
   * @param array $data
   *   The data of the row.
   */
  protected function getLinkFields(array &$values, array $data): void {
    $links = $this->filterFields('link', $data);
    if (empty($links)) {
      return;
    }

    foreach ($links as $reference => $referenceValue) {
      $elements = \explode('-', $reference);
      $fieldName = \trim($elements[1]);
      $contents = \array_filter(\explode('|', $referenceValue));
      foreach ($contents as $link) {
        $href = \explode('@', $link);
        $values[$fieldName][] = [
          'title' => \trim($href[0]),
          'uri' => \trim($href[1]),
        ];
      }
    }
  }

  /**
   * Get the value of all reference type fields, whatever their cardinality.
   *
   * @param array $values
   *   The structured data of the entity.
   * @param array $data
   *   The data of the row.
   */
  protected function getReferenceFields(
    array &$values,
    array $data
  ): void {
    $references = $this->filterFields('reference', $data);
    if (empty($references)) {
      return;
    }
    foreach ($references as $reference => $referenceValue) {
      $elements = \explode('-', $reference);
      $entityType = \trim($elements[1]);
      $fieldName = \trim($elements[2]);
      $targets = \array_filter(\explode('|', $referenceValue));

      foreach ($targets as $target) {
        $target = \explode(':', $target);
        $bundle = $target[0];
        $csvId = $target[1];

        $drupalId = $this->getDrupalId($entityType, $bundle, $csvId);

        // Log and return.
        if (\is_null($drupalId)) {
          $this->log(
            'Unable to find a drupal id.',
            'alert',
            __FUNCTION__,
            $entityType,
            $bundle,
            [$fieldName, $csvId]
          );

          return;
        }

        $values[$fieldName][] = ['target_id' => $drupalId];
      }
    }
  }

  /**
   * Get the target_id for a reference field, wathever the cardinality.
   *
   * @param array $values
   *   The Structured entity data.
   * @param array $data
   *   The data of the row.
   */
  protected function getParagraphsFields(
    array &$values,
    array $data
  ) {
    $paragraphs = $this->filterFields('paragraphs', $data);
    if (empty($paragraphs)) {
      return;
    }

    foreach ($paragraphs as $paragraph => $paragraphValue) {
      $elements = \explode('-', $paragraph);
      $entityType = \trim($elements[1]);
      $field_name = \trim($elements[2]);
      $targets = \array_filter(\explode('|', $paragraphValue));

      foreach ($targets as $target) {
        $target = \explode(':', $target);
        $bundle = $target[0];
        $csvId = $target[1];
        $drupalId = $this->getDrupalId($entityType, $bundle, $csvId);

        // Log and return.
        if (\is_null($drupalId)) {
          $this->log(
            'Unable to find a drupal id.',
            'alert',
            __FUNCTION__,
            $entityType,
            $bundle,
            [$field_name, $csvId]
          );

          return;
        }
        $paragraph = $this
          ->entityTypeManager
          ->getStorage('paragraph')
          ->load($drupalId);

        $values[$field_name][] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
      }
    }
  }

  /**
   * Callback function for filtering datas in the imported file.
   *
   * @param string $pattern
   *   The regex pattern.
   * @param array $data
   *   The data of the row.
   *
   * @return array
   *   The filtrered datas.
   */
  protected function filterFields(string $pattern, array $data): array {
    switch ($pattern) {
      case 'html':
        return \array_filter($data, function ($k) {
          return \preg_match('/^html-.+$/', $k);
        }, ARRAY_FILTER_USE_KEY);

      case 'reference':
        return \array_filter($data, function ($k) {
          return \preg_match('/^reference-.+$/', $k);
        }, ARRAY_FILTER_USE_KEY);

      case 'link':
        return \array_filter($data, function ($k) {
          return \preg_match('/^link-.+$/', $k);
        }, ARRAY_FILTER_USE_KEY);

      case 'field':
        return \array_filter($data, function ($k) {
          return \preg_match('/^field_.+$/', $k);
        }, ARRAY_FILTER_USE_KEY);

      default:
        return [];
    }
  }

  /**
   * Stores the uuid of an entity.
   *
   * @param array $record
   *   Data stored: [entity_uuid => entityType].
   */
  protected function storeUuid(array $record): void {
    $record = $this->state->get(self::STATE_FILE, []) + $record;
    $this->state->set(self::STATE_FILE, $record);
  }

  /**
   * Searches for a drupal id in the relevant entity mapping table.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The type of bundle to be created.
   * @param string $csvId
   *   The id in the csv file.
   *
   * @return int|string
   *   A drupal id|uuid, 0 if no drupal id found.
   */
  protected function getDrupalId(
    string $entityType,
    string $bundle,
    string $csvId
  ): ?int {
    if (
      \array_key_exists($entityType, $this->drupalIdMap) &&
      \array_key_exists($bundle, $this->drupalIdMap[$entityType]) &&
      \array_key_exists($csvId, $this->drupalIdMap[$entityType][$bundle])
    ) {
      return (int) $this->drupalIdMap[$entityType][$bundle][$csvId];
    }

    return NULL;
  }

  /**
   * Stores the correspondence between the drupal id and the csv id.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The type of bundle to be created.
   * @param string $csvId
   *   The id in the csv file.
   * @param string $drupalId
   *   The Drupal id.
   */
  protected function mapDrupalId(
    string $entityType,
    string $bundle,
    string $csvId,
    string $drupalId
  ): void {
    $this->drupalIdMap[$entityType][$bundle][$csvId] = $drupalId;
  }

  /**
   * Log message.
   *
   * @param string $message
   *   The message to display.
   * @param string $severity
   *   The severity.
   * @param string $function
   *   The name of the function that displays the message.
   * @param string $entityType
   *   The concerned entity.
   * @param string $bundle
   *   The concerned bundle.
   * @param array $options
   *   Additional information.
   */
  protected function log(
    string $message,
    string $severity,
    string $function,
    $entityType = 'undefined',
    $bundle = 'undefined',
    array $options = []
  ): void {
    $log = \sprintf(
      '[function:%s]',
      $function
    );
    if ('undefined' !== $entityType) {
      $log = \sprintf(
        '%s [entity:%s]',
        $log,
        $entityType
      );
    }
    if ('undefined' !== $bundle) {
      $log = \sprintf(
        '%s [bundle:%s]',
        $log,
        $bundle
      );
    }
    if (!empty($options)) {
      $additionnal = \implode(',', $options);
      $log = \sprintf(
        '%s [option:%s]',
        $log,
        $additionnal
      );
    }

    switch ($severity) {
      case 'notice':
        $this->logger->notice($log);
        $this->logger->notice($message);
        break;

      case 'alert':
        $this->logger->alert($log);
        $this->logger->alert($message);
        break;

      case 'warning':
        $this->logger->warning($log);
        $this->logger->warning($message);
        break;
    }
  }

}
