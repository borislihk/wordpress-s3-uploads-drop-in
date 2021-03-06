<?php
/*
  Plugin Name: S3 Uploads DropIn
  Version: 1.8.4
*/

if ( ! defined( 'ABSPATH' ) ) exit;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;

add_action('plugins_loaded', [S3Uploads::get_instance(), 'init']);

// A little singleton
class S3Uploads 
{
  protected function __construct() { }
  protected function __wakeup() { }
  protected function __clone() { }

  public static function get_instance()
  {
    static $instance = null;
    if ($instance === null) {
      $instance = new static();
    }
    return $instance;
  }

  private $s3Client;

  public function init()
  {
    $this->initS3Client();

		// media library
    add_filter('upload_dir', [$this, 'filterUploadDir']);
    add_filter('wp_generate_attachment_metadata', [$this, 'onUpdatedAttachment'] , 10, 2);
    add_action('delete_attachment', [$this, 'onBeforeDeleted']);

		// bb-plugin
		add_action('fl_builder_after_save_layout', [$this, 'uploadBuilderCaches'], 10, 1);
		add_action('fl_builder_after_save_draft', [$this, 'uploadBuilderCaches'], 10, 1);
		add_action('render_node', [$this, 'uploadBuilderCaches']);
		add_action('save_post', [$this, 'uploadBuilderCaches'], 10, 1);
  }

  private function initS3Client()
  {
    $config = [
      'region' => getenv('AWS_S3_REGION'),
      'version' => 'latest'
    ];

    if (getenv('AWS_ACCESS_KEY_ID') && getenv('AWS_SECRET_ACCESS_KEY'))
    {
      $config = array_merge($config, [
        'credentials' => [
          'key' => getenv('AWS_ACCESS_KEY_ID'),
          'secret' => getenv('AWS_SECRET_ACCESS_KEY')
        ]
      ]);
    }
    $this->s3Client = S3Client::factory($config);
  }

  public function filterUploadDir($dirs)
  {
    $dirs['url'] = getenv('AWS_S3_BUCKET_URL') . $dirs['subdir'];
    $dirs['baseurl'] = getenv('AWS_S3_BUCKET_URL');
    return $dirs;
  }

  public function onUpdatedAttachment($attachmentData, $attachmentId)
  {
    $attachments = array_unique(array_values(array_merge(
      [$this->attachmentMainPath($attachmentId)],
			$this->attachmentOtherPaths($attachmentId)
    )));

    foreach($attachments as $attachment)
    {
      $this->uploadToS3($attachment);
      @unlink($attachment);
    }

    return $attachmentData;
  }

  public function onBeforeDeleted($attachmentId)
  {
    $attachments = array_map(function($attachment) 
    {
      $seperators = explode('uploads', $attachment);
      return getenv('AWS_S3_PATH') . end($seperators);
    }, array_merge(
      [$this->attachmentMainPath($attachmentId)],
      $this->attachmentOtherPaths($attachmentId)
    ));
    
    $this->s3Client->deleteObjects([
      'Bucket' => getenv('AWS_S3_BUCKET'),
      'Delete' => [
        'Objects' => array_map(function($key) 
        {
            return ['Key' => $key];
        }, $attachments)
      ]
    ]);
  }

  public function uploadToS3($path)
  {
    $source = fopen($path, 'rb');
    $path_parts = explode('uploads', $path);
    $key =  getenv('AWS_S3_PATH') . end($path_parts);
    $uploader = new ObjectUploader(
      $this->s3Client,
      getenv('AWS_S3_BUCKET'),
      $key,
      $source
    );
    $uploader->upload();
  }

  private function attachmentLocation($attachmentId)
  {
		return pathinfo($this->attachmentMainPath($attachmentId))['dirname'];
    //$attachmentMainPath = $this->attachmentMainPath($attachmentId);
    //$pathInfo = pathinfo($attachmentMainPath);
    //$dirname = explode('/', $pathInfo['dirname']);
    //$year = $dirname[count($dirname) - 2];
    //$month = $dirname[count($dirname) - 1];
    //return "uploads/$year/$month";
  }

  private function attachmentMainPath($attachmentId)
  {
    return get_attached_file($attachmentId);
  }

  private function attachmentOtherPaths($attachmentId)
  {
    return array_filter(array_map(function($size) use($attachmentId) 
    {
      $sizeInfo = image_get_intermediate_size($attachmentId, $size);
      $attachmentLocation = $this->attachmentLocation($attachmentId);
      return isset($sizeInfo['file']) ? $attachmentLocation . '/' . $sizeInfo['file'] : null;
    }, $this->sizes($attachmentId)));
  }

  private function attachmentPath($sizeInfo)
  {
    return isset($sizeInfo['file']) ? wp_get_upload_dir()['path'] . '/' . $sizeInfo['file'] : null;
  }

  private function sizes($attachmentId)
  {
    return array_keys(wp_get_attachment_metadata($attachmentId)['sizes'] ?? []); 
  }

	public function uploadBuilderCaches($post_id)
	{
		if (empty($post_id)) return;
		$cache_dir = 'bb-plugin/cache';
		$path = wp_upload_dir()['basedir'] . "/$cache_dir/";
		$prefix = $post_id . '-layout';
		$suffix = [
			'.css',
			'.js',
			'-partial.css',
			'-partial.js',
			'-draft.css',
			'-draft.js',
			'-draft-partial.css',
			'-draft-partial.js',
			'-preview.css'
		];
		$caches = array_map(
			function ($suffix) use ($path, $prefix)
			{
				$cache_file = $path . $prefix . $suffix;
				// create preview from draft
				if (!file_exists($cache_file) && $suffix == 'preview.css' && file_exists($path . $prefix . '-draft.css'))
				{
					copy($path . $prefix . '-draft.css', $cache_file);
				}
				return $cache_file;
			},
			$suffix
		);
		foreach ($cache_files as $cache_file)
		{
			if (file_exists($cache_file))
			{
				// upload to s3
				$this->uploadToS3($cache_file);
			}
		}
	}

	private function log_to_file($msg)
	{
		file_put_contents('uploader.log', $msg, FILE_APPEND);
	}
}
