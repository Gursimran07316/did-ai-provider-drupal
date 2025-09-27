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

 

    return $form;
  }
  /**
   * Extra config shown when this automator is chosen.
   * Lets the editor map which fields hold the source image & audio and pick provider/model.
   */
  public function extraFormFields(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, FormStateInterface $form_state, array $defaults = []): array {
    $form = parent::extraFormFields($entity, $fieldDefinition, $form_state, $defaults);
  
    $form['did'] = [
      '#type' => 'details',
      '#title' => $this->t('D-ID Settings'),
      '#open' => TRUE,
      '#weight' => 20,
    ];
  
    // Build field options.
    $imageOptions = ['_none' => $this->t('No image — use presenter')];
    
    foreach ($entity->getFieldDefinitions() as $name => $def) {
      $type = $def->getType();
      if ($type === 'image') {
        $imageOptions[$name] = $def->getLabel() . " ($name)";
      }
      elseif ($type === 'file' || ($type === 'entity_reference' && $def->getSetting('target_type') === 'media')) {
        // file/media can be used for audio too
        $audioOptions[$name] = $def->getLabel() . " ($name)";
        // if you also want image from generic file fields:
        $imageOptions[$name] = $def->getLabel() . " ($name)";
      }
    }
  
    $form['automator_image_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Image field'),
      '#options' => $imageOptions,
      '#default_value' => $defaults['automator_image_field'] ?? '_none',
      '#required' => FALSE,
      '#description' => $this->t('Choose an image field, or select “No image — use presenter”.'),
      '#parents' => ['automator_image_field'],
    ];
  
  
    // Fetch presenters dynamically.
    $presenterOptions = ['' => $this->t('- Select a presenter -')];
    try {
      $presenters = $this->didApi->getPresenters();
      // Expecting a ["presenters" => [ ... ]] shape as you pasted
      foreach (($presenters['presenters'] ?? []) as $p) {
        if (!empty($p['presenter_id']) && !empty($p['name'])) {
          $label = $p['name'];
          if (!empty($p['owner_id']) && $p['owner_id'] === 'PUBLIC_D-ID') {
            $label .= ' (Public)';
          }
          $presenterOptions[$p['presenter_id']] = $label;
        }
      }
    }
    catch (\Throwable $e) {
      // Fail open: UI still renders without options.
    }
  
    $form['automator_presenter_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Presenter (used when no image)'),
      '#options' => $presenterOptions,
      '#default_value' => $defaults['automator_presenter_id'] ?? '',
      '#parents' => ['automator_presenter_id'],
      '#states' => [
        'visible' => [
          ':input[name="automator_image_field"]' => ['value' => '_none'],
        ],
        'required' => [
          ':input[name="automator_image_field"]' => ['value' => '_none'],
        ],
      ],
    ];
  
    // Expression stays the same, but give it the automator_ prefix.
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
    \Drupal::logger('did_ai_provider')->notice('Automator settings: @s', ['@s' => json_encode($settings)]);
    
    // 1) Resolve AUDIO from automator base_field (fallback to target field).
    $target_field_name = $field_definition->getName();
    $audio_source_field = trim((string) ($settings['base_field'] ?? ''));
    if ($audio_source_field === '') {
      $audio_source_field = $target_field_name;
    }
    \Drupal::logger('did_ai_provider')->notice(
      'Audio source field resolved to: @f (target=@t, base=@b)',
      ['@f' => $audio_source_field, '@t' => $target_field_name, '@b' => ($settings['base_field'] ?? '')]
    );
    $audio_path = $this->firstFilePathByMimePrefix($entity, $audio_source_field, 'audio/');
    if (!$audio_path) {
      \Drupal::logger('did_ai_provider')->warning('No audio found on field @f.', ['@f' => $audio_source_field]);
      return [];
    }
  
    // 2) Resolve IMAGE vs PRESENTER and other options.
    $image_field = trim((string) (
      $settings['automator_image_field']
      ?? $settings['image_field']
      ?? $this->configuration['image_field']
      ?? '_none'
    ));
    $presenter_id = trim((string) (
      $settings['automator_presenter_id']
      ?? $settings['presenter_id']
      ?? $this->configuration['presenter_id']
      ?? ''
    ));
    $expression = (string) (
      $settings['automator_expression']
      ?? $settings['expression']
      ?? $this->configuration['expression']
      ?? 'neutral'
    );
    $wait = (bool) ($settings['wait_for_result'] ?? $this->configuration['wait_for_result'] ?? TRUE);
    $timeout = (int) ($settings['timeout'] ?? $this->configuration['timeout'] ?? 600);
  
    // 3) Call D-ID based on image vs presenter.
    $result = NULL;
    if ($image_field === '_none') {
      if ($presenter_id === '') {
        \Drupal::logger('did_ai_provider')->warning('No presenter selected while image set to _none.');
        return [];
      }
      if (method_exists($this->didApi, 'isValidPresenterId') && !$this->didApi->isValidPresenterId($presenter_id)) {
        \Drupal::logger('did_ai_provider')->error('Invalid presenter_id: @id', ['@id' => $presenter_id]);
        return [];
      }
      $result = $wait
        ? $this->didApi->generateVideoFromAudioAndPresenterSync($audio_path, $presenter_id, $expression, $timeout)
        : $this->didApi->generateVideoFromAudioAndPresenter($audio_path, $presenter_id, $expression);
    }
    else {
      $image_path = $this->firstFilePathByMimePrefix($entity, $image_field, 'image/');
      if (!$image_path) {
        \Drupal::logger('did_ai_provider')->warning('Image field @f selected but empty.', ['@f' => $image_field]);
        return [];
      }
      $result = $wait
        ? $this->didApi->generateVideoFromAudioAndImageSync($audio_path, $image_path, $expression, $timeout)
        : $this->didApi->generateVideoFromAudioAndImage($audio_path, $image_path, $expression);
    }
  
    if (!$result) {
      \Drupal::logger('did_ai_provider')->error(
        'D-ID generation failed. presenter=@p expr=@e audio_field=@af',
        ['@p' => $presenter_id ?: 'n/a', '@e' => $expression, '@af' => $audio_source_field]
      );
      return [];
    }
    if (empty($result['result_url'])) {
      \Drupal::logger('did_ai_provider')->warning('D-ID response has no result_url. Raw: @raw', ['@raw' => json_encode($result)]);
      return [];
    }
  
    // 4) Download the video bytes and return for file storage.
    $client = $this->didApi->getHttpClient();
    try {
      $res = $client->get((string) $result['result_url'], ['http_errors' => FALSE, 'timeout' => 120]);
      if ($res->getStatusCode() !== 200) {
        \Drupal::logger('did_ai_provider')->warning('Video download HTTP @c', ['@c' => $res->getStatusCode()]);
        return [];
      }
      $bytes = (string) $res->getBody();
    }
    catch (\Throwable $e) {
      \Drupal::logger('did_ai_provider')->error('Video download failed: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  
    if ($bytes === '') {
      \Drupal::logger('did_ai_provider')->warning('Empty video bytes from result_url.');
      return [];
    }
  
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