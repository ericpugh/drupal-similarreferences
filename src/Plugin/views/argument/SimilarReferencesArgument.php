<?php

namespace Drupal\similarreferences\Plugin\views\argument;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\argument\NumericArgument;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Argument handler to accept a node id.
 *
 * @ingroup views_argument_handlers
 *
 * @ViewsArgument("similar_references_arg")
 */
class SimilarReferencesArgument extends NumericArgument implements ContainerFactoryPluginInterface {

  /**
   * Database Service Object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  private $entityTypeManager;

  /**
   * Constructs the SimilarReferencesArgument object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param Connection $connection
   *   The datbase connection.
   * @param EntityTypeManager $entity_type_manager
   *   The vocabulary storage.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $connection, EntityTypeManager $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->connection = $connection;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Define default values for options.
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['reference_fields'] = ['default' => []];
    $options['include_args'] = ['default' => FALSE];

    return $options;
  }

  /**
   * Build options settings form.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {

    parent::buildOptionsForm($form, $form_state);

    $referenceFields = [];
    foreach (['node', 'user'] as $type) {
      $referenceFields += $this->getReferenceFieldsByType($type);
    }

    $form['reference_fields'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Limit similarity to references from these content reference fields'),
      '#description' => $this->t('Choosing any reference fields here will limit the fields used to calculate similarity. Leave all checkboxes unselected to not limit fields.'),
      '#options' => $referenceFields,
      '#default_value' => empty($this->options['reference_fields']) ? [] : $this->options['reference_fields'],
    );

    $form['include_args'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Include argument node(s) in results'),
      '#description' => $this->t('If selected, the node(s) passed as the argument will be included in the view results.'),
      '#default_value' => !empty($this->options['include_args']),
    );
  }

  /**
   * Validate this argument works. By default, all arguments are valid.
   */
  public function validateArgument($arg) {

    if (isset($this->argument_validated)) {
      return $this->argument_validated;
    }

    // The argument.
    $this->value = [$arg => $arg];

    // Get the content reference fields selected.
    $referenceFields = empty($this->options['reference_fields']) ? [] : $this->options['reference_fields'];
    foreach ($referenceFields as $key => $val) {
      if ($val === 0) {
        unset($referenceFields[$key]);
      }
    }
    // Use all fields if none explicitly selected.
    if (empty($referenceFields)) {
      $fields = array_keys($this->options['reference_fields']);
      foreach ($fields as $field) {
        $referenceFields[$field] = $field;
      }
    }

    // Get information from each content reference field.
    $fields = [];
    foreach ($referenceFields as $fieldName) {
      $fields[$fieldName]['table_name'] = sprintf('node__%s', $fieldName);
      $fields[$fieldName]['column_name'] = sprintf('%s_target_id', $fieldName);
      $fields[$fieldName]['target_ids'] = $this->getReferenceFieldTargetIds($fieldName, $arg);
    }

    // Get entity ids and append to the field properties.
    $hasRelationship = FALSE;
    $similarCount = 0;
    if ($fields) {
      foreach ($fields as $name => $field) {
        $entityIds = [];
        if (!empty($field['target_ids'])) {
          $select = $this->connection->select($field['table_name'], 'fd');
          $select->fields('fd', ['entity_id', $field['column_name']]);
          $select->condition($field['column_name'], $field['target_ids'], 'IN');
          $entityIds = array_keys($select->execute()->fetchAllKeyed());
          $hasRelationship = !empty($entityIds) ? TRUE : FALSE;
        }
        $fields[$name]['entity_ids'] = $entityIds;
        $similarCount += count($entityIds);
        // Clean up empty fields.
        if (empty($field['target_ids']) || empty($fields[$name]['entity_ids'])) {
          unset($fields[$name]);
        }
      }
    }

    $this->fields = $fields;
    $this->view->total_similar = $similarCount;

    if (empty($fields) || !$hasRelationship) {
      return FALSE;
    }

    return TRUE;

  }

  /**
   * Add filter(s).
   */
  public function query() {
    $this->ensureMyTable();

    // Add relationships.
    foreach ($this->fields as $name => $field) {
      if (!empty($field['entity_ids'])) {
        $configuration = array(
          'left_table' => 'node_field_data',
          'left_field' => 'nid',
          'table' => $field['table_name'],
          'field' => 'entity_id',
          'adjusted' => TRUE,
        );
        $join = \Drupal\views\Views::pluginManager('join')->createInstance('standard', $configuration);
        $this->query->addRelationship($field['table_name'], $join, 'node_field_data');
        $this->query->addWhere(0, $field['table_name'] . '.entity_id', $field['entity_ids'], 'IN');
      }
    }

    // Exclude the current node(s)
    if (empty($this->options['include_args'])) {
      $this->query->addWhere(0, "node.nid", $this->value, 'NOT IN');
    }
    $this->query->addGroupBy('nid');
  }


  /**
   * Get the target_id values of a given entity ID and field name
   * @param string $field
   *   The field name.
   * @param integer $entityId
   *   The entity ID.
   * @return array
   */
  public function getReferenceFieldTargetIds($field, $entityId) {
    $table = sprintf('node__%s', $field);
    $col = sprintf('%s_target_id', $field);
    $select = $this->connection->select($table, 'fd');
    $select->fields('fd', ['entity_id', $col]);
    $select->condition('entity_id', $entityId);
    $select->distinct();
    $results = $select->execute()->fetchAll();
    $ids = [];
    foreach ($results as $row) {
        $ids[] = $row->{$col};
    }
    // Don't allow zero as a target_id value.
    if(($key = array_search(0, $ids)) !== false) {
      unset($ids[$key]);
    }

    return $ids;
  }

  /**
   * @param string $targetType
   *   Target entity type
   * @return array
   */
  public function getReferenceFieldsByType($targetType) {
    $field_properties = [
      'settings' => [
        'target_type' => $targetType,
      ],
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'deleted' => FALSE,
      'status' => 1,
    ];
    $fields = $this->entityTypeManager->getStorage('field_storage_config')->loadByProperties($field_properties);
    $referenceFields = [];
    foreach ($fields as $field) {
      $referenceFields[$field->get('field_name')] = $field->get('field_name');
    }
    return $referenceFields;
  }

}
