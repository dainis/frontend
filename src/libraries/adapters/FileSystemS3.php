<?php

use Aws\S3\S3Client;


/**
 * Amazon AWS S3 implementation for FileSystemInterface
 *
 * This class defines the functionality defined by FileSystemInterface for AWS S3.
 * @author Jaisen Mathai <jaisen@jmathai.com>
 */
class FileSystemS3 implements FileSystemInterface
{
  /**
    * Member variables holding the names to the bucket and the file system object itself.
    * @access private
    * @var array
    */
  const uploadTypeAttach = 'attachment';
  const uploadTypeInline = 'inline';
  private $bucket, $config, $fs, $uploadType = self::uploadTypeAttach;
  protected $storeThumbs = true;

  /**
    * Constructor
    *
    * @return void
    */
  public function __construct($config = null, $params = null)
  {
    $this->config = !is_null($config) ? $config : getConfig()->get();

    if(!is_null($params) && isset($params['fs']))
    {
      $this->fs = $params['fs'];
    }
    else
    {
      $utilityObj = new Utility;
      $this->fs = new S3Client([
        'credentials' => [
          'key' => $utilityObj->decrypt($this->config->credentials->awsKey),
          'secret' => $utilityObj->decrypt($this->config->credentials->awsSecret)
        ],
        'region' => 'eu-central-1',
        'version' => 'latest'
      ]);
    }

    $this->bucket = $this->config->aws->s3BucketName;
  }

  /**
    * Deletes a photo (and all generated versions) from the file system.
    * To get a list of all the files to delete we first have to query the database and find out what versions exist.
    *
    * @param string $id ID of the photo to delete
    * @return boolean
    */
  public function deletePhoto($photo)
  {
    $objects = [];

    foreach($photo as $key => $value)
    {
      if(strncmp($key, 'path', 4) === 0)
      {
        $objects[] = ['Key' => $this->normalizePath($value)];
      }
    }

    $response = $this->fs->deleteObjects([
      'Bucket' => $this->bucket,
      'Delete' => [
        'Objects' => $objects
      ]
    ]);

    return empty($response['Errors']);
  }

  public function downloadPhoto($photo)
  {
    $fp = fopen($photo['pathOriginal'], 'r');
    return $fp;
  }

  /**
    * Gets diagnostic information for debugging.
    *
    * @return array
    */
  public function diagnostics()
  {
    $utilityObj = new Utility;
    $diagnostics = array();
    $exists = $this->fs->doesBucketExist($this->bucket);

    if($exists)
    {
      $storageSize = $this->fs->get_bucket_filesize($this->bucket, true);
      $diagnostics[] = $utilityObj->diagnosticLine(true, sprintf('Connection to bucket "%s" is okay.', $this->bucket));
    }
    else
    {
      $diagnostics[] = $utilityObj->diagnosticLine(false, sprintf('Connection to bucket "%s" is NOT okay.', $this->bucket));
    }
    return $diagnostics;
  }

  /**
    * Executes an upgrade script
    *
    * @return void
    */
  public function executeScript($file, $filesystem)
  {
    if($filesystem != 's3')
      return;

    $status = include $file;
    return $status;
  }

  /**
    * Retrieves a photo from the remote file system as specified by $filename.
    * This file is stored locally and the path to the local file is returned.
    *
    * @param string $filename File name on the remote file system.
    * @return mixed String on success, FALSE on failure.
    */
  public function getPhoto($filename)
  {
    $filename = $this->normalizePath($filename);
    $tmpname = '/tmp/'.uniqid('opme', true);
    try
    {
      $this->fs->getObject([
        'Bucket' => $this->bucket,
        'Key' => $filename,
        'SaveAs' => $tmpname
      ]);
      return $tmpname;
    } catch(Exception $e)
    {
      getLogger()->warn("The photo {$filename} could not be downloaded {$e}");
      return false;
    }
  }

  /**
    * Allows injection of member variables.
    * Primarily used for unit testing with mock objects.
    *
    * @param string $name Name of the member variable
    * @param mixed $value Value of the member variable
    * @return void
    */
  public function inject($name, $value)
  {
    $this->$name = $value;
  }

  // TODO Gh-420 the $acl should be moved into a config and not exist in the signature
  /**
    * Writes/uploads a new photo to the remote file system.
    *
    * @param string $localFile File name on the local file system.
    * @param string $remoteFile File name to be saved on the remote file system.
    * @param string $acl Permission setting for this photo.
    * @return boolean
    */
  public function putPhoto($localFile, $remoteFile, $dateTaken)
  {
    $acl = 'public-read';
    if(!file_exists($localFile))
    {
      getLogger()->warn("The photo {$localFile} does not exist so putPhoto failed");
      return false;
    }

    if($this->storeThumbs === false && strpos($remoteFile, '/original/') !== false)
    {
      return true;
    }

    $remoteFile = $this->normalizePath($remoteFile);
    $opts = $this->getUploadOpts($localFile, $acl);

    $opts['Key'] = $remoteFile;

    try {
      $this->fs->PutObject($opts);
      return true;
    } catch(Exception $e)
    {
      getLogger()->crit('Could not put photo on the file system: ' . $e);
      return false;
    }
  }

  /**
    * Writes/uploads new photos in bulk and in parallel to the remote file system.
    *
    * @param array $files Array where each row represents a file with the key being the local file name and the value being the remote.
    *   [{"/path/to/local/file.jpg": "/path/to/save/on/remote.jpg"}...]
    * @param string $remoteFile File name to be saved on the remote file system.
    * @param string $acl Permission setting for this photo.
    * @return boolean
    */
  public function putPhotos($files)
  {
    $allOk = true;
    foreach($files as $file)
    {
      list($localFile, $remoteFileArr) = each($file);
      $remoteFile = $remoteFileArr[0];
      $dateTaken = $remoteFileArr[1];
      $remoteFile = $this->normalizePath($remoteFile);

      $allOk = $this->putPhoto($localFile, $remoteFile, $dateTaken) || $allOk;
    }

    return $allOk;
  }

  /**
    * Gets a CFBatchRequest object for the AWS library
    *
    * @return object
   */
  public function getBatchRequest()
  {
    return new CFBatchRequest();
  }

  /**
    * Get the hostname for the remote filesystem to be used in constructing public URLs.
    * @return string
    */
  public function getHost()
  {
    return $this->config->aws->s3Host;
  }

  /**
    * Return any meta data which needs to be stored in the photo record
    * @return array
    */
  public function getMetaData($localFile)
  {
    return array();
  }

  /**
    * Initialize the remote file system by creating buckets and setting permissions and settings.
    * This is called from the Setup controller.
    * @return boolean
    */
  public function initialize($isEditMode)
  {
    getLogger()->info('Initializing file system');
    if(!$this->fs->isBucketDnsCompatible($this->bucket))
    {
      getLogger()->warn("The bucket name you provided ({$this->bucket}) is invalid.");
      return false;
    }

    $exists = $this->fs->doesBucketExist($this->bucket);

    if(!$exists)
    {
      getLogger()->info("Bucket {$this->bucket} does not exist, creating it now");
      try
      {
        $res = $this->fs->createBucket([
          'Bucket' => $this->bucket,
          'ACL' => 'private'
        ]);
      } catch (Exception $e)
      {
        getLogger()->crit('Could not create S3 bucket: ' . $e);
        return false;
      }
    }

    $policy =json_encode([
      'Version' => '2008-10-17',
      'Statement' => array(
          array(
              'Sid' => 'AddPerm',
              'Effect' => 'Allow',
              'Principal' => array(
                  'AWS' => '*'
              ),
              'Action' => array('s3:*'),
              'Resource' => array("arn:aws:s3:::{$this->bucket}/*")
          )
      )
    ]);

    try
    {
      $res = $this->fs->PutBucketPolicy([
        'Bucket' => $this->bucket,
        'Policy' => $policy
      ]);
    } catch(Exception $e)
    {
      getLogger()->crit('Failed to set bucket policy' . $e);
    }

    return true;
  }

  /**
    * Identification method to return array of strings.
    *
    * @return array
    */
  public function identity()
  {
    return array('s3');
  }

  /**
    * Removes leading slashes since it's not needed when putting new files on S3.
    *
    * @param string $path Path of the photo to have leading slashes removed.
    * @return string
   */
  public function normalizePath($path)
  {
    return preg_replace('/^\/+/', '', $path);
  }

  public function setHostname($hostname)
  {
    $this->fs->set_hostname($hostname);
    $this->fs->allow_hostname_override(false);
    $this->fs->enable_path_style();
  }

  public function setSSL($bool)
  {}

  public function setUploadType($type)
  {
    $this->uploadType = $type;
  }

  public function getUploadOpts($localFile, $acl)
  {
    $opts = [
      'ACL' => $acl,
      'ContentType' => 'image/jpeg',
      'Bucket' => $this->bucket
    ];

    if($this->uploadType === self::uploadTypeAttach)
    {
      $opts['SourceFile'] = $localFile;
    }
    elseif($this->uploadType === self::uploadTypeInline)
    {
      $opts['Body'] = file_get_contents($localFile);
    }

    return $opts;
  }
}
