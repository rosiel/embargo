<?php

namespace Drupal\migrate_embargoes_to_embargo\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Source a migration by selecting entities by type.
 *
 * @MigrateSource(
 *   id = "migrate_embargoes_to_embargo.source.entity"
 * )
 */
class Entity extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The ID of the target entity type.
   *
   * @var string
   */
  protected string $entityTypeId;

  /**
   * Constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);

    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeId = $this->configuration['entity_type'];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration = NULL
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('entity_type.manager')
    );
  }

  /**
   * Helper; lookup the definition of the target type.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface
   *   The looked-up type.
   */
  protected function getType() : EntityTypeInterface {
    return $this->entityTypeManager->getDefinition($this->entityTypeId);
  }

  /**
   * Get the storage for the given type.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage for the given type.
   */
  protected function getStorage() {
    return $this->entityTypeManager->getStorage($this->entityTypeId);
  }

  /**
   * Helper; map properties to descriptions thereof.
   *
   * @return string
   *   A human-readable description of the given typed data property.
   */
  protected function mapProp($def) {
    return "{$this->t('@label: @description', [
      '@label' => $def->getLabel(),
      '@description' => $def->getDescription(),
    ])}";
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $class = $this->getType()->getClass();
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $properties */
    $properties = call_user_func([$class, 'baseFieldDefinitions'], $this->getType());
    $mapped = array_map([$this, 'mapProp'], $properties);
    return $mapped;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return $this->configuration['keys'];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    // XXX: Loading all entities like this would fall apart if there was a
    // particularly large number of them; however, we do not expect this to be
    // used with terribly many at the moment... like only 10s or 100s.
    $storage = $this->getStorage();
    $results = $storage->getQuery()
      ->accessCheck()
      ->execute();
    foreach ($results as $id) {
      $array = $storage->load($id)->toArray();
      foreach ($this->getIds() as $key => $info) {
        if (is_array($array[$key])) {
          $array[$key] = reset($array[$key])['value'];
        }
      }
      yield $id => $array;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rewind() {
    unset($this->iterator);
    $this->next();
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return "{$this->t('"@type" entity source', ['@type' => $this->entityTypeId])}";
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep() {
    $vars = parent::__sleep();

    $to_suppress = [
      // XXX: Avoid serializing some DB things that we don't need.
      'iterator',
    ];
    foreach ($to_suppress as $value) {
      $key = array_search($value, $vars);
      if ($key !== FALSE) {
        unset($vars[$key]);
      }
    }

    return $vars;
  }

}
