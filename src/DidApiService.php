<?php



namespace Drupal\did_ai_provider;

use Drupal\Component\FileSystem\FileSystem;
use Drupal\did_ai_provider\Form\DidAiSettingsForm;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\Client;

/**
 * Dream Studio API creator.
 */
class DidApiService {

  /**
   * The http client.
   */
  protected Client $client;

  /**
   * The file system.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * API Key.
   */
  private string $apiKey;

  /**
   * The base path.
   */
  private string $basePath = 'https://api.d-id.com/';

  /**
   * Constructs a new Did object.
   *
   * @param \GuzzleHttp\Client $client
   *   Http client.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   The config factory.
   */
  public function __construct(Client $client, ConfigFactory $configFactory, FileSystemInterface $fileSystem) {
    $this->client = $client;
    $this->apiKey = (string) ($configFactory->get('did_ai_provider.settings')->get('api_key') ?? '');
    $this->fileSystem = $fileSystem;
  }

  /**
   * Generate video from audio with an image syncronously.
   *
   * @param string $audioUrl
   *   The audio url.
   * @param string $imageUrl
   *   The image url.
   * @param string $expression
   *   The expression.
   * @param integer $timeout
   *   The timeout.
   *
   * @return array|null
   *   A partial talk.
   */
  public function generateVideoFromAudioAndImageSync($audioUrl, $imageUrl, $expression = 'neutral', $timeout = 600) {
    $time = time();
    $video = $this->generateVideoFromAudioAndImage($audioUrl, $imageUrl, $expression);
    if ($video) {
      // Try to get the result every 2 seconds.
      while (time() - $time < $timeout) {
        $result = $this->getTalk($video['id']);
        if (!empty($result['result_url'])) {
          return $result;
        }
        sleep(2);
      }
    }
    return NULL;
  }

  /**
   * Generate video from audio with an image.
   *
   * @param string $audioUrl
   *   The audio url.
   * @param string $imageUrl
   *   The image url.
   * @param string $expression
   *   The expression.
   *
   * @return array|null
   *   A partial talk.
   */
  public function generateVideoFromAudioAndImage($audioUrl, $imageUrl, $expression = 'neutral') {
    // Upload image.
    $image = $this->uploadImage($imageUrl);

    // Upload audio.
    $audio = $this->uploadAudio($audioUrl);

    // Generate video if all is ok.
    if (!empty($audio['url']) && !empty($image['url'])) {
      $result = $this->talksFromAudioImage($audio['url'], $image['url'], $expression);
      if (isset($result['id'])) {
        return $this->getTalk($result['id']);
      }
    }
    return NULL;
  }

  /**
   * Gets the presenters.
   *
   * @return array
   *   The presenters.
   */
  public function getPresenters() {
    return json_decode($this->makeRequest("clips/presenters", [], 'GET')->getContents(), TRUE);
  }

  /**
   * Get talks.
   *
   * @return array
   *   The talks.
   */
  public function getTalks() {
    return json_decode($this->makeRequest("talks", [], 'GET')->getContents(), TRUE);
  }

  /**
   * Get a specific talk.
   *
   * @param string $id
   *   The id.
   *
   * @return array
   *   The response.
   */
  public function getTalk($id) {
    return json_decode($this->makeRequest("talks/{$id}", [], 'GET')->getContents(), TRUE);
  }

  /**
   * Generate talks from audio and image.
   *
   * @param string $audioUrl
   *   The audio url.
   * @param string $imageUrl
   *   The image url.
   * @param string $expression
   *   The expression.
   *
   * @return array
   *   The response.
   */
  public function talksFromAudioImage($audioUrl, $imageUrl, $expression = 'neutral') {
    $body = [
      'source_url' => $imageUrl,
      'script' => [
        'type' => 'audio',
        'subtitles' => FALSE,
        'audio_url' => $audioUrl,
        'reduce_noise' => TRUE,
      ],
      'config' => [
        'stitch' => TRUE,
        'driver_expressions' => [
          'expressions' => [
            [
              'start_frame' => 0,
              'expression' => $expression,
              'intensity' => 1,
            ],
          ],
        ],
      ],
    ];
    $options['headers'] = [
      'Content-Type' => 'application/json',
    ];
    $result = json_decode($this->makeRequest("talks", [], 'POST', json_encode($body), $options), TRUE);
    return $result;
  }

  /**
   * Upload image to D-iD.
   *
   * @param string $imageUrl
   *   The image url.
   *
   * @return array
   *   The response.
   */
  public function uploadImage($imageUrl) {
    $imageUrl = $this->checkAndCreateTemporaryImage($imageUrl);
    $guzzleOptions['multipart'] = [
      [
        'name' => 'image',
        'contents' => fopen($imageUrl, 'r'),
        'filename' => $this->hashFilenameFromUrl($imageUrl),
      ],
    ];
    $result = json_decode($this->makeRequest("images", [], 'POST', NULL, $guzzleOptions), TRUE);

    return $result;
  }

  /**
   * Check and create a temporary image, if its too large.
   *
   * @param string $imageUrl
   *   The image url.
   *
   * @return string
   *   New or same url.
   */
  public function checkAndCreateTemporaryImage($imageUrl) {
    $size = getimagesize($imageUrl);
    $filesize = filesize($imageUrl);
    if ($size[0] > 1920 || $size[1] > 1080 || $filesize > 1000000) {
      $tmpFile = $this->fileSystem->getTempDirectory() . '/did.jpg';
      $data = file_get_contents($imageUrl);
      $im = imagecreatefromstring($data);
      imagejpeg($im, $tmpFile, 90);
      imagedestroy($im);
      return $tmpFile;
    }
    return $imageUrl;
  }

  /**
   * Upload audio to D-iD.
   *
   * @param string $audioUrl
   *   The audio url.
   *
   * @return array
   *   The response.
   */
  public function uploadAudio($audioUrl) {
    $guzzleOptions['multipart'] = [
      [
        'name' => 'audio',
        'contents' => fopen($audioUrl, 'r'),
        'filename' => $this->hashFilenameFromUrl($audioUrl),
      ],
    ];
    $result = json_decode($this->makeRequest("audios", [], 'POST', NULL, $guzzleOptions), TRUE);
    return $result;
  }

  /**
   * Because filenames needs to be short, we hash them with SHA-1.
   *
   * @param string $url
   *   The url.
   *
   * @return string
   *   The hashed filename.
   */
  public function hashFilenameFromUrl($url) {
    $ext = pathinfo($url, PATHINFO_EXTENSION);
    return sha1($url) . '.' . $ext;
  }

  /**
   * Make Did call.
   *
   * @param string $path
   *   The path.
   * @param array $query_string
   *   The query string.
   * @param string $method
   *   The method.
   * @param string $body
   *   Data to attach if POST/PUT/PATCH.
   * @param array $options
   *   Extra headers.
   *
   * @return string|object
   *   The return response.
   */
  protected function makeRequest($path, array $query_string = [], $method = 'GET', $body = '', array $options = []) {
    // We can wait long time since its video.
    $options['connect_timeout'] = 600;
    $options['read_timeout'] = 600;
    // Don't let Guzzle die, just forward body and status.
    $options['http_errors'] = FALSE;
    // Basic auth.
    $options['auth'] = explode(':', $this->apiKey);
    if ($body) {
      $options['body'] = $body;
    }

    $new_url = $this->basePath . $path;
    $new_url .= count($query_string) ? '?' . http_build_query($query_string) : '';

    $res = $this->client->request($method, $new_url, $options);

    return $res->getBody();
  }

}
