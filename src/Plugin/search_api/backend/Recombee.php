<?php

namespace Drupal\search_api_recombee\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Url;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\Field;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Recombee\RecommApi\Client;
use Recombee\RecommApi\Exceptions\ApiException;
use Recombee\RecommApi\Requests\AddItemProperty;
use Recombee\RecommApi\Requests\DeleteItemProperty;
use Recombee\RecommApi\Requests\ListItemProperties;
use Recombee\RecommApi\Requests\Request;
use Recombee\RecommApi\Requests\ResetDatabase;
use Recombee\RecommApi\Requests\SetItemValues;

/**
 * Indexes items using the Recombee API.
 *
 * @SearchApiBackend(
 *   id = "recombee",
 *   label = @Translation("Recombee"),
 *   description = @Translation("Indexes items using the Recombee API.")
 * )
 */
class Recombee extends BackendPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  /**
   * The Recombee API client.
   *
   * @var \Recombee\RecommApi\Client
   */
  protected $client;

  /**
   * Gets the Recombee API client.
   *
   * @return \Recombee\RecommApi\Client
   *   The Recombee API client.
   */
  protected function getClient() {
    // Only if not initialized yet.
    if (empty($this->client)) {
      $account = $this->configuration['account'];
      $token = $this->configuration['token'];

      // Initialize API client.
      $this->client = new Client($account, $token);
    }
    return $this->client;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'account' => NULL,
      'token' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Prepare description links.
    $recombee_link = Link::fromTextAndUrl('Recombee', Url::fromUri('https://admin.recombee.com/'))
      ->toString();

    $form['account'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database ID'),
      '#description' => $this->t('The @recombee "API Identifier" value (see "Settings" page for a database).', [
        '@recombee' => $recombee_link,
      ]),
      '#default_value' => $this->configuration['account'],
      '#required' => TRUE,
    ];
    $form['token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Private token'),
      '#description' => $this->t('The @recombee "Private token" value (see "Settings" page for a database).', [
        '@recombee' => $recombee_link,
      ]),
      '#default_value' => $this->configuration['token'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Get credentials from the current form.
    $account = $form_state->getValue('account');
    $token = $form_state->getValue('token');

    try {
      // Call API to get list of the database properties.
      $this->send(new ListItemProperties(), $account, $token);
    }
    catch (\Exception $e) {
      $form_state->setErrorByName('token', $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $info[] = [
      'label' => $this->t('Database ID'),
      'info' => $this->configuration['account'],
    ];
    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type) {
    return in_array($type, [
      'recombee_set',
      'recombee_image',
      'recombee_image_list',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    $this->updateIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    $settings = $index->getThirdPartySettings('search_api_recombee');
    // Update database properties if allowed.
    if (!empty($settings['schema'])) {
      $this->updateSchema($index);
    }
  }

  /**
   * Updates the database properties for specific index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The updated index.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the Recombee API error occurs.
   */
  protected function updateSchema(IndexInterface $index) {
    // Get list of the database properties.
    $properties = $this->getProperties();

    // Delete renamed fields if needed.
    foreach ($index->getFieldRenames() as $field_old => $field_new) {
      if (isset($properties[$field_old])) {
        // Call API to delete the database property.
        $this->send(new DeleteItemProperty($field_old));
        unset($properties[$field_old]);
      }
    }

    // Define default data type overrides.
    $type_mapping = [
      'text' => 'string',
      'integer' => 'int',
      'decimal' => 'double',
      'date' => 'timestamp',
      'recombee_set' => 'set',
      'recombee_image' => 'image',
      'recombee_image_list' => 'imageList',
    ];

    // Temporarily add Site ID field, so the property is created.
    $site_field = new Field($index, 'site');
    $site_field->setType('string');
    $index->addField($site_field);

    // Process all configured fields.
    foreach ($index->getFields() as $field_id => $field) {
      $property_type = $field->getType();

      // Override data type if needed.
      if (isset($type_mapping[$property_type])) {
        $property_type = $type_mapping[$property_type];
      }

      // Delete field if different type is used.
      if (isset($properties[$field_id])) {
        if ($property_type !== $properties[$field_id]) {
          // Call API to delete the database property.
          $this->send(new DeleteItemProperty($field_id));
          unset($properties[$field_id]);
        }
      }

      // Create new field if needed.
      if (!isset($properties[$field_id])) {
        // Call API to add the database property.
        $this->send(new AddItemProperty($field_id, $property_type));
        $reindex = TRUE;
      }
    }

    if (!empty($reindex)) {
      $index->reindex();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    $settings = $index->getThirdPartySettings('search_api_recombee');
    // Get list of the database properties.
    $properties = $this->getProperties();

    $indexed = [];
    foreach ($items as $id => $item) {
      try {
        $this->indexItem($item, $properties, $settings);
        $indexed[] = $id;
      }
      catch (\Exception $e) {
        $this->getLogger()->warning($e->getMessage());
      }
    }
    return $indexed;
  }

  /**
   * Indexes a single item on the specified index.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item to index.
   * @param array $properties
   *   The set of supported database properties.
   * @param array $settings
   *   The search index settings.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the Recombee API error occurs.
   */
  protected function indexItem(ItemInterface $item, array $properties, array $settings) {
    $recombee_config = \Drupal::config('recombee.settings');
    $site_id = $recombee_config->get('site_id');
    $base_url = $recombee_config->get('base_url');
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $item->getOriginalObject()->getValue();

    // Compose item ID using the site ID, entity type and ID.
    $item_id = recombee_item_id($entity, $site_id);

    $item_values = [
      'site' => $site_id,
    ];
    // Process all configured fields.
    foreach ($item->getFields() as $field_id => $field) {
      // Skip unsupported properties.
      if (!isset($properties[$field_id])) {
        continue;
      }
      $item_values[$field_id] = $field->getValues();

      // Make URL absolute for URI field and image data types.
      if (!empty($base_url)
        && ($field->getPropertyPath() === 'search_api_url'
          || in_array($properties[$field_id], ['image', 'imageList']))
      ) {
        foreach ($item_values[$field_id] as &$value) {
          $value = $base_url . $value;
        }
      }

      // Flatten value if set or list is not used.
      if (!in_array($properties[$field_id], ['set', 'imageList'])) {
        $item_values[$field_id] = array_pop($item_values[$field_id]);
      }
    }

    // Send values if available.
    if (!empty($item_values)) {
      // Call API to set the item values.
      $this->send(new SetItemValues($item_id, $item_values, [
        'cascadeCreate' => TRUE,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    // TODO.
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $settings = $index->getThirdPartySettings('search_api_recombee');
    // Call API to clear the whole database if allowed.
    if (!empty($settings['schema'])) {
      $this->send(new ResetDatabase());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    // TODO.
  }

  /**
   * Sends a Recombee API request.
   *
   * @param \Recombee\RecommApi\Requests\Request $request
   *   The Recombee API request to be sent.
   * @param string $account
   *   (optional) The Recombee API database ID.
   *   Taken from the index configuration by default.
   * @param string $token
   *   (optional) The Recombee API private token.
   *   Taken from the index configuration by default.
   *
   * @return array
   *   The Recombee API call result.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the Recombee API error occurs.
   */
  protected function send(Request $request, $account = NULL, $token = NULL) {
    // Initialize a temporary API client if needed.
    if (!empty($account) && !empty($token)) {
      $client = new Client($account, $token);
    }
    else {
      // Use already initialized client.
      $client = $this->getClient();
    }

    try {
      // Send the API request.
      $request->setTimeout(10000);
      $result = $client->send($request);
    }
    catch (ApiException $e) {
      // Parse error if available.
      $error = json_decode($e->getMessage());
      if (!empty($error->message)) {

        // Compose message to be displayed.
        $message = $this->t('Recombee API error @code: @message', [
          '@code' => !empty($error->statusCode) ? $error->statusCode : 0,
          '@message' => $error->message,
        ]);
        $this->messenger->addError($message);
        throw new SearchApiException($message);
      }
      else {
        throw new SearchApiException($e);
      }
    }
    return $result;
  }

  /**
   * Gets the Recombee database properties.
   *
   * @return array
   *   The set of data types, keyed by property name.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the Recombee API error occurs.
   */
  protected function getProperties() {
    // Call API to get list of the database properties.
    $result = $this->send(new ListItemProperties());

    $properties = [];
    foreach ($result as $property) {
      $properties[$property['name']] = $property['type'];
    }
    return $properties;
  }

}
