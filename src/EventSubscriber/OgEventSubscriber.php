<?php

namespace Drupal\og\EventSubscriber;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\og\Event\DefaultRoleEventInterface;
use Drupal\og\Event\PermissionEventInterface;
use Drupal\og\GroupContentOperationPermission;
use Drupal\og\GroupPermission;
use Drupal\og\OgRoleInterface;
use Drupal\og\PermissionManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscribers for Organic Groups.
 */
class OgEventSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The OG permission manager.
   *
   * @var \Drupal\og\PermissionManagerInterface
   */
  protected $permissionManager;

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
   * Constructs an OgEventSubscriber object.
   *
   * @param \Drupal\og\PermissionManagerInterface $permission_manager
   *   The OG permission manager.
   * @param \Drupal\core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The service providing information about bundles.
   */
  public function __construct(PermissionManagerInterface $permission_manager, EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info) {
    $this->permissionManager = $permission_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PermissionEventInterface::EVENT_NAME => [['provideDefaultOgPermissions']],
      DefaultRoleEventInterface::EVENT_NAME => [['provideDefaultRoles']],
    ];
  }

  /**
   * Provides default OG permissions.
   *
   * @param \Drupal\og\Event\PermissionEventInterface $event
   *   The OG permission event.
   */
  public function provideDefaultOgPermissions(PermissionEventInterface $event) {
    $event->setPermissions([
      new GroupPermission([
        'name' => 'update group',
        'title' => t('Edit group'),
        'description' => t('Edit the group. Note: This permission controls only node entity type groups.'),
        'default roles' => [OgRoleInterface::ADMINISTRATOR],
      ]),
      new GroupPermission([
        'name' => 'administer group',
        'title' => t('Administer group'),
        'description' => t('Manage group members and content in the group.'),
        'default roles' => [OgRoleInterface::ADMINISTRATOR],
        'restrict access' => TRUE,
      ]),
    ]);

    // Add a list of generic CRUD permissions for all group content.
    $group_content_permissions = $this->getDefaultEntityOperationPermissions($event->getGroupContentBundleIds());
    $event->setPermissions($group_content_permissions);
  }

  /**
   * Provides a default role for the group administrator.
   *
   * @param \Drupal\og\Event\DefaultRoleEventInterface $event
   *   The default role event.
   */
  public function provideDefaultRoles(DefaultRoleEventInterface $event) {
    /** @var \Drupal\og\Entity\OgRole $role */
    $role = $this->entityTypeManager->getStorage('og_role')->create([
      'name' => OgRoleInterface::ADMINISTRATOR,
      'label' => 'Administrator',
      'is_admin' => TRUE,
    ]);
    $event->addRole($role);
  }

  /**
   * Returns a list of generic entity operation permissions for group content.
   *
   * This returns generic group content entity operation permissions for the
   * operations 'create', 'update' and 'delete'.
   *
   * In Drupal the entity operation permissions are not following a machine
   * writable naming scheme, but instead they use an arbitrary human readable
   * format. For example the permission to update nodes of type article is 'edit
   * own article content'. This does not even contain the operation 'update' or
   * the entity type 'node'.
   *
   * OG needs to be able to provide basic CRUD permissions for its group content
   * even if it cannot generate the proper human readable versions. This method
   * settles for a generic permission format '{operation} {ownership} {bundle}
   * {entity type}'. For example for editing articles this would become 'update
   * own article node'.
   *
   * Modules can implement their own PermissionEvent to declare their proper
   * permissions to use instead of the generic ones. For an example
   * implementation, see `provideDefaultNodePermissions()`.
   *
   * @param array $group_content_bundle_ids
   *   An array of group content bundle IDs, keyed by group content entity type
   *   ID.
   *
   * @return \Drupal\og\GroupContentOperationPermission[]
   *   The array of permissions.
   *
   * @see \Drupal\og\EventSubscriber\OgEventSubscriber::provideDefaultNodePermissions()
   */
  protected function getDefaultEntityOperationPermissions(array $group_content_bundle_ids) {
    $permissions = [];

    foreach ($group_content_bundle_ids as $group_content_entity_type_id => $bundle_ids) {
      foreach ($bundle_ids as $bundle_id) {
        $permissions += $this->generateEntityOperationPermissionList($group_content_entity_type_id, $bundle_id);
      }
    }

    return $permissions;
  }

  /**
   * Helper method to generate entity operation permissions for a given bundle.
   *
   * @param $group_content_entity_type_id
   *   The entity type ID for which to generate the permission list.
   * @param $group_content_bundle_id
   *   The bundle ID for which to generate the permission list.
   *
   * @return array
   *   An array of permission names and descriptions.
   */
  protected function generateEntityOperationPermissionList($group_content_entity_type_id, $group_content_bundle_id) {
    $permissions = [];

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
    $operations = [
      [
        'name' => "create $group_content_bundle_id $group_content_entity_type_id",
        'title' => $this->t('Create %bundle @entity', $args),
        'operation' => 'create',
      ],
      [
        'name' => "update own $group_content_bundle_id $group_content_entity_type_id",
        'title' => $this->t('Edit own %bundle @entity', $args),
        'operation' => 'update',
        'ownership' => 'own',
      ],
      [
        'name' => "update any $group_content_bundle_id $group_content_entity_type_id",
        'title' => $this->t('Edit any %bundle @entity', $args),
        'operation' => 'update',
        'ownership' => 'any',
      ],
      [
        'name' => "delete own $group_content_bundle_id $group_content_entity_type_id",
        'title' => $this->t('Delete own %bundle @entity', $args),
        'operation' => 'delete',
        'ownership' => 'own',
      ],
      [
        'name' => "delete any $group_content_bundle_id $group_content_entity_type_id",
        'title' => $this->t('Delete any %bundle @entity', $args),
        'operation' => 'delete',
        'ownership' => 'any',
      ],
    ];

    // Add default permissions.
    foreach ($operations as $values) {
      $permission = new GroupContentOperationPermission($values);
      $permission
        ->setEntityType($group_content_entity_type_id)
        ->setBundle($group_content_bundle_id)
        ->setDefaultRoles([OgRoleInterface::ADMINISTRATOR]);
      $permissions[] = $permission;
    }

    return $permissions;
  }

}
