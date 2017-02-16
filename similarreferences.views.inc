<?php

/**
 * @file
 * Provide a custom views field data that isn't tied to any other module.
 */

/**
 * Implements hook_views_data_alter().
 */
function similarreferences_views_data_alter(&$data) {

  $data['node']['similarreferences'] = array(
    'group' => t('Similar by References'),
    'title' => t('Similarity'),
    'help' => t('Percentage/count of content references which node has in common with node given as argument.'),
    'field' => array(
      'id' => 'similar_references_field',
    ),
    'sort' => array(
      'id' => 'similar_references_sort',
    ),
  );

  $data['node']['similar_references_nid'] = array(
    'title' => t('Nid'),
    'group' => t('Similar by References'),
    'help' => t('ID of content item(s).'),
    'argument' => array(
      'id' => 'similar_references_arg',
      'name field' => 'title',
      'numeric' => TRUE,
      'validate type' => 'nid',
    ),
  );
}
