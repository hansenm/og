<?php

namespace Drupal\og;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Manager for OG permissions.
 */
class PermissionManager implements PermissionManagerInterface {

  /**
   * The OG group manager.
   *
   * @var \Drupal\og\GroupManager
   */
  protected $groupManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The service providing information about bundles.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * Constructs a PermissionManager object.
   *
   * @param \Drupal\og\GroupManager $group_manager
   *   The OG group manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The service providing information about bundles.
   */
  public function __construct(GroupManager $group_manager, EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info) {
    $this->groupManager = $group_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Provide an alter hook.
   */
  public function getPermissionList($entity_type_id, $bundle_id) {
    $permissions = [];

    foreach ($this->groupManager->getGroupContentBundleIdsByGroupBundle($entity_type_id, $bundle_id) as $group_content_entity_type_id => $group_content_bundle_ids) {
      foreach ($group_content_bundle_ids as $group_content_bundle_id) {
        $permissions += $this->generateCrudPermissionList($group_content_entity_type_id, $group_content_bundle_id);
      }
    }

    return $permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function generateCrudPermissionList($group_content_entity_type_id, $group_content_bundle_id) {
    $permissions = [];

    // Check if the bundle is a group content type.
    if (!Og::isGroupContent($group_content_entity_type_id, $group_content_bundle_id)) {
      return [];
    }

    $entity_info = $this->entityTypeManager->getDefinition($group_content_entity_type_id);
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($group_content_entity_type_id)[$group_content_bundle_id];

    // Build standard list of permissions for this bundle.
    $args = [
      '%bundle' => $bundle_info['label'],
      '@entity' => $entity_info->getPluralLabel(),
    ];
    // @todo This needs to support all entity operations for the given entity
    //    type, not just the standard CRUD operations.
    // @see https://github.com/amitaibu/og/issues/222
    $permissions += [
      "create $group_content_bundle_id $group_content_entity_type_id" => [
        'title' => t('Create %bundle @entity', $args),
      ],
      "update own $group_content_bundle_id $group_content_entity_type_id" => [
        'title' => t('Edit own %bundle @entity', $args),
      ],
      "update any $group_content_bundle_id $group_content_entity_type_id" => [
        'title' => t('Edit any %bundle @entity', $args),
      ],
      "delete own $group_content_bundle_id $group_content_entity_type_id" => [
        'title' => t('Delete own %bundle @entity', $args),
      ],
      "delete any $group_content_bundle_id $group_content_entity_type_id" => [
        'title' => t('Delete any %bundle @entity', $args),
      ],
    ];

    // Add default permissions.
    foreach ($permissions as $key => $value) {
      $permissions[$key]['default role'] = [OgRoleInterface::ADMINISTRATOR];
    }

    return $permissions;
  }

}
