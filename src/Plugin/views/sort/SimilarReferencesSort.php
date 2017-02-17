<?php

namespace Drupal\similarreferences\Plugin\views\sort;

use Drupal\views\Plugin\views\sort\SortPluginBase;

/**
 * Handler which sort by the similarity.
 *
 * @ingroup views_sort_handlers
 *
 * @ViewsSort("similar_references_sort")
 */
class SimilarReferencesSort extends SortPluginBase {

  /**
   * Define default sorting order.
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['order'] = array('default' => 'DESC');
    return $options;
  }

  /**
   * Add orderBy.
   */
  public function query() {
    $this->ensureMyTable();
    //    \Drupal::logger('similarreferences')->debug(print_r($this, TRUE));
//    dpm($this);

    $this->query->addOrderBy($this->tableAlias, 'nid', $this->options['order'], NULL, array('function' => 'count'));
  }

}
