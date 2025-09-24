<?php

declare(strict_types=1);

namespace Drupal\did_ai_provider\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\OperationType\GenericType\VideoFile;
use Drupal\ai\OperationType\ImageAndAudioToVideo\ImageAndAudioToVideoInput;
use Drupal\ai\OperationType\ImageAndAudioToVideo\ImageAndAudioToVideoInterface;
use Drupal\ai\OperationType\ImageAndAudioToVideo\ImageAndAudioToVideoOutput;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\did_ai_provider\DidApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of the 'D-ID' provider.
 */
#[AiProvider(
  id: 'did_ai_provider',
  label: new TranslatableMarkup('D-ID'),
)]
final class DidProvider extends AiProviderClientBase implements
  ContainerFactoryPluginInterface,
  ImageAndAudioToVideoInterface {


/**
 * D-ID API client service.
 *
 * @var \Drupal\did_ai_provider\DidApiService
 */
protected DidApiService $client;
  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * File URL generator service.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Track any temp managed files you might create (not required here,
   * but kept for parity with other providers).
   *
   * @var array
   */
  protected array $temporaryFiles = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    // Use YOUR service id, not the old one.
    $instance->client = $container->get('did_ai_provider.api');
    $instance->fileSystem = $container->get('file_system');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    // Optional: use your dedicated logger channel.
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * Destructor.
   */
  public function __destruct() {
    // If you push any temporary managed files into $this->temporaryFiles,
    // you can delete them here. Not used in this implementation.
    foreach ($this->temporaryFiles as $file) {
      try {
        $file->delete();
      }
      catch (\Throwable $e) {
        // Swallow.
      }
    }
  }

  /**
   * Raw client accessor (parity with other providers).
   */
  public function getClient(): DidApiService {
    return $this->client;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    // MUST match the OperationType id.
    return ['image_and_audio_to_video'];
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    // Needs an API key to be configured.
    if (!$this->getConfig()->get('api_key')) {
      return FALSE;
    }
    if ($operation_type) {
      return in_array($operation_type, $this->getSupportedOperationTypes(), TRUE);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $op = NULL, array $capabilities = []): array {
    return [
      'default' => 'D-ID',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Required by AiProviderClientBase. Lets the UI render extra per-model
   * settings (optional). We expose only "expression" here.
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    $generalConfig['expression'] = [
      'label' => 'Expression',
      'description' => 'Driver expression (neutral, happy, surprise, serious).',
      'type' => 'string',
      'default' => 'neutral',
      'constraints' => [
        'options' => [
          'neutral' => 'neutral',
          'happy' => 'happy',
          'surprise' => 'surprise',
          'serious' => 'serious',
        ],
      ],
      'required' => FALSE,
    ];
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    // Your settings form should store API key here.
    return $this->configFactory->get('did_ai_provider.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    // Optional: definition shown in AI Automator / Explorer.
    $path = $this->moduleHandler
      ->getModule('did_ai_provider')
      ->getPath() . '/definitions/api_defaults.yml';

    if (is_file($path)) {
      return Yaml::parseFile($path) ?? [];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // No-op; your Did service reads API key from config. Keep parity.
  }

  /**
   * Operation implementation.
   *
   * IMPORTANT:
   *  - Method name MUST be imageAndAudioToVideo(...) to match the op id.
   *  - Input is a typed ImageAndAudioToVideoInput from the Explorer.
   *  - Output must return VideoFile[] (not AudioFile[]).
   */
  public function imageAndAudioToVideo(string|array|ImageAndAudioToVideoInput $input, string $model_id, array $tags = []): ImageAndAudioToVideoOutput {
    // The Explorer provides the typed input; we enforce that.
    if (!$input instanceof ImageAndAudioToVideoInput) {
      throw new AiBadRequestException('Expected ImageAndAudioToVideoInput.');
    }

    // Extract typed files from the input.
    $image = $input->getImageFile(); // \Drupal\ai\OperationType\GenericType\ImageFile
    $audio = $input->getAudioFile(); // \Drupal\ai\OperationType\GenericType\AudioFile

    // Try to use URLs if the GenericType classes expose them; if not, persist
    // the binaries to public:// and generate absolute URLs for D-ID.
    $imageUrl = method_exists($image, 'getUrl') ? $image->getUrl() : NULL;
    $audioUrl = method_exists($audio, 'getUrl') ? $audio->getUrl() : NULL;

    // Ensure the directory exists (arg must be by reference).
    $dir = 'public://ai_did';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

if (empty($imageUrl)) {
  $imgName = uniqid('img_', true) . '-' . $image->getFilename();
  $imgUri = $dir . '/' . $imgName;
  $this->fileSystem->saveData($image->getBinary(), $imgUri, FileSystemInterface::EXISTS_REPLACE);
  $imageUrl = $this->fileUrlGenerator->generateAbsoluteString($imgUri);
}

if (empty($audioUrl)) {
  $audName = uniqid('aud_', true) . '-' . $audio->getFilename();
  $audUri = $dir . '/' . $audName;
  $this->fileSystem->saveData($audio->getBinary(), $audUri, FileSystemInterface::EXISTS_REPLACE);
  $audioUrl = $this->fileUrlGenerator->generateAbsoluteString($audUri);
}

    

    if (empty($imageUrl) || empty($audioUrl)) {
      throw new AiBadRequestException('Both image and audio are required.');
    }

    // Optional expression from provider config (UI setting).
    $expression = $this->configuration['expression'] ?? 'neutral';

    // Call the D-ID client. This method should POST, poll until done, and
    // return something like: ['result_url' => 'https://.../video.mp4'].
    $result = $this->client->generateVideoFromAudioAndImageSync($audioUrl, $imageUrl, $expression);

    if (empty($result['result_url'])) {
      throw new AiBadRequestException('No video returned from D-ID.');
    }

    $videoUrl = (string) $result['result_url'];

    // Download the produced video so we can hand a binary to the AI layer.
    $binary = @file_get_contents($videoUrl);
    if ($binary === false) {
      throw new AiBadRequestException('Failed to download the generated video from D-ID.');
    }

    $filename = 'did-video-' . md5($videoUrl) . '.mp4';
    $videoFile = new VideoFile($binary, 'video/mp4', $filename);

    // Wrap into the AI operation output object.
    return new ImageAndAudioToVideoOutput([$videoFile], $result, ['source_url' => $videoUrl]);
  }

}