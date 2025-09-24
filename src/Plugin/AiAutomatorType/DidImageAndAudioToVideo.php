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

  protected AiProviderPluginManager $aiPluginManager;
  /**
   * {@inheritDoc}
   */
  public $title = 'Did Video Field: Generate story';
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
  
    $form['did'] = [
      '#type' => 'details',
      '#title' => $this->t('D-ID Settings'),
      '#open' => TRUE,
      '#weight' => 20,
    ];
  
    // Expression.
    $form['did']['expression'] = [
      '#type' => 'select',
      '#title' => $this->t('Expression'),
      '#description' => $this->t('Choose the expression to use.'),
      '#default_value' => $defaults['expression'] ?? 'neutral',
      '#options' => [
        'neutral' => $this->t('Neutral'),
        'happy' => $this->t('Happy'),
        'surprised' => $this->t('Surprised'),
        'serious' => $this->t('Serious'),
        'angry' => $this->t('Angry'),
        'sad' => $this->t('Sad'),
      ],
    ];
  
    // Avatar source selector.
    $form['did']['avatar_option'] = [
      '#type' => 'select',
      '#title' => $this->t('Avatar type'),
      '#description' => $this->t('Choose the image to generate from.'),
      '#default_value' => $defaults['avatar_option'] ?? 'field',
      '#options' => [
        'url' => $this->t('Image URL'),
        'field' => $this->t('Image field'),
      ],
    ];
  
    // Image URL (when avatar_option = url).
    $form['did']['file_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image URL'),
      '#default_value' => $defaults['file_url'] ?? '',
      '#description' => $this->t('Public URL (e.g. https://example.com/test.jpg) or internal file like public://test.jpg.'),
      '#states' => [
        'visible' => [
          ':input[name="avatar_option"]' => ['value' => 'url'],
        ],
      ],
    ];
  
    // List of available file/image fields on the bundle (when avatar_option = field).
    $options = [];
    foreach ($entity->getFieldDefinitions() as $name => $def) {
      $type = $def->getType();
      if (in_array($type, ['image', 'file'], true)) {
        $options[$name] = $def->getLabel() . " ($name)";
      }
    }
  
    $form['did']['file_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Image field'),
      '#description' => $this->t('Choose the image field to use.'),
      '#default_value' => $defaults['file_field'] ?? '',
      '#options' => $options,
      '#states' => [
        'visible' => [
          ':input[name="avatar_option"]' => ['value' => 'field'],
        ],
      ],
    ];
  
    // Optional: choose the audio source field (same pattern).
    $form['did']['audio_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Audio field'),
      '#description' => $this->t('Choose the audio field to use.'),
      '#default_value' => $defaults['audio_field'] ?? '',
      '#options' => $options,
      '#required' => TRUE,
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