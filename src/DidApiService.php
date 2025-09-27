<?php

namespace Drupal\did_ai_provider;

use Drupal\Component\FileSystem\FileSystem;
use Drupal\did_ai_provider\Form\DidAiSettingsForm;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\File\FileSystemInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;

/**
 * D-ID API client.
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
   * API Key (the actual "username:password" string from the Key module).
   */
  private string $apiKey;

  /**
   * The base path.
   */
  private string $basePath = 'https://api.d-id.com/';

  public function __construct(
    Client $client,
    ConfigFactory $configFactory,
    FileSystemInterface $fileSystem,
    KeyRepositoryInterface $keyRepository
  ) {
    $this->client = $client;
    $this->fileSystem = $fileSystem;

    // Resolve configured key name to the real secret.
    $key_name = (string) ($configFactory->get('did_ai_provider.settings')->get('api_key') ?? '');
    $resolved = $key_name ? $keyRepository->getKey($key_name) : NULL;
    $this->apiKey = $resolved ? (string) $resolved->getKeyValue() : '';
    \Drupal::logger('did_ai_provider')->notice('DidApiService constructed with API key length: @len', [
      '@len' => strlen($this->apiKey),
    ]);
  }

  /**
   * Synchronous: audio + image → result_url.
   */
  public function generateVideoFromAudioAndImageSync($audioUrl, $imageUrl, $expression = 'neutral', $timeout = 600) {
    $time = time();
    $video = $this->generateVideoFromAudioAndImage($audioUrl, $imageUrl, $expression);
    if ($video) {
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
   * Async: audio + image → talk (poll later).
   */
  public function generateVideoFromAudioAndImage($audioUrl, $imageUrl, $expression = 'neutral') {
    $image = $this->uploadImage($imageUrl);
    $audio = $this->uploadAudio($audioUrl);

    if (!empty($audio['url']) && !empty($image['url'])) {
      $result = $this->talksFromAudioImage($audio['url'], $image['url'], $expression);
      if (isset($result['id'])) {
        return $this->getTalk($result['id']);
      }
    }
    return NULL;
  }
// DidApiService.php

public function generateVideoFromAudioAndPresenter(string $audioUrl, string $presenterId, string $expression = 'neutral'): ?array {
  // ⬇️ Upload audio first (local Drupal URIs won't be reachable by D-ID)
  $audio = $this->uploadAudio($audioUrl);
  if (empty($audio['url'])) {
    \Drupal::logger('did_ai_provider')->error('Audio upload failed for presenter flow. Local: @src', ['@src' => $audioUrl]);
    return NULL;
  }
  $talk = $this->talksFromAudioPresenter($audio['url'], $presenterId, $expression);
  return $talk ? $this->getTalk($talk['id'] ?? '') : NULL;
}

public function generateVideoFromAudioAndPresenterSync(string $audioUrl, string $presenterId, string $expression = 'neutral', int $timeout = 600): ?array {
  // ⬇️ Upload audio first
  $audio = $this->uploadAudio($audioUrl);
  if (empty($audio['url'])) {
    \Drupal::logger('did_ai_provider')->error('Audio upload failed for presenter sync flow. Local: @src', ['@src' => $audioUrl]);
    return NULL;
  }
  $time = time();
  $talk = $this->talksFromAudioPresenter($audio['url'], $presenterId, $expression);
  if ($talk && !empty($talk['id'])) {
    while (time() - $time < $timeout) {
      $result = $this->getTalk($talk['id']);
      if (!empty($result['result_url'])) {
        return $result;
      }
      sleep(2);
    }
  }
  return NULL;
}


public function isValidPresenterId(string $presenterId): bool {
  if ($presenterId === '') {
    return false;
  }
  $map = $this->getPresenterMap();
  return isset($map[$presenterId]);
}

public function getPresenterMap(): array {
  $cache = \Drupal::cache();
  $cid = 'did_ai_provider:presenters_map';
  if ($cached = $cache->get($cid)) {
    return $cached->data;
  }
  $map = [];
  try {
    $res = $this->getPresenters();
    foreach (($res['presenters'] ?? []) as $p) {
      if (!empty($p['presenter_id'])) {
        $map[$p['presenter_id']] = $p;
      }
    }
  } catch (\Throwable $e) {
    \Drupal::logger('did_ai_provider')->warning('Failed to fetch presenters: @m', ['@m' => $e->getMessage()]);
  }
  // Cache for 30 minutes.
  $cache->set($cid, $map, \Drupal::time()->getRequestTime() + 1800);
  return $map;
}

  /**
   * Presenters list.
   */
  public function getPresenters() {
    return json_decode($this->makeRequest("clips/presenters", [], 'GET'), TRUE);
  }

  public function getTalks() {
    return json_decode($this->makeRequest("talks", [], 'GET'), TRUE);
  }

  public function getTalk($id) {
    return json_decode($this->makeRequest("talks/{$id}", [], 'GET'), TRUE);
  }

  /**
   * POST /talks from audio+image.
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
          'expressions' => [[
            'start_frame' => 0,
            'expression' => $expression,
            'intensity'  => 1,
          ]],
        ],
      ],
    ];
    $options['headers'] = ['Content-Type' => 'application/json'];
    return json_decode($this->makeRequest("talks", [], 'POST', json_encode($body), $options), TRUE);
  }

  /**
   * NEW: POST /talks from audio+presenter.
   */
  public function talksFromAudioPresenter(string $audioUrl, string $presenterId, string $expression = 'neutral'): array {
    $body = [
      'presenter_id' => $presenterId,
      'script' => [
        'type' => 'audio',
        'subtitles' => FALSE,
        'audio_url' => $audioUrl,
        'reduce_noise' => TRUE,
      ],
      'config' => [
        'stitch' => TRUE,
        'driver_expressions' => [
          'expressions' => [[
            'start_frame' => 0,
            'expression' => $expression,
            'intensity'  => 1,
          ]],
        ],
      ],
    ];
    $options['headers'] = ['Content-Type' => 'application/json'];
    return json_decode($this->makeRequest("talks", [], 'POST', json_encode($body), $options), TRUE);
  }
  public function getHttpClient(): Client {
    return $this->client;
  }

  /**
   * Upload image to D-ID.
   */
  public function uploadImage($imageUrl) {
    $imagePath = $this->checkAndCreateTemporaryImage($imageUrl);
    $imagePath = $this->realOpenPath($imagePath);
    $guzzleOptions['multipart'] = [[
      'name' => 'image',
      'contents' => fopen($imagePath, 'r'),
      'filename' => $this->hashFilenameFromUrl($imagePath),
    ]];
    return json_decode($this->makeRequest("images", [], 'POST', '', $guzzleOptions), TRUE);
  }

  protected function realOpenPath(string $uriOrUrl): string {
    $scheme = parse_url($uriOrUrl, PHP_URL_SCHEME);
    if (in_array($scheme, ['public', 'private', 'temporary'], true)) {
      $real = $this->fileSystem->realpath($uriOrUrl);
      return $real ?: $uriOrUrl;
    }
    return $uriOrUrl;
  }

  public function checkAndCreateTemporaryImage($imageUrl) {
    $scheme = parse_url($imageUrl, PHP_URL_SCHEME);

    if (in_array($scheme, ['http', 'https'], true)) {
      $data = @file_get_contents($imageUrl);
      if ($data === false) {
        return $imageUrl;
      }
      $im = @imagecreatefromstring($data);
      if (!$im) {
        return $imageUrl;
      }
      $w = imagesx($im);
      $h = imagesy($im);
      $needs_resize = ($w > 1920 || $h > 1080 || strlen($data) > 1000000);
      if ($needs_resize) {
        $tmpFile = $this->fileSystem->getTempDirectory() . '/did.jpg';
        imagejpeg($im, $tmpFile, 90);
        imagedestroy($im);
        return $tmpFile;
      }
      imagedestroy($im);
      return $imageUrl;
    }

    $path = $this->realOpenPath($imageUrl);
    $size = @getimagesize($path);
    $filesize = @filesize($path);
    if (!$size || $filesize === false) {
      return $path;
    }
    if ($size[0] > 1920 || $size[1] > 1080 || $filesize > 1000000) {
      $data = @file_get_contents($path);
      if ($data === false) {
        return $path;
      }
      $im = @imagecreatefromstring($data);
      if (!$im) {
        return $path;
      }
      $tmpFile = $this->fileSystem->getTempDirectory() . '/did.jpg';
      imagejpeg($im, $tmpFile, 90);
      imagedestroy($im);
      return $tmpFile;
    }
    return $path;
  }

  /**
   * Upload audio to D-ID.
   */
  public function uploadAudio($audioUrl) {
    $audioPath = $this->realOpenPath($audioUrl);
    $guzzleOptions['multipart'] = [[
      'name' => 'audio',
      'contents' => fopen($audioPath, 'r'),
      'filename' => $this->hashFilenameFromUrl($audioPath),
    ]];
    return json_decode($this->makeRequest("audios", [], 'POST', '', $guzzleOptions), TRUE);
  }

  public function hashFilenameFromUrl($url) {
    $ext = pathinfo($url, PATHINFO_EXTENSION);
    return sha1($url) . '.' . $ext;
  }

  /**
   * Low-level HTTP wrapper.
   *
   * @return string Response body as string.
   */
  protected function makeRequest($path, array $query_string = [], $method = 'GET', $body = '', array $options = []) {
    // Long timeouts (video).
    $options['connect_timeout'] = 600;
    $options['read_timeout'] = 600;
    $options['http_errors'] = FALSE;
    $options['auth'] = explode(':', $this->apiKey);

    if ($body !== '') {
      $options['body'] = $body;
    }

    $url = $this->basePath . $path;
    if ($query_string) {
      $url .= '?' . http_build_query($query_string);
    }

    $res = $this->client->request($method, $url, $options);
    return (string) $res->getBody();
  }
// POST /clips using an uploaded audio URL + presenter_id
public function clipsFromAudioPresenter(
  string $audioUploadUrl,
  string $presenterId,
  string $expression = 'neutral'
): array {
  $body = [
    'presenter_id' => $presenterId,
    'script' => [
      'type' => 'audio',
      'audio_url' => $audioUploadUrl,
      'subtitles' => FALSE,
      'reduce_noise' => TRUE,
    ],
    'config' => [
      'stitch' => TRUE,
      'driver_expressions' => [
        'expressions' => [[
          'start_frame' => 0,
          'expression'  => $expression,
          'intensity'   => 1,
        ]],
      ],
    ],
  ];
  $opts['headers'] = ['Content-Type' => 'application/json'];
  $resp = $this->makeRequest('clips', [], 'POST', json_encode($body), $opts);
  return json_decode($resp, TRUE) ?? [];
}

// GET /clips/{id}
public function getClip(string $id): array {
  $resp = $this->makeRequest("clips/{$id}", [], 'GET');
  return json_decode($resp, TRUE) ?? [];
}
}