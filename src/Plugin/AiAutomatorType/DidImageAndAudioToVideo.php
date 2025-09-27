<?php

declare(strict_types=1);

namespace Drupal\did_ai_provider\Plugin\AiAutomatorType;

use Drupal\ai_automators\Attribute\AiAutomatorType;
use Drupal\ai_automators\PluginBaseClasses\ExternalBase;
use Drupal\ai_automators\PluginInterfaces\AiAutomatorTypeInterface;
use Drupal\ai\OperationType\GenericType\AudioFile;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\ImageAndAudioToVideo\ImageAndAudioToVideoInput;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\did_ai_provider\DidApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\ai\Exception\AiRetryableException;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\Core\File\FileSystemInterface;
/**
 * D-ID: Image + Audio → Video ().
 */
#[AiAutomatorType(
  id: 'did_image_and_audio_to_video',
  label: new TranslatableMarkup('D-ID: Image + Audio → Video'),
  field_rule: 'file',
  target: 'file',
)]
class DidImageAndAudioToVideo extends ExternalBase implements AiAutomatorTypeInterface, ContainerFactoryPluginInterface {

  /**
   * D-ID API service.
   */
  protected DidApiService $didApi;
  use DependencySerializationTrait;
  /**
   * The instance loaded so far.
   *
   * @var array
   */
  protected array $providerInstances = [];
  protected string $llmType = 'image_and_audio_to_video';
  

  protected AiProviderPluginManager $aiPluginManager;
  /**
   * {@inheritDoc}
   */
  public $title = 'Did Videoo Field: Generate story';
  /**
   * Construct like your DownloaderBase (no provider manager).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DidApiService $didApi,
    AiProviderPluginManager $aiPluginManager,
  ) {
    $this->configuration = $configuration;
    $this->pluginId = $plugin_id;
    $this->pluginDefinition = $plugin_definition;
    $this->didApi = $didApi;
    $this->aiPluginManager = $aiPluginManager;
  }
  public function needsPrompt() {
    return FALSE;  // tells the field UI: don’t render the prompt box
  }
  
  
  public function placeholderText() {
    return '';     // optional: no placeholder if anything still reads it
  }
  public function allowedInputs() {
    return [
     'file'
    ];
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('did_ai_provider.api'),
      $container->get('ai.provider'),
    );
  }

  /**
   * Defaults + new field-source options.
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'expression' => 'neutral',
      'wait_for_result' => TRUE,
      'timeout' => 600,
      
      'image_field' => '',
      'audio_field' => '',
    ];
  }

  /**
   * Settings form shown in the rule UI.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
      // Hide prompt.
  unset($form['automator_prompt']);

  // Hide base mode + base field.
  if (isset($form['automator_input_mode'])) {
    $form['automator_input_mode']['#access'] = FALSE;
  }
  unset($form['automator_base_field']);

    $form['expression'] = [
      '#type' => 'select',
      '#title' => $this->t('Facial expression'),
      '#options' => [
        'neutral' => $this->t('Neutral'),
        'happy' => $this->t('Happy'),
        'surprised' => $this->t('Surprised'),
        'serious' => $this->t('Serious'),
        'angry' => $this->t('Angry'),
        'sad' => $this->t('Sad'),
      ],
      '#default_value' => $this->configuration['expression'] ?? 'neutral',
    ];

    $form['wait_for_result'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Wait for final video'),
      '#default_value' => (bool) ($this->configuration['wait_for_result'] ?? TRUE),
      '#description' => $this->t('If unchecked, returns a partial talk object (with id) you can poll later.'),
    ];

    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout (seconds)'),
      '#default_value' => (int) ($this->configuration['timeout'] ?? 600),
      '#min' => 30,
      '#max' => 1800,
    ];

    // New: explicit field sources (by machine name).
    $form['image_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image source field (optional)'),
      '#default_value' => (string) ($this->configuration['image_field'] ?? ''),
      '#description' => $this->t('Machine name of the field containing the still image (e.g. "field_headshot"). Leave empty to use the target field and auto-detect image by MIME.'),
    ];

    $form['audio_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Audio source field (optional)'),
      '#default_value' => (string) ($this->configuration['audio_field'] ?? ''),
      '#description' => $this->t('Machine name of the field containing the audio (e.g. "field_voiceover"). Leave empty to use the target field and auto-detect audio by MIME.'),
    ];

    return $form;
  }
  /**
   * Extra config shown when this automator is chosen.
   * Lets the editor map which fields hold the source image & audio and pick provider/model.
   */
  public function extraFormFields(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, FormStateInterface $form_state, array $defaults = []): array {
    $form = parent::extraFormFields($entity, $fieldDefinition, $form_state, $defaults);
  
    // (Optional) keep a details wrapper just for grouping/UX.
    $form['did'] = [
      '#type' => 'details',
      '#title' => $this->t('D-ID Settings'),
      '#open' => TRUE,
      '#weight' => 20,
    ];
  
    // Build options from fields on this bundle.
    $imageOptions = [];
    $audioOptions = [];
    foreach ($entity->getFieldDefinitions() as $name => $def) {
      $type = $def->getType();
      if ($type === 'image') {
        $imageOptions[$name] = $def->getLabel() . " ($name)";
      }
      elseif ($type === 'file' || ($type === 'entity_reference' && $def->getSetting('target_type') === 'media')) {
        // Let file/media be selectable for both (we’ll filter by mime later).
        $imageOptions[$name] = $def->getLabel() . " ($name)";
        $audioOptions[$name] = $def->getLabel() . " ($name)";
      }
    }
  
    // IMPORTANT: top-level keys and `automator_` prefix
    $form['automator_image_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Image field'),
      '#options' => $imageOptions,
      '#default_value' => $defaults['automator_image_field'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Field containing the still image (file or media).'),
      '#parents' => ['automator_image_field'], // ensure top-level key
    ];
  
    $form['automator_audio_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Audio field'),
      '#options' => $audioOptions,
      '#default_value' => $defaults['automator_audio_field'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Field containing the audio (file or media).'),
      '#parents' => ['automator_audio_field'], // ensure top-level key
    ];
  
    // (Optional) other options like expression/timeout can also be top-level with the prefix:
    $form['automator_expression'] = [
      '#type' => 'select',
      '#title' => $this->t('Expression'),
      '#options' => [
        'neutral' => $this->t('Neutral'),
        'happy' => $this->t('Happy'),
        'surprised' => $this->t('Surprised'),
        'serious' => $this->t('Serious'),
        'angry' => $this->t('Angry'),
        'sad' => $this->t('Sad'),
      ],
      '#default_value' => $defaults['automator_expression'] ?? 'neutral',
      '#parents' => ['automator_expression'],
    ];
  
    return $form;
  }

  /**
   * Save config.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['expression'] = (string) $form_state->getValue('expression');
    $this->configuration['wait_for_result'] = (bool) $form_state->getValue('wait_for_result');
    $this->configuration['timeout'] = (int) $form_state->getValue('timeout');
    $this->configuration['image_field'] = (string) $form_state->getValue('image_field');
    $this->configuration['audio_field'] = (string) $form_state->getValue('audio_field');
  }

  /**
   * Run when executed as a normal Automator Type (non-field context).
   */
  public function run(mixed $input, array $context = []): array {
    if (!$input instanceof ImageAndAudioToVideoInput) {
      throw new \InvalidArgumentException('DidImageAndAudioToVideo expects ImageAndAudioToVideoInput.');
    }

    $image = $input->getImageFile();
    $audio = $input->getAudioFile();

    if (!$image instanceof ImageFile || !$audio instanceof AudioFile) {
      throw new \InvalidArgumentException('Invalid input: image or audio not provided.');
    }

    $image_path_or_url = $image->getUrl() ?: $image->getPath();
    $audio_path_or_url = $audio->getUrl() ?: $audio->getPath();

    $expression = (string) ($this->configuration['expression'] ?? 'neutral');

    $result = !empty($this->configuration['wait_for_result'])
      ? $this->didApi->generateVideoFromAudioAndImageSync($audio_path_or_url, $image_path_or_url, $expression, (int) ($this->configuration['timeout'] ?? 600))
      : $this->didApi->generateVideoFromAudioAndImage($audio_path_or_url, $image_path_or_url, $expression);

    if (!$result) {
      return ['status' => 'error', 'message' => $this->t('Failed to create video using D-ID.')];
    }

    return [
      'status' => 'ok',
      'video_url' => $result['result_url'] ?? NULL,
      'talk_id' => $result['id'] ?? NULL,
      'data' => $result,
    ];
  }

  /**
   * Make this type usable on file fields (per field_rule/target).
   */
  public function applies(FieldDefinitionInterface $field_definition, ContentEntityInterface $entity): bool {
    return $field_definition->getType() === 'file';
  }

  /**
   * Execute against a node/entity field:
   * - If image_field/audio_field are set, pull from those fields.
   * - Otherwise, scan the target field and auto-detect by MIME type.
   */
  public function execute(ContentEntityInterface $entity, FieldDefinitionInterface $field_definition, array $settings = []): array {
    $image_field = trim((string) ($settings['image_field'] ?? $this->configuration['image_field'] ?? ''));
    $audio_field = trim((string) ($settings['audio_field'] ?? $this->configuration['audio_field'] ?? ''));

    $target_field_name = $field_definition->getName();

    // Resolve image + audio paths.
    $image_path = $image_field
      ? $this->firstFilePathByMimePrefix($entity, $image_field, 'image/')
      : $this->firstFilePathByMimePrefix($entity, $target_field_name, 'image/');

    $audio_path = $audio_field
      ? $this->firstFilePathByMimePrefix($entity, $audio_field, 'audio/')
      : $this->firstFilePathByMimePrefix($entity, $target_field_name, 'audio/');

    if (!$image_path || !$audio_path) {
      return [
        'status' => 'error',
        'message' => $this->t('Missing either image or audio file. Image: @img | Audio: @aud', [
          '@img' => $image_path ? 'OK' : 'Not found',
          '@aud' => $audio_path ? 'OK' : 'Not found',
        ]),
      ];
    }

    $expression = (string) ($settings['expression'] ?? $this->configuration['expression'] ?? 'neutral');
    $wait = (bool) ($settings['wait_for_result'] ?? $this->configuration['wait_for_result'] ?? TRUE);

    $result = $wait
      ? $this->didApi->generateVideoFromAudioAndImageSync($audio_path, $image_path, $expression, (int) ($settings['timeout'] ?? $this->configuration['timeout'] ?? 600))
      : $this->didApi->generateVideoFromAudioAndImage($audio_path, $image_path, $expression);

    if (!$result) {
      return ['status' => 'error', 'message' => $this->t('Failed to create video using D-ID.')];
    }

    return [
      'status' => 'ok',
      'data' => $result,
      'video_url' => $result['result_url'] ?? NULL,
      'talk_id' => $result['id'] ?? NULL,
    ];
  }
  public function generate(ContentEntityInterface $entity, FieldDefinitionInterface $field_definition, array $settings = []): array {
    // Resolve sources (your current logic).
    $image_field = trim((string) (
      $settings['automator_image_field']
      ?? $settings['image_field']               // optional fallback to plugin config
      ?? $this->configuration['image_field']
      ?? ''
    ));
    
    $audio_field = trim((string) (
      $settings['automator_audio_field']
      ?? $settings['audio_field']               // optional fallback to plugin config
      ?? $this->configuration['audio_field']
      ?? ''
    ));
    
    $expression = (string) (
      $settings['automator_expression']
      ?? $settings['expression']
      ?? $this->configuration['expression']
      ?? 'neutral'
    );
    $target_field_name = $field_definition->getName();
  
    $image_path = $image_field
      ? $this->firstFilePathByMimePrefix($entity, $image_field, 'image/')
      : $this->firstFilePathByMimePrefix($entity, $target_field_name, 'image/');
    $audio_path = $audio_field
      ? $this->firstFilePathByMimePrefix($entity, $audio_field, 'audio/')
      : $this->firstFilePathByMimePrefix($entity, $target_field_name, 'audio/');
  
    if (!$image_path || !$audio_path) {
      // No values => nothing to store.
      return [];
    }
    \Drupal::logger('did_ai_provider')->info(
      'DID fields: image=@i audio=@a', ['@i' => $image_field, '@a' => $audio_field]
    );
  
    $wait = (bool) ($settings['wait_for_result'] ?? $this->configuration['wait_for_result'] ?? TRUE);
    $timeout = (int) ($settings['timeout'] ?? $this->configuration['timeout'] ?? 600);
  
    // Call D-ID.
    $result = $wait
      ? $this->didApi->generateVideoFromAudioAndImageSync($audio_path, $image_path, $expression, $timeout)
      : $this->didApi->generateVideoFromAudioAndImage($audio_path, $image_path, $expression);
  
    // If you’re not waiting, ask the Automator queue to retry later instead of failing silently.
    if (!$wait && (!$result || empty($result['result_url']))) {
      throw new AiRetryableException('D-ID render started; retry soon.');
    }
  
    if (!$result || empty($result['result_url'])) {
      // Returning [] means "no values generated" and the rule will no-op.
      return [];
    }
  
    // Download the MP4 with Guzzle (not file_get_contents).
    $client = $this->didApi->getHttpClient(); // expose this in the service (see below)
    try {
      $res = $client->get((string) $result['result_url'], ['http_errors' => FALSE, 'timeout' => 120]);
      if ($res->getStatusCode() !== 200) {
        // If the video isn’t ready yet in queue mode, hint a retry.
        if (!$wait) {
          throw new AiRetryableException('D-ID video not ready (HTTP ' . $res->getStatusCode() . ').');
        }
        return [];
      }
      $bytes = (string) $res->getBody();
    }
    catch (\Throwable $e) {
      if (!$wait) {
        throw new AiRetryableException('D-ID download failed: ' . $e->getMessage());
      }
      return [];
    }
  
    if (strlen($bytes) === 0) {
      return [];
    }
  
    // Hand back what the framework expects for a "file" target.
    return [[
      'filename' => 'did-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 10) . '.mp4',
      'binary'   => $bytes,
    ]];
  }


public function verifyValue(ContentEntityInterface $entity, $value, FieldDefinitionInterface $field_definition, array $automator_config): bool {
  return is_array($value)
    && !empty($value['filename'])
    && isset($value['binary'])
    && is_string($value['binary'])
    && $value['binary'] !== '';
}

public function storeValues(ContentEntityInterface $entity, array $values, FieldDefinitionInterface $field_definition, array $automator_config) {
  if (empty($values)) {
    return FALSE;
  }

  $file_repo = \Drupal::service('file.repository');
  $fs = \Drupal::service('file_system');
  $dir = 'public://did_videos';
  $fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

  $items = [];
  foreach ($values as $v) {
    if (!$this->verifyValue($entity, $v, $field_definition, $automator_config)) {
      continue;
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $v['filename']) ?: ('did_' . \Drupal::time()->getRequestTime() . '.mp4');
    $uri = $dir . '/' . $safe;

    $file = $file_repo->writeData($v['binary'], $uri, FileSystemInterface::EXISTS_RENAME);
    if ($file instanceof FileInterface) {
      $file->setPermanent();
      $file->save();
      $items[] = ['target_id' => $file->id()];
    }
  }

  if (!$items) {
    \Drupal::logger('did_ai_provider')->warning('DID Automator: no files persisted for @field.', ['@field' => $field_definition->getName()]);
    return FALSE;
  }

  $entity->set($field_definition->getName(), $items);
  return TRUE;
}
  /**
   * Helper: get the first file path from a field whose MIME starts with $prefix.
   *
   * Supports typical file/image fields. If you use Media fields, you can extend
   * this to traverse into the media-source field on the media entity.
   */
  protected function firstFilePathByMimePrefix(ContentEntityInterface $entity, string $field_name, string $mime_prefix): ?string {
    if (!$entity->hasField($field_name)) {
      return NULL;
    }
    $items = $entity->get($field_name);
    if ($items->isEmpty()) {
      return NULL;
    }

    // If it's an entity reference to files, collect file entities.
    $files = [];
    foreach ($items->referencedEntities() as $referenced) {
      // File entity?
      if ($referenced->getEntityTypeId() === 'file') {
        $files[] = $referenced;
        continue;
      }
      // Minimal Media support: if you attach Media:file, try to unwrap the file.
      if ($referenced->getEntityTypeId() === 'media' && $referenced->hasField('field_media_file')) {
        foreach ($referenced->get('field_media_file')->referencedEntities() as $media_file) {
          $files[] = $media_file;
        }
      }
      // You can add more media-source unwrapping here if needed (image, audio).
    }

    foreach ($files as $file) {
      $mime = (string) $file->getMimeType();
      if (str_starts_with($mime, $mime_prefix)) {
        // Use local path; DidApiService will handle resizing/encoding when needed.
        return $file->getFileUri();
      }
    }
    return NULL;
    }

}