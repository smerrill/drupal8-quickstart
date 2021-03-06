<?php

/**
 * @file
 * Contains \Drupal\Core\Entity\EntityViewBuilder.
 */

namespace Drupal\Core\Entity;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Render\Element;
use Drupal\entity\Entity\EntityViewDisplay;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for entity view controllers.
 */
class EntityViewBuilder extends EntityControllerBase implements EntityControllerInterface, EntityViewBuilderInterface {

  /**
   * The type of entities for which this controller is instantiated.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * Information about the entity type.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityType;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The cache bin used to store the render cache.
   *
   * @var string
   */
  protected $cacheBin = 'render';

  /**
   * The language manager.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   */
  protected $languageManager;

  /**
   * Constructs a new EntityViewBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityManagerInterface $entity_manager, LanguageManagerInterface $language_manager) {
    $this->entityTypeId = $entity_type->id();
    $this->entityType = $entity_type;
    $this->entityManager = $entity_manager;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity.manager'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL) {
    $build_list = $this->viewMultiple(array($entity), $view_mode, $langcode);

    // The default ::buildMultiple() #pre_render callback won't run, because we
    // extract a child element of the default renderable array. Thus we must
    // assign an alternative #pre_render callback that applies the necessary
    // transformations and then still calls ::buildMultiple().
    $build = $build_list[0];
    $build['#pre_render'][] = array($this, 'build');

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function viewMultiple(array $entities = array(), $view_mode = 'full', $langcode = NULL) {
    if (!isset($langcode)) {
      $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->id;
    }

    $build_list = array(
      '#sorted' => TRUE,
      '#pre_render' => array(array($this, 'buildMultiple')),
      '#langcode' => $langcode,
    );
    $weight = 0;
    foreach ($entities as $key => $entity) {
      // Ensure that from now on we are dealing with the proper translation
      // object.
      $entity = $this->entityManager->getTranslationFromContext($entity, $langcode);

      // Set build defaults.
      $build_list[$key] = $this->getBuildDefaults($entity, $view_mode, $langcode);
      $entityType = $this->entityTypeId;
      $this->moduleHandler()->alter(array($entityType . '_build_defaults', 'entity_build_defaults'), $build_list[$key], $entity, $view_mode, $langcode);

      $build_list[$key]['#weight'] = $weight++;
    }

    return $build_list;
  }

  /**
   * Provides entity-specific defaults to the build process.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity for which the defaults should be provided.
   * @param string $view_mode
   *   The view mode that should be used.
   * @param string $langcode
   *   For which language the entity should be prepared, defaults to
   *   the current content language.
   *
   * @return array
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode, $langcode) {
    // Allow modules to change the view mode.
    $context = array('langcode' => $langcode);
    $this->moduleHandler()->alter('entity_view_mode', $view_mode, $entity, $context);

    $build = array(
      '#theme' => $this->entityTypeId,
      "#{$this->entityTypeId}" => $entity,
      '#view_mode' => $view_mode,
      '#langcode' => $langcode,
      // Collect cache defaults for this entity.
      '#cache' => array(
        'tags' => NestedArray::mergeDeep($this->getCacheTag(), $entity->getCacheTag()),
      ),
    );

    // Cache the rendered output if permitted by the view mode and global entity
    // type configuration.
    if ($this->isViewModeCacheable($view_mode) && !$entity->isNew() && $entity->isDefaultRevision() && $this->entityType->isRenderCacheable()) {
      $build['#cache'] += array(
        'keys' => array(
          'entity_view',
          $this->entityTypeId,
          $entity->id(),
          $view_mode,
          'cache_context.theme',
          'cache_context.user.roles',
          // @todo Move this out of here and into field formatters that depend
          //       on the timezone. Blocked on https://drupal.org/node/2099137.
          'cache_context.timezone',
        ),
        'bin' => $this->cacheBin,
      );

      if ($entity instanceof TranslatableInterface && count($entity->getTranslationLanguages()) > 1) {
        $build['#cache']['keys'][] = $langcode;
      }
    }

    return $build;
  }

  /**
   * Builds an entity's view; augments entity defaults.
   *
   * This function is assigned as a #pre_render callback in ::view().
   *
   * It transforms the renderable array for a single entity to the same
   * structure as if we were rendering multiple entities, and then calls the
   * default ::buildMultiple() #pre_render callback.
   *
   * @param array $build
   *   A renderable array containing build information and context for an entity
   *   view.
   *
   * @return array
   *   The updated renderable array.
   *
   * @see drupal_render()
   */
  public function build(array $build) {
    $build_list = array(
      '#langcode' => $build['#langcode'],
    );
    $build_list[] = $build;
    $build_list = $this->buildMultiple($build_list);
    return $build_list[0];
  }

  /**
   * Builds multiple entities' views; augments entity defaults.
   *
   * This function is assigned as a #pre_render callback in ::viewMultiple().
   *
   * By delaying the building of an entity until the #pre_render processing in
   * drupal_render(), the processing cost of assembling an entity's renderable
   * array is saved on cache-hit requests.
   *
   * @param array $build_list
   *   A renderable  array containing build information and context for an
   *   entity view.
   *
   * @return array
   *   The updated renderable array.
   *
   * @see drupal_render()
   */
  public function buildMultiple(array $build_list) {
    // Build the view modes and display objects.
    $view_modes = array();
    $langcode = $build_list['#langcode'];
    $entity_type_key = "#{$this->entityTypeId}";
    $view_hook = "{$this->entityTypeId}_view";

    // Find the keys for the ContentEntities in the build; Store entities for
    // rendering by view_mode.
    $children = Element::children($build_list);
    foreach ($children as $key) {
      if (isset($build_list[$key][$entity_type_key])) {
        $entity = $build_list[$key][$entity_type_key];
        if ($entity instanceof ContentEntityInterface) {
          $view_modes[$build_list[$key]['#view_mode']][$key] = $entity;
        }
      }
    }

    // Build content for the displays represented by the entities.
    foreach ($view_modes as $view_mode => $view_mode_entities) {
      $displays = EntityViewDisplay::collectRenderDisplays($view_mode_entities, $view_mode);
      $this->buildComponents($build_list, $view_mode_entities, $displays, $view_mode, $langcode);
      foreach (array_keys($view_mode_entities) as $key) {
        // Allow for alterations while building, before rendering.
        $entity = $build_list[$key][$entity_type_key];
        $display = $displays[$entity->bundle()];

        $this->moduleHandler()->invokeAll($view_hook, array(&$build_list[$key], $entity, $display, $view_mode, $langcode));
        $this->moduleHandler()->invokeAll('entity_view', array(&$build_list[$key], $entity, $display, $view_mode, $langcode));

        $this->alterBuild($build_list[$key], $entity, $display, $view_mode, $langcode);

        // Assign the weights configured in the display.
        // @todo: Once https://drupal.org/node/1875974 provides the missing API,
        //   only do it for 'extra fields', since other components have been
        //   taken care of in EntityViewDisplay::buildMultiple().
        foreach ($display->getComponents() as $name => $options) {
          if (isset($build_list[$key][$name])) {
            $build_list[$key]['#weight'] = $options['weight'];
          }
        }

        // Allow modules to modify the render array.
        $this->moduleHandler()->alter(array($view_hook, 'entity_view'), $build_list[$key], $entity, $display);
      }
    }

    return $build_list;
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponents(array &$build, array $entities, array $displays, $view_mode, $langcode = NULL) {
    $entities_by_bundle = array();
    foreach ($entities as $id => $entity) {
      // Initialize the field item attributes for the fields being displayed.
      // The entity can include fields that are not displayed, and the display
      // can include components that are not fields, so we want to act on the
      // intersection. However, the entity can have many more fields than are
      // displayed, so we avoid the cost of calling $entity->getProperties()
      // by iterating the intersection as follows.
      foreach ($displays[$entity->bundle()]->getComponents() as $name => $options) {
        if ($entity->hasField($name)) {
          foreach ($entity->get($name) as $item) {
            $item->_attributes = array();
          }
        }
      }
      // Group the entities by bundle.
      $entities_by_bundle[$entity->bundle()][$id] = $entity;
    }

    // Invoke hook_entity_prepare_view().
    $this->moduleHandler()->invokeAll('entity_prepare_view', array($this->entityTypeId, $entities, $displays, $view_mode));

    // Let the displays build their render arrays.
    foreach ($entities_by_bundle as $bundle => $bundle_entities) {
      $display_build = $displays[$bundle]->buildMultiple($bundle_entities);
      foreach ($bundle_entities as $id => $entity) {
        $build[$id] += $display_build[$id];
      }
    }
  }

  /**
   * Specific per-entity building.
   *
   * @param array $build
   *   The render array that is being created.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be prepared.
   * @param \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display
   *   The entity view display holding the display options configured for the
   *   entity components.
   * @param string $view_mode
   *   The view mode that should be used to prepare the entity.
   * @param string $langcode
   *   (optional) For which language the entity should be prepared, defaults to
   *   the current content language.
   */
  protected function alterBuild(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, $view_mode, $langcode = NULL) { }

  /**
   * {@inheritdoc}
   */
  public function getCacheTag() {
    return array($this->entityTypeId . '_view' => TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(array $entities = NULL) {
    if (isset($entities)) {
      // Always invalidate the ENTITY_TYPE_list tag.
      $tags = array($this->entityTypeId . '_list' => TRUE);
      foreach ($entities as $entity) {
        $tags = NestedArray::mergeDeep($tags, $entity->getCacheTag());
      }
      Cache::invalidateTags($tags);
    }
    else {
      Cache::invalidateTags($this->getCacheTag());
    }
  }

  /**
   * Returns TRUE if the view mode is cacheable.
   *
   * @param string $view_mode
   *   Name of the view mode that should be rendered.
   *
   * @return bool
   *   TRUE if the view mode can be cached, FALSE otherwise.
   */
  protected function isViewModeCacheable($view_mode) {
    if ($view_mode == 'default') {
      // The 'default' is not an actual view mode.
      return TRUE;
    }
    $view_modes_info = $this->entityManager->getViewModes($this->entityTypeId);
    return !empty($view_modes_info[$view_mode]['cache']);
  }

  /**
   * {@inheritdoc}
   */
  public function viewField(FieldItemListInterface $items, $display_options = array()) {
    $output = array();
    $entity = $items->getEntity();
    $field_name = $items->getFieldDefinition()->getName();

    // Get the display object.
    if (is_string($display_options)) {
      $view_mode = $display_options;
      $display = EntityViewDisplay::collectRenderDisplay($entity, $view_mode);
      foreach ($entity as $name => $items) {
        if ($name != $field_name) {
          $display->removeComponent($name);
        }
      }
    }
    else {
      $view_mode = '_custom';
      $display = entity_create('entity_view_display', array(
        'targetEntityType' => $entity->getEntityTypeId(),
        'bundle' => $entity->bundle(),
        'mode' => $view_mode,
        'status' => TRUE,
      ));
      $display->setComponent($field_name, $display_options);
    }

    $build = $display->build($entity);
    if (isset($build[$field_name])) {
      $output = $build[$field_name];
    }

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function viewFieldItem(FieldItemInterface $item, $display = array()) {
    $entity = $item->getEntity();
    $field_name = $item->getFieldDefinition()->getName();

    // Clone the entity since we are going to modify field values.
    $clone = clone $entity;

    // Push the item as the single value for the field, and defer to viewField()
    // to build the render array for the whole list.
    $clone->{$field_name}->setValue(array($item->getValue()));
    $elements = $this->viewField($clone->{$field_name}, $display);

    // Extract the part of the render array we need.
    $output = isset($elements[0]) ? $elements[0] : array();
    if (isset($elements['#access'])) {
      $output['#access'] = $elements['#access'];
    }

    return $output;
  }

}
