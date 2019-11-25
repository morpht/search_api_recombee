<?php

namespace Drupal\search_api_recombee\Plugin\search_api\data_type;

use Drupal\search_api\Plugin\search_api\data_type\StringDataType;

/**
 * Provides a Recombee Image List data type.
 *
 * @SearchApiDataType(
 *   id = "recombee_image_list",
 *   label = @Translation("Recombee Image List"),
 *   description = @Translation("Contains list of URLs that refer to images.")
 * )
 */
class RecombeeImageListDataType extends StringDataType {

}
