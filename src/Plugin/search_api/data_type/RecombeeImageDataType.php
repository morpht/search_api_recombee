<?php

namespace Drupal\search_api_recombee\Plugin\search_api\data_type;

use Drupal\search_api\Plugin\search_api\data_type\StringDataType;

/**
 * Provides a Recombee Image data type.
 *
 * @SearchApiDataType(
 *   id = "recombee_image",
 *   label = @Translation("Recombee Image"),
 *   description = @Translation("Contains URL of an image (jpeg, png or gif).")
 * )
 */
class RecombeeImageDataType extends StringDataType {

}
