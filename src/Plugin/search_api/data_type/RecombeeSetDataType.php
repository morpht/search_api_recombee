<?php

namespace Drupal\search_api_recombee\Plugin\search_api\data_type;

use Drupal\search_api\Plugin\search_api\data_type\StringDataType;

/**
 * Provides a Recombee Set data type.
 *
 * @SearchApiDataType(
 *   id = "recombee_set",
 *   label = @Translation("Recombee Set"),
 *   description = @Translation("Contains set of string values.")
 * )
 */
class RecombeeSetDataType extends StringDataType {

}
