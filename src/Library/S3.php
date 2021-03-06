<?php
declare(strict_types=1);

namespace Npf\Library;

/**
 * $Id$
 *
 * Copyright (c) 2013, Donovan Schönknecht.  All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * - Redistributions of source code must retain the above copyright notice,
 *   this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright
 *   notice, this list of conditions and the following disclaimer in the
 *   documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * Amazon S3 is a trademark of Amazon.com, Inc. or its affiliates.
 */

use DOMDocument;
use JetBrains\PhpStorm\Pure;
use Npf\Exception\InternalError;
use OpenSSLAsymmetricKey;
use SimpleXMLElement;
use stdClass;

/**
 * Amazon S3 PHP class
 *
 * @link http://undesigned.org.za/2007/10/22/amazon-s3-php-class
 * @version 0.5.1
 */
class S3
{
    // ACL flags
    const ACL_PRIVATE = 'private';
    const ACL_PUBLIC_READ = 'public-read';
    const ACL_PUBLIC_READ_WRITE = 'public-read-write';
    const ACL_AUTHENTICATED_READ = 'authenticated-read';
    const STORAGE_CLASS_STANDARD = 'STANDARD';
    const STORAGE_CLASS_RRS = 'REDUCED_REDUNDANCY';
    const STORAGE_CLASS_STANDARD_IA = 'STANDARD_IA';
    const SSE_NONE = '';
    /**
     * Default delimiter to be used, for example while getBucket().
     * @var string|null
     * @access public
     * @static
     */
    public static string|null $defDelimiter = null;
    /**
     * AWS URI
     *
     * @var string
     * @acess public
     * @static
     */
    public static string $endpoint = 's3.amazonaws.com';
    /**
     * Proxy information
     *
     * @var null|array
     * @access public
     * @static
     */
    public static ?array $proxy;
    /**
     * Connect using SSL?
     *
     * @var bool
     * @access public
     * @static
     */
    public static bool $useSSL;
    /**
     * Use SSL validation?
     *
     * @var bool
     * @access public
     * @static
     */
    public static bool $useSSLValidation = true;
    /**
     * Use SSL version
     *
     * @access public
     * @static
     */
    public static int $useSSLVersion = CURL_SSLVERSION_TLSv1;
    /**
     * Use PHP exceptions?
     *
     * @var bool
     * @access public
     * @static
     */
    public static bool $useExceptions;
    /**
     * SSL client key
     *
     * @var bool
     * @access public
     * @static
     */
    public static ?bool $sslKey = null;
    /**
     * SSL client certfificate
     *
     * @var string|null
     * @acess public
     * @static
     */
    public static ?string $sslCert = null;
    /**
     * SSL CA cert (only required if you are having problems with your system CA cert)
     *
     * @var string|null
     * @access public
     * @static
     */
    public static ?string $sslCACert = null;
    /**
     * The AWS Access key
     *
     * @var string|null
     * @access private
     * @static
     */
    private static ?string $__accessKey = null;
    /**
     * AWS Secret Key
     *
     * @var string|null
     * @access private
     * @static
     */
    private static ?string $__secretKey = null;
    /**
     * Time offset applied to time()
     * @var int
     * @access private
     * @static
     */
    private static int $__timeOffset = 0;
    /**
     * AWS Key Pair ID
     *
     * @var string|null
     * @access private
     * @static
     */
    private static ?string $__signingKeyPairId = null;

    /**
     * Key resource, freeSigningKey() must be called to clear it from memory
     *
     * @access private
     * @static
     */
    private static ?OpenSSLAsymmetricKey $__signingKeyResource;

    /**
     * Constructor - if you're not using the class statically
     *
     * @param string|null $accessKey Access key
     * @param string|null $secretKey Secret key
     * @param boolean $useSSL Enable SSL
     * @param string $endpoint Amazon URI
     */
    public function __construct(?string $accessKey = null,
                                ?string $secretKey = null,
                                bool $useSSL = false,
                                string $endpoint = 's3.amazonaws.com')
    {
        if ($accessKey !== null && $secretKey !== null)
            self::setAuth($accessKey, $secretKey);
        self::$useSSL = $useSSL;
        self::$endpoint = $endpoint;
    }

    /**
     * Set AWS access key and secret key
     *
     * @param string $accessKey Access key
     * @param string $secretKey Secret key
     * @return void
     */
    public static function setAuth(string $accessKey, string $secretKey)
    {
        self::$__accessKey = $accessKey;
        self::$__secretKey = $secretKey;
    }

    /**
     * Check if AWS keys have been set
     *
     * @return boolean
     */
    public static function hasAuth(): bool
    {
        return (self::$__accessKey !== null && self::$__secretKey !== null);
    }

    /**
     * Set SSL on or off
     *
     * @param boolean $enabled SSL enabled
     * @param boolean $validate SSL certificate validation
     * @return void
     */
    public static function setSSL(bool $enabled, bool $validate = true)
    {
        self::$useSSL = $enabled;
        self::$useSSLValidation = $validate;
    }

    /**
     * Set SSL client certificates (experimental)
     *
     * @param string|null $sslCert SSL client certificate
     * @param string|null $sslKey SSL client key
     * @param string|null $sslCACert SSL CA cert (only required if you are having problems with your system CA cert)
     * @return void
     */
    public static function setSSLAuth(?string $sslCert = null,
                                      ?string $sslKey = null,
                                      ?string $sslCACert = null)
    {
        self::$sslCert = $sslCert;
        self::$sslKey = $sslKey;
        self::$sslCACert = $sslCACert;
    }

    /**
     * Set proxy information
     *
     * @param string $host Proxy hostname and port (localhost:1234)
     * @param string|null $user Proxy username
     * @param string|null $pass Proxy password
     * @param int $type CURL proxy type
     * @return void
     */
    public static function setProxy(string $host,
                                    ?string $user = null,
                                    ?string $pass = null,
                                    int $type = CURLPROXY_SOCKS5)
    {
        self::$proxy = ['host' => $host, 'type' => $type, 'user' => $user, 'pass' => $pass];
    }

    /**
     * Set the error mode to exceptions
     *
     * @param bool $enabled Enable exceptions
     * @return void
     */
    public static function setExceptions(bool $enabled = true)
    {
        self::$useExceptions = $enabled;
    }

    /**
     * Set AWS time correction offset (use carefully)
     *
     * This can be used when an inaccurate system time is generating
     * invalid request signatures.  It should only be used as a last
     * resort when the system time cannot be changed.
     *
     * @param int $offset Time offset (set to zero to use AWS server time)
     * @return void
     */
    public static function setTimeCorrectionOffset(int $offset = 0)
    {
        if ($offset == 0) {
            $rest = new S3Request('HEAD');
            $rest = $rest->getResponse();
            $awstime = $rest->headers['date'];
            $systime = time();
            $offset = $systime > $awstime ? -($systime - $awstime) : ($awstime - $systime);
        }
        self::$__timeOffset = $offset;
    }

    /**
     * Set signing key
     *
     * @param string $keyPairId AWS Key Pair ID
     * @param string $signingKey Private Key
     * @param boolean $isFile Load private key from file, set to false to load string
     * @return boolean
     * @throws InternalError
     */
    public static function setSigningKey(string $keyPairId,
                                         string $signingKey,
                                         bool $isFile = true): bool
    {
        self::$__signingKeyPairId = $keyPairId;
        if ((self::$__signingKeyResource = openssl_pkey_get_private($isFile ?
                file_get_contents($signingKey) : $signingKey)) !== false) return true;
        self::__triggerError('S3::setSigningKey(): Unable to open load private key: ' . $signingKey);
        return false;
    }

    /**
     * Internal error handler
     *
     * @param string|null $message Error message
     * @return void
     * @throws InternalError
     * @internal Internal error handler
     */
    private static function __triggerError(?string $message)
    {
        if (self::$useExceptions)
            throw new InternalError($message);
        else
            trigger_error($message, E_USER_WARNING);
    }

    /**
     * Get a list of buckets
     *
     * @param boolean $detailed Returns detailed bucket list when true
     * @return array | false
     * @throws InternalError
     */
    public static function listBuckets(bool $detailed = false): bool|array
    {
        $rest = new S3Request('GET', '', '', self::$endpoint);
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::listBuckets(): [%s] %s", $rest->error['code'],
                $rest->error['message']));
            return false;
        }
        $results = [];
        if (!isset($rest->body->Buckets)) return $results;
        if ($detailed) {
            if (isset($rest->body->Owner, $rest->body->Owner->ID, $rest->body->Owner->DisplayName))
                $results['owner'] = [
                    'id' => (string)$rest->body->Owner->ID, 'name' => (string)$rest->body->Owner->DisplayName
                ];
            $results['buckets'] = [];
            foreach ($rest->body->Buckets->Bucket as $b)
                $results['buckets'][] = [
                    'name' => (string)$b->Name, 'time' => strtotime((string)$b->CreationDate)
                ];
        } else
            foreach ($rest->body->Buckets->Bucket as $b) $results[] = (string)$b->Name;
        return $results;
    }

    /**
     * Get contents for a bucket
     *
     * If maxKeys is null this method will loop through truncated result sets
     *
     * @param string $bucket Bucket name
     * @param string|null $prefix Prefix
     * @param string|null $marker Marker (last file listed)
     * @param string|null $maxKeys Max keys (maximum number of keys to return)
     * @param string|null $delimiter Delimiter
     * @param boolean $returnCommonPrefixes Set to true to return CommonPrefixes
     * @return array | false
     * @throws InternalError
     */
    public static function getBucket(string $bucket,
                                     ?string $prefix = null,
                                     ?string $marker = null,
                                     ?string $maxKeys = null,
                                     ?string $delimiter = null,
                                     bool $returnCommonPrefixes = false): bool|array
    {
        $rest = new S3Request('GET', $bucket, '', self::$endpoint);
        if ($maxKeys == 0) $maxKeys = null;
        if ($prefix !== null && $prefix !== '') $rest->setParameter('prefix', $prefix);
        if ($marker !== null && $marker !== '') $rest->setParameter('marker', $marker);
        if ($maxKeys !== null && $maxKeys !== '') $rest->setParameter('max-keys', $maxKeys);
        if ($delimiter !== null && $delimiter !== '') $rest->setParameter('delimiter', $delimiter);
        else if (!empty(self::$defDelimiter)) $rest->setParameter('delimiter', self::$defDelimiter);
        $response = $rest->getResponse();
        if ($response->error === false && $response->code !== 200)
            $response->error = ['code' => $response->code, 'message' => 'Unexpected HTTP status'];
        if ($response->error !== false) {
            self::__triggerError(sprintf("S3::getBucket(): [%s] %s",
                $response->error['code'], $response->error['message']));
            return false;
        }
        $results = [];
        $nextMarker = null;
        if (isset($response->body, $response->body->Contents))
            foreach ($response->body->Contents as $c) {
                $results[(string)$c->Key] = [
                    'name' => (string)$c->Key,
                    'time' => strtotime((string)$c->LastModified),
                    'size' => (int)$c->Size,
                    'hash' => substr((string)$c->ETag, 1, -1)
                ];
                $nextMarker = (string)$c->Key;
            }
        if ($returnCommonPrefixes && isset($response->body, $response->body->CommonPrefixes))
            foreach ($response->body->CommonPrefixes as $c)
                $results[(string)$c->Prefix] = ['prefix' => (string)$c->Prefix];
        if (isset($response->body, $response->body->IsTruncated) &&
            (string)$response->body->IsTruncated == 'false') return $results;
        if (isset($response->body, $response->body->NextMarker))
            $nextMarker = (string)$response->body->NextMarker;
        // Loop through truncated results if maxKeys isn't specified
        if ($maxKeys == null && $nextMarker !== null && (string)$response->body->IsTruncated == 'true')
            do {
                $rest = new S3Request('GET', $bucket, '', self::$endpoint);
                if ($prefix !== null && $prefix !== '') $rest->setParameter('prefix', $prefix);
                $rest->setParameter('marker', $nextMarker);
                if ($delimiter !== null && $delimiter !== '') $rest->setParameter('delimiter', $delimiter);
                if (($response = $rest->getResponse()) == false || $response->code !== 200) break;
                if (isset($response->body, $response->body->Contents))
                    foreach ($response->body->Contents as $c) {
                        $results[(string)$c->Key] = [
                            'name' => (string)$c->Key,
                            'time' => strtotime((string)$c->LastModified),
                            'size' => (int)$c->Size,
                            'hash' => substr((string)$c->ETag, 1, -1)
                        ];
                        $nextMarker = (string)$c->Key;
                    }
                if ($returnCommonPrefixes && isset($response->body, $response->body->CommonPrefixes))
                    foreach ($response->body->CommonPrefixes as $c)
                        $results[(string)$c->Prefix] = ['prefix' => (string)$c->Prefix];
                if (isset($response->body, $response->body->NextMarker))
                    $nextMarker = (string)$response->body->NextMarker;
            } while ($response !== false && (string)$response->body->IsTruncated == 'true');
        return $results;
    }

    /**
     * Put a bucket
     *
     * @param string $bucket Bucket name
     * @param string $acl ACL flag
     * @param bool $location Set as "EU" to create buckets hosted in Europe
     * @return boolean
     * @throws InternalError
     */
    public static function putBucket(string $bucket,
                                     string $acl = self::ACL_PRIVATE,
                                     bool $location = false): bool
    {
        $rest = new S3Request('PUT', $bucket, '', self::$endpoint);
        $rest->setAmzHeader('x-amz-acl', $acl);
        if ($location !== false) {
            $dom = new DOMDocument;
            $createBucketConfiguration = $dom->createElement('CreateBucketConfiguration');
            $locationConstraint = $dom->createElement('LocationConstraint', $location);
            $createBucketConfiguration->appendChild($locationConstraint);
            $dom->appendChild($createBucketConfiguration);
            $rest->data = $dom->saveXML();
            $rest->size = strlen($rest->data);
            $rest->setHeader('Content-Type', 'application/xml');
        }
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::putBucket({$bucket}, {$acl}, {$location}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return true;
    }

    /**
     * Delete an empty bucket
     *
     * @param string $bucket Bucket name
     * @return boolean
     * @throws InternalError
     */
    public static function deleteBucket(string $bucket): bool
    {
        $rest = new S3Request('DELETE', $bucket, '', self::$endpoint);
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 204)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::deleteBucket({$bucket}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return true;
    }

    /**
     * Create input array info for putObject() with a resource
     *
     * @param mixed $resource $resource $resource Input resource to read from
     * @param bool $bufferSize Input byte size
     * @param string $md5sum MD5 hash to send (optional)
     * @return array | false
     * @throws InternalError
     */
    public static function inputResource(mixed &$resource,
                                         bool $bufferSize = false,
                                         string $md5sum = ''): bool|array
    {
        if (!is_resource($resource) || (int)$bufferSize < 0) {
            self::__triggerError('S3::inputResource(): Invalid resource or buffer size');
            return false;
        }
        // Try to figure out the bytesize
        if ($bufferSize === false) {
            if (fseek($resource, 0, SEEK_END) < 0 || ($bufferSize = ftell($resource)) === false) {
                self::__triggerError('S3::inputResource(): Unable to obtain resource size');
                return false;
            }
            fseek($resource, 0);
        }
        $input = ['size' => $bufferSize, 'md5sum' => $md5sum];
        $input['fp'] =& $resource;
        return $input;
    }

    /**
     * Put an object from a file (legacy function)
     *
     * @param string $file Input file path
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @param string $acl ACL constant
     * @param array $metaHeaders Array of x-amz-meta-* headers
     * @param string|null $contentType Content type
     * @return boolean
     * @throws InternalError
     */
    public static function putObjectFile(string $file,
                                         string $bucket,
                                         string $uri,
                                         string $acl = self::ACL_PRIVATE,
                                         array $metaHeaders = [],
                                         ?string $contentType = null): bool
    {
        return self::putObject(self::inputFile($file), $bucket, $uri, $acl, $metaHeaders, ['Content-Type' => $contentType]);
    }

    /**
     * Put an object
     *
     * @param mixed $input Input data
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @param string $acl ACL constant
     * @param array $metaHeaders Array of x-amz-meta-* headers
     * @param array $requestHeaders Array of request headers or content type as a string
     * @param string $storageClass Storage class constant
     * @param string $serverSideEncryption Server-side encryption
     * @return boolean
     * @throws InternalError
     */
    public static function putObject(mixed $input,
                                     string $bucket,
                                     string $uri,
                                     string $acl = self::ACL_PRIVATE,
                                     array $metaHeaders = [],
                                     array $requestHeaders = [],
                                     string $storageClass = self::STORAGE_CLASS_STANDARD,
                                     string $serverSideEncryption = self::SSE_NONE): bool
    {
        if ($input === false) return false;
        $rest = new S3Request('PUT', $bucket, $uri, self::$endpoint);
        if (!is_array($input)) $input = [
            'data' => $input, 'size' => strlen($input),
            'md5sum' => base64_encode(md5($input, true))
        ];
        // Data
        if (isset($input['fp']))
            $rest->fp =& $input['fp'];
        elseif (isset($input['file']))
            $rest->fp = @fopen($input['file'], 'rb');
        elseif (isset($input['data']))
            $rest->data = $input['data'];
        // Content-Length (required)
        if (isset($input['size']) && $input['size'] >= 0)
            $rest->size = $input['size'];
        else {
            if (isset($input['file'])) {
                clearstatcache(false, $input['file']);
                $rest->size = filesize($input['file']);
            } elseif (isset($input['data']))
                $rest->size = strlen($input['data']);
        }
        // Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
        if (is_array($requestHeaders))
            foreach ($requestHeaders as $h => $v)
                str_starts_with($h, 'x-amz-') ? $rest->setAmzHeader($h, $v) : $rest->setHeader($h, $v);
        elseif (is_string($requestHeaders)) // Support for legacy contentType parameter
            $input['type'] = $requestHeaders;
        // Content-Type
        if (!isset($input['type'])) {
            if (isset($requestHeaders['Content-Type']))
                $input['type'] =& $requestHeaders['Content-Type'];
            elseif (isset($input['file']))
                $input['type'] = self::__getMIMEType($input['file']);
            else
                $input['type'] = 'application/octet-stream';
        }
        if ($storageClass !== self::STORAGE_CLASS_STANDARD) // Storage class
            $rest->setAmzHeader('x-amz-storage-class', $storageClass);
        if ($serverSideEncryption !== self::SSE_NONE) // Server-side encryption
            $rest->setAmzHeader('x-amz-server-side-encryption', $serverSideEncryption);
        // We need to post with Content-Length and Content-Type, MD5 is optional
        if ($rest->size >= 0 && ($rest->fp !== false || $rest->data !== false)) {
            $rest->setHeader('Content-Type', $input['type']);
            if (isset($input['md5sum'])) $rest->setHeader('Content-MD5', $input['md5sum']);
            $rest->setAmzHeader('x-amz-acl', $acl);
            foreach ($metaHeaders as $h => $v) $rest->setAmzHeader('x-amz-meta-' . $h, $v);
            $rest->getResponse();
        } else
            $rest->response->error = ['code' => 0, 'message' => 'Missing input parameters'];
        if (!$rest->response->error && $rest->response->code !== 200)
            $rest->response->error = ['code' => $rest->response->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->response->error) {
            self::__triggerError(sprintf("S3::putObject(): [%s] %s",
                $rest->response->error['code'], $rest->response->error['message']));
            return false;
        }
        return true;
    }

    /**
     * Get MIME type for file
     *
     * To override the putObject() Content-Type, add it to $requestHeaders
     *
     * To use fileinfo, ensure the MAGIC environment variable is set
     *
     * @param string &$file File path
     * @return string
     * @internal Used to get mime types
     */
    private static function __getMIMEType(string $file): string
    {
        static $exts = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'png' => 'image/png', 'ico' => 'image/x-icon', 'pdf' => 'application/pdf',
            'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'svg' => 'image/svg+xml',
            'svgz' => 'image/svg+xml', 'swf' => 'application/x-shockwave-flash',
            'zip' => 'application/zip', 'gz' => 'application/x-gzip',
            'tar' => 'application/x-tar', 'bz' => 'application/x-bzip',
            'bz2' => 'application/x-bzip2', 'rar' => 'application/x-rar-compressed',
            'exe' => 'application/x-msdownload', 'msi' => 'application/x-msdownload',
            'cab' => 'application/vnd.ms-cab-compressed', 'txt' => 'text/plain',
            'asc' => 'text/plain', 'htm' => 'text/html', 'html' => 'text/html',
            'css' => 'text/css', 'js' => 'text/javascript',
            'xml' => 'text/xml', 'xsl' => 'application/xsl+xml',
            'ogg' => 'application/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/x-wav',
            'avi' => 'video/x-msvideo', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg',
            'mov' => 'video/quicktime', 'flv' => 'video/x-flv', 'php' => 'text/x-php'
        ];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (isset($exts[$ext])) return $exts[$ext];
        // Use fileinfo if available
        if (extension_loaded('fileinfo') && isset($_ENV['MAGIC']) &&
            ($finfo = finfo_open(FILEINFO_MIME, $_ENV['MAGIC'])) !== false) {
            if (($type = finfo_file($finfo, $file)) !== false) {
                // Remove the charset and grab the last content-type
                $type = explode(' ', str_replace('; charset=', ';charset=', $type));
                $type = array_pop($type);
                $type = explode(';', $type);
                $type = trim(array_shift($type));
            }
            finfo_close($finfo);
            if ($type !== false && strlen($type) > 0) return $type;
        }
        return 'application/octet-stream';
    }

    /**
     * Create input info array for putObject()
     *
     * @param string $file Input file
     * @param mixed $md5sum Use MD5 hash (supply a string if you want to use your own)
     * @return array | false
     * @throws InternalError
     */
    public static function inputFile(string $file, bool $md5sum = true): bool|array
    {
        if (!file_exists($file) || !is_file($file) || !is_readable($file)) {
            self::__triggerError('S3::inputFile(): Unable to open input file: ' . $file);
            return false;
        }
        clearstatcache(false, $file);
        return ['file' => $file, 'size' => filesize($file), 'md5sum' => $md5sum !== false ?
            (is_string($md5sum) ? $md5sum : base64_encode(md5_file($file, true))) : ''];
    }

    /**
     * Put an object from a string (legacy function)
     *
     * @param string $string Input data
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @param string $acl ACL constant
     * @param array $metaHeaders Array of x-amz-meta-* headers
     * @param string $contentType Content type
     * @return boolean
     * @throws InternalError
     */
    public static function putObjectString(string $string,
                                           string $bucket,
                                           string $uri,
                                           string $acl = self::ACL_PRIVATE,
                                           array $metaHeaders = [],
                                           string $contentType = 'text/plain'): bool
    {
        return self::putObject($string, $bucket, $uri, $acl, $metaHeaders, ['Content-Type' => $contentType]);
    }

    /**
     * Get an object
     *
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @param mixed $saveTo Filename or resource to write to
     * @return bool|stdClass
     * @throws InternalError
     */
    public static function getObject(string $bucket, string $uri, bool $saveTo = false): bool|stdClass
    {
        $rest = new S3Request('GET', $bucket, $uri, self::$endpoint);
        if ($saveTo !== false) {
            if (is_resource($saveTo))
                $rest->fp =& $saveTo;
            elseif (($rest->fp = @fopen($saveTo, 'wb')) === false)
                $rest->response->error = ['code' => 0, 'message' => 'Unable to open save file for writing: ' . $saveTo];
        }
        if (!$rest->response->error) $rest->getResponse();
        if (!$rest->response->error && $rest->response->code !== 200)
            $rest->response->error = ['code' => $rest->response->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->response->error) {
            self::__triggerError(sprintf("S3::getObject({$bucket}, {$uri}): [%s] %s",
                $rest->response->error['code'], $rest->response->error['message']));
            return false;
        }
        return $rest->response;
    }

    /**
     * Get object information
     *
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @param boolean $returnInfo Return response information
     * @return mixed
     * @throws InternalError
     */
    public static function getObjectInfo(string $bucket, string $uri, bool $returnInfo = true): mixed
    {
        $rest = new S3Request('HEAD', $bucket, $uri, self::$endpoint);
        $rest = $rest->getResponse();
        if ($rest->error === false && ($rest->code !== 200 && $rest->code !== 404))
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::getObjectInfo({$bucket}, {$uri}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return $rest->code == 200 ? $returnInfo ? $rest->headers : true : false;
    }

    /**
     * Copy an object
     *
     * @param string $srcBucket Source bucket name
     * @param string $srcUri Source object URI
     * @param string $bucket Destination bucket name
     * @param string $uri Destination object URI
     * @param string $acl ACL constant
     * @param array $metaHeaders Optional array of x-amz-meta-* headers
     * @param array $requestHeaders Optional array of request headers (content type, disposition, etc.)
     * @param string $storageClass Storage class constant
     * @return bool|array
     * @throws InternalError
     */
    public static function copyObject(string $srcBucket,
                                      string $srcUri,
                                      string $bucket,
                                      string $uri,
                                      string $acl = self::ACL_PRIVATE,
                                      array $metaHeaders = [],
                                      array $requestHeaders = [],
                                      string $storageClass = self::STORAGE_CLASS_STANDARD): bool|array
    {
        $rest = new S3Request('PUT', $bucket, $uri, self::$endpoint);
        $rest->setHeader('Content-Length', '0');
        foreach ($requestHeaders as $h => $v)
            str_starts_with($h, 'x-amz-') ? $rest->setAmzHeader($h, $v) : $rest->setHeader($h, $v);
        foreach ($metaHeaders as $h => $v) $rest->setAmzHeader('x-amz-meta-' . $h, $v);
        if ($storageClass !== self::STORAGE_CLASS_STANDARD) // Storage class
            $rest->setAmzHeader('x-amz-storage-class', $storageClass);
        $rest->setAmzHeader('x-amz-acl', $acl);
        $rest->setAmzHeader('x-amz-copy-source', sprintf('/%s/%s', $srcBucket, rawurlencode($srcUri)));
        if (sizeof($requestHeaders) > 0 || sizeof($metaHeaders) > 0)
            $rest->setAmzHeader('x-amz-metadata-directive', 'REPLACE');
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::copyObject({$srcBucket}, {$srcUri}, {$bucket}, {$uri}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return isset($rest->body->LastModified, $rest->body->ETag) ? [
            'time' => strtotime((string)$rest->body->LastModified),
            'hash' => substr((string)$rest->body->ETag, 1, -1)
        ] : false;
    }

    /**
     * Set up a bucket redirection
     *
     * @param string|null $bucket Bucket name
     * @param string|null $location Target host name
     * @return boolean
     * @throws InternalError
     */
    public static function setBucketRedirect(?string $bucket = NULL,
                                             ?string $location = NULL): bool
    {
        $rest = new S3Request('PUT', $bucket, '', self::$endpoint);
        if (empty($bucket) || empty($location)) {
            self::__triggerError("S3::setBucketRedirect({$bucket}, {$location}): Empty parameter.");
            return false;
        }
        $dom = new DOMDocument;
        $websiteConfiguration = $dom->createElement('WebsiteConfiguration');
        $redirectAllRequestsTo = $dom->createElement('RedirectAllRequestsTo');
        $hostName = $dom->createElement('HostName', $location);
        $redirectAllRequestsTo->appendChild($hostName);
        $websiteConfiguration->appendChild($redirectAllRequestsTo);
        $dom->appendChild($websiteConfiguration);
        $rest->setParameter('website', null);
        $rest->data = $dom->saveXML();
        $rest->size = strlen($rest->data);
        $rest->setHeader('Content-Type', 'application/xml');
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::setBucketRedirect({$bucket}, {$location}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return true;
    }

    /**
     * Get logging status for a bucket
     *
     * This will return false if logging is not enabled.
     * Note: To enable logging, you also need to grant write access to the log group
     *
     * @param string $bucket Bucket name
     * @return array | false
     * @throws InternalError
     */
    public static function getBucketLogging(string $bucket): array|bool
    {
        $rest = new S3Request('GET', $bucket, '', self::$endpoint);
        $rest->setParameter('logging', null);
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::getBucketLogging({$bucket}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        if (!isset($rest->body->LoggingEnabled)) return false; // No logging
        return [
            'targetBucket' => (string)$rest->body->LoggingEnabled->TargetBucket,
            'targetPrefix' => (string)$rest->body->LoggingEnabled->TargetPrefix,
        ];
    }

    /**
     * Disable bucket logging
     *
     * @param string $bucket Bucket name
     * @return boolean
     * @throws InternalError
     */
    public static function disableBucketLogging(string $bucket): bool
    {
        return self::setBucketLogging($bucket, null);
    }

    /**
     * Set logging for a bucket
     *
     * @param string $bucket Bucket name
     * @param string|null $targetBucket Target bucket (where logs are stored)
     * @param string|null $targetPrefix Log prefix (e,g; domain.com-)
     * @return boolean
     * @throws InternalError
     */
    public static function setBucketLogging(string $bucket, ?string $targetBucket, ?string $targetPrefix = null): bool
    {
        // The S3 log delivery group has to be added to the target bucket's ACP
        if ($targetBucket !== null && ($acp = self::getAccessControlPolicy($targetBucket)) !== false) {
            // Only add permissions to the target bucket when they do not exist
            $aclWriteSet = false;
            $aclReadSet = false;
            foreach ($acp['acl'] as $acl)
                if ($acl['type'] == 'Group' && $acl['uri'] == 'https://acs.amazonaws.com/groups/s3/LogDelivery') {
                    if ($acl['permission'] == 'WRITE') $aclWriteSet = true;
                    elseif ($acl['permission'] == 'READ_ACP') $aclReadSet = true;
                }
            if (!$aclWriteSet) $acp['acl'][] = [
                'type' => 'Group', 'uri' => 'https://acs.amazonaws.com/groups/s3/LogDelivery', 'permission' => 'WRITE'
            ];
            if (!$aclReadSet) $acp['acl'][] = [
                'type' => 'Group', 'uri' => 'https://acs.amazonaws.com/groups/s3/LogDelivery', 'permission' => 'READ_ACP'
            ];
            if (!$aclReadSet || !$aclWriteSet) self::setAccessControlPolicy($targetBucket, '', $acp);
        }
        $dom = new DOMDocument;
        $bucketLoggingStatus = $dom->createElement('BucketLoggingStatus');
        $bucketLoggingStatus->setAttribute('xmlns', 'https://s3.amazonaws.com/doc/2006-03-01/');
        if ($targetBucket !== null) {
            if ($targetPrefix == null) $targetPrefix = $bucket . '-';
            $loggingEnabled = $dom->createElement('LoggingEnabled');
            $loggingEnabled->appendChild($dom->createElement('TargetBucket', $targetBucket));
            $loggingEnabled->appendChild($dom->createElement('TargetPrefix', $targetPrefix));
            // TODO: Add TargetGrants?
            $bucketLoggingStatus->appendChild($loggingEnabled);
        }
        $dom->appendChild($bucketLoggingStatus);
        $rest = new S3Request('PUT', $bucket, '', self::$endpoint);
        $rest->setParameter('logging', null);
        $rest->data = $dom->saveXML();
        $rest->size = strlen($rest->data);
        $rest->setHeader('Content-Type', 'application/xml');
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::setBucketLogging({$bucket}, {$targetBucket}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return true;
    }

    /**
     * Get object or bucket Access Control Policy
     *
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @return bool|array
     * @throws InternalError
     */
    public static function getAccessControlPolicy(string $bucket, string $uri = ''): bool|array
    {
        $rest = new S3Request('GET', $bucket, $uri, self::$endpoint);
        $rest->setParameter('acl', null);
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::getAccessControlPolicy({$bucket}, {$uri}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        $acp = [];
        if (isset($rest->body->Owner, $rest->body->Owner->ID, $rest->body->Owner->DisplayName))
            $acp['owner'] = [
                'id' => (string)$rest->body->Owner->ID, 'name' => (string)$rest->body->Owner->DisplayName
            ];
        if (isset($rest->body->AccessControlList)) {
            $acp['acl'] = [];
            foreach ($rest->body->AccessControlList->Grant as $grant) {
                foreach ($grant->Grantee as $grantee) {
                    if (isset($grantee->ID, $grantee->DisplayName)) // CanonicalUser
                        $acp['acl'][] = [
                            'type' => 'CanonicalUser',
                            'id' => (string)$grantee->ID,
                            'name' => (string)$grantee->DisplayName,
                            'permission' => (string)$grant->Permission
                        ];
                    elseif (isset($grantee->EmailAddress)) // AmazonCustomerByEmail
                        $acp['acl'][] = [
                            'type' => 'AmazonCustomerByEmail',
                            'email' => (string)$grantee->EmailAddress,
                            'permission' => (string)$grant->Permission
                        ];
                    elseif (isset($grantee->URI)) // Group
                        $acp['acl'][] = [
                            'type' => 'Group',
                            'uri' => (string)$grantee->URI,
                            'permission' => (string)$grant->Permission
                        ];
                }
            }
        }
        return $acp;
    }

    /**
     * Set object or bucket Access Control Policy
     *
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @param array $acp Access Control Policy Data (same as the data returned from getAccessControlPolicy)
     * @return boolean
     * @throws InternalError
     */
    public static function setAccessControlPolicy(string $bucket, string $uri = '', array $acp = []): bool
    {
        $dom = new DOMDocument;
        $dom->formatOutput = true;
        $accessControlPolicy = $dom->createElement('AccessControlPolicy');
        $accessControlList = $dom->createElement('AccessControlList');
        // It seems the owner has to be passed along too
        $owner = $dom->createElement('Owner');
        $owner->appendChild($dom->createElement('ID', $acp['owner']['id']));
        $owner->appendChild($dom->createElement('DisplayName', $acp['owner']['name']));
        $accessControlPolicy->appendChild($owner);
        foreach ($acp['acl'] as $g) {
            $grant = $dom->createElement('Grant');
            $grantee = $dom->createElement('Grantee');
            $grantee->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
            if (isset($g['id'])) { // CanonicalUser (DisplayName is omitted)
                $grantee->setAttribute('xsi:type', 'CanonicalUser');
                $grantee->appendChild($dom->createElement('ID', $g['id']));
            } elseif (isset($g['email'])) { // AmazonCustomerByEmail
                $grantee->setAttribute('xsi:type', 'AmazonCustomerByEmail');
                $grantee->appendChild($dom->createElement('EmailAddress', $g['email']));
            } elseif ($g['type'] == 'Group') { // Group
                $grantee->setAttribute('xsi:type', 'Group');
                $grantee->appendChild($dom->createElement('URI', $g['uri']));
            }
            $grant->appendChild($grantee);
            $grant->appendChild($dom->createElement('Permission', $g['permission']));
            $accessControlList->appendChild($grant);
        }
        $accessControlPolicy->appendChild($accessControlList);
        $dom->appendChild($accessControlPolicy);
        $rest = new S3Request('PUT', $bucket, $uri, self::$endpoint);
        $rest->setParameter('acl', null);
        $rest->data = $dom->saveXML();
        $rest->size = strlen($rest->data);
        $rest->setHeader('Content-Type', 'application/xml');
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::setAccessControlPolicy({$bucket}, {$uri}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return true;
    }

    /**
     * Get a bucket's location
     *
     * @param string $bucket Bucket name
     * @return string|bool
     * @throws InternalError
     */
    public static function getBucketLocation(string $bucket): bool|string
    {
        $rest = new S3Request('GET', $bucket, '', self::$endpoint);
        $rest->setParameter('location', null);
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::getBucketLocation({$bucket}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return (isset($rest->body[0]) && (string)$rest->body[0] !== '') ? (string)$rest->body[0] : 'US';
    }

    /**
     * Delete an object
     *
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @return boolean
     * @throws InternalError
     */
    public static function deleteObject(string $bucket, string $uri): bool
    {
        $rest = new S3Request('DELETE', $bucket, $uri, self::$endpoint);
        $rest = $rest->getResponse();
        if ($rest->error === false && $rest->code !== 204)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::deleteObject(): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return true;
    }

    /**
     * Get a query string authenticated URL
     *
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @param integer $lifetime Lifetime in seconds
     * @param boolean $hostBucket Use the bucket name as the hostname
     * @param boolean $https Use HTTPS ($hostBucket should be false for SSL verification)
     * @return string
     */
    public static function getAuthenticatedURL(string $bucket,
                                               string $uri,
                                               int $lifetime,
                                               bool $hostBucket = false,
                                               bool $https = false): string
    {
        $expires = self::__getTime() + $lifetime;
        $uri = str_replace(['%2F', '%2B'], ['/', '+'], rawurlencode($uri));
        return sprintf(($https ? 'https' : 'http') . '://%s/%s?AWSAccessKeyId=%s&Expires=%u&Signature=%s',
            // $hostBucket ? $bucket : $bucket.'.s3.amazonaws.com', $uri, self::$__accessKey, $expires,
            $hostBucket ? $bucket : self::$endpoint . '/' . $bucket, $uri, self::$__accessKey, $expires,
            urlencode(self::__getHash("GET\n\n\n{$expires}\n/{$bucket}/{$uri}")));
    }

    /**
     * Get the current time
     *
     * @return integer
     * @internal Used to apply offsets to sytem time
     */
    public static function __getTime(): int
    {
        return time() + self::$__timeOffset;
    }

    /**
     * Creates a HMAC-SHA1 hash
     *
     * This uses the hash extension if loaded
     *
     * @param string|null $string $string String to sign
     * @return string
     * @internal Used by __getSignature()
     */
    #[Pure] private static function __getHash(?string $string): string
    {
        return base64_encode(extension_loaded('hash') ?
            hash_hmac('sha1', $string, self::$__secretKey, true) : pack('H*', sha1(
                (str_pad(self::$__secretKey, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) .
                pack('H*', sha1((str_pad(self::$__secretKey, 64, chr(0x00)) ^
                        (str_repeat(chr(0x36), 64))) . $string)))));
    }

    /**
     * Get a CloudFront canned policy URL
     *
     * @param string $url URL to sign
     * @param integer $lifetime URL lifetime
     * @return bool|string
     */
    public static function getSignedCannedURL(string $url, int $lifetime): bool|string
    {
        return self::getSignedPolicyURL([
            'Statement' => [
                ['Resource' => $url, 'Condition' => [
                    'DateLessThan' => ['AWS:EpochTime' => self::__getTime() + $lifetime]
                ]]
            ]
        ]);
    }

    /**
     * Get a CloudFront signed policy URL
     *
     * @param array $policy Policy
     * @return bool|string
     */
    public static function getSignedPolicyURL(array $policy): bool|string
    {
        $data = json_encode($policy);
        $signature = '';
        if (!openssl_sign($data, $signature, self::$__signingKeyResource)) return false;
        $encoded = str_replace(['+', '='], ['-', '_', '~'], base64_encode($data));
        $signature = str_replace(['+', '='], ['-', '_', '~'], base64_encode($signature));
        $url = $policy['Statement'][0]['Resource'] . '?';
        foreach (['Policy' => $encoded, 'Signature' => $signature, 'Key-Pair-Id' => self::$__signingKeyPairId] as $k => $v)
            $url .= $k . '=' . str_replace('%2F', '/', rawurlencode($v)) . '&';
        return substr($url, 0, -1);
    }

    /**
     * Get upload POST parameters for form uploads
     *
     * @param string $bucket Bucket name
     * @param string $uriPrefix Object URI prefix
     * @param string $acl ACL constant
     * @param integer $lifetime Lifetime in seconds
     * @param integer $maxFileSize Maximum filesize in bytes (default 5MB)
     * @param string $successRedirect Redirect URL or 200 / 201 status code
     * @param array $amzHeaders Array of x-amz-meta-* headers
     * @param array $headers Array of request headers or content type as a string
     * @param boolean $flashVars Includes additional "Filename" variable posted by Flash
     * @return stdClass
     */
    public static function getHttpUploadPostParams(string $bucket,
                                                   string $uriPrefix = '',
                                                   string $acl = self::ACL_PRIVATE,
                                                   int $lifetime = 3600,
                                                   int $maxFileSize = 5242880,
                                                   string $successRedirect = "201",
                                                   array $amzHeaders = [],
                                                   array $headers = [],
                                                   bool $flashVars = false): stdClass
    { // Create policy object
        $policy = new stdClass;
        $policy->expiration = gmdate('Y-m-d\TH:i:s\Z', (self::__getTime() + $lifetime));
        $policy->conditions = [];
        $obj = new stdClass;
        $obj->bucket = $bucket;
        array_push($policy->conditions, $obj);
        $obj = new stdClass;
        $obj->acl = $acl;
        array_push($policy->conditions, $obj);
        $obj = new stdClass; // 200 for non-redirect uploads
        if (is_numeric($successRedirect) && in_array((int)$successRedirect, [200, 201])) $obj->success_action_status = (string)$successRedirect; else // URL
            $obj->success_action_redirect = $successRedirect;
        array_push($policy->conditions, $obj);
        if ($acl !== self::ACL_PUBLIC_READ) array_push($policy->conditions, ['eq', '$acl', $acl]);
        array_push($policy->conditions, ['starts-with', '$key', $uriPrefix]);
        if ($flashVars) array_push($policy->conditions, ['starts-with', '$Filename', '']);
        foreach (array_keys($headers) as $headerKey) array_push($policy->conditions, ['starts-with', '$' . $headerKey, '']);
        foreach ($amzHeaders as $headerKey => $headerVal) {
            $obj = new stdClass;
            $obj->{$headerKey} = (string)$headerVal;
            array_push($policy->conditions, $obj);
        }
        array_push($policy->conditions, ['content-length-range', 0, $maxFileSize]);
        $policy = base64_encode(str_replace('\/', '/', json_encode($policy))); // Create parameters
        $params = new stdClass;
        $params->AWSAccessKeyId = self::$__accessKey;
        $params->key = $uriPrefix . '${filename}';
        $params->acl = $acl;
        $params->policy = $policy;
        unset($policy);
        $params->signature = self::__getHash($params->policy);
        if (is_numeric($successRedirect) && in_array((int)$successRedirect, [200, 201])) $params->success_action_status = (string)$successRedirect; else $params->success_action_redirect = $successRedirect;
        foreach ($headers as $headerKey => $headerVal) $params->{$headerKey} = (string)$headerVal;
        foreach ($amzHeaders as $headerKey => $headerVal) $params->{$headerKey} = (string)$headerVal;
        return $params;
    }

    /**
     * Create a CloudFront distribution
     *
     * @param string $bucket Bucket name
     * @param boolean $enabled Enabled (true/false)
     * @param array $cnames Array containing CNAME aliases
     * @param string|null $comment Use the bucket name as the hostname
     * @param string|null $defaultRootObject Default root object
     * @param string|null $originAccessIdentity Origin access identity
     * @param array $trustedSigners Array of trusted signers
     * @return array | false
     * @throws InternalError
     */
    public static function createDistribution(string $bucket,
                                              bool $enabled = true,
                                              array $cnames = [],
                                              ?string $comment = null,
                                              ?string $defaultRootObject = null,
                                              ?string $originAccessIdentity = null,
                                              array $trustedSigners = []): bool|array
    {
        if (!extension_loaded('openssl')) {
            self::__triggerError(sprintf("S3::createDistribution({$bucket}, " . (int)$enabled . ", [], '$comment'): %s",
                "CloudFront functionality requires SSL"));
            return false;
        }
        $useSSL = self::$useSSL;
        self::$useSSL = true; // CloudFront requires SSL
        $rest = new S3Request('POST', '', '2010-11-01/distribution', 'cloudfront.amazonaws.com');
        $rest->data = self::__getCloudFrontDistributionConfigXML(
            $bucket . '.s3.amazonaws.com',
            $enabled,
            (string)$comment,
            (string)hrtime(true),
            $cnames,
            $defaultRootObject,
            $originAccessIdentity,
            $trustedSigners
        );
        $rest->size = strlen($rest->data);
        $rest->setHeader('Content-Type', 'application/xml');
        $rest = self::__getCloudFrontResponse($rest);
        self::$useSSL = $useSSL;
        if ($rest->error === false && $rest->code !== 201)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::createDistribution({$bucket}, " . (int)$enabled . ", [], '$comment'): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        } elseif ($rest->body instanceof SimpleXMLElement)
            return self::__parseCloudFrontDistributionConfig($rest->body);
        return false;
    }

    /**
     * Get a DistributionConfig DOMDocument
     *
     * http://docs.amazonwebservices.com/AmazonCloudFront/latest/APIReference/index.html?PutConfig.html
     *
     * @param string $bucket S3 Origin bucket
     * @param boolean $enabled Enabled (true/false)
     * @param string $comment Comment to append
     * @param string $callerReference Caller reference
     * @param array $cnames Array of CNAME aliases
     * @param ?string $defaultRootObject Default root object
     * @param ?string $originAccessIdentity Origin access identity
     * @param array $trustedSigners Array of trusted signers
     * @return string
     * @internal Used to create XML in createDistribution() and updateDistribution()
     */
    private static function __getCloudFrontDistributionConfigXML(string $bucket,
                                                                 bool $enabled,
                                                                 string $comment,
                                                                 string $callerReference = '0',
                                                                 array $cnames = [],
                                                                 ?string $defaultRootObject = null,
                                                                 ?string $originAccessIdentity = null,
                                                                 array $trustedSigners = []): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $distributionConfig = $dom->createElement('DistributionConfig');
        $distributionConfig->setAttribute('xmlns', 'http://cloudfront.amazonaws.com/doc/2010-11-01/');
        $origin = $dom->createElement('S3Origin');
        $origin->appendChild($dom->createElement('DNSName', $bucket));
        if ($originAccessIdentity !== null) $origin->appendChild($dom->createElement('OriginAccessIdentity', $originAccessIdentity));
        $distributionConfig->appendChild($origin);
        if ($defaultRootObject !== null) $distributionConfig->appendChild($dom->createElement('DefaultRootObject', $defaultRootObject));
        $distributionConfig->appendChild($dom->createElement('CallerReference', $callerReference));
        foreach ($cnames as $cname)
            $distributionConfig->appendChild($dom->createElement('CNAME', $cname));
        if ($comment !== '') $distributionConfig->appendChild($dom->createElement('Comment', $comment));
        $distributionConfig->appendChild($dom->createElement('Enabled', $enabled ? 'true' : 'false'));
        $trusted = $dom->createElement('TrustedSigners');
        foreach ($trustedSigners as $id => $type)
            $trusted->appendChild($id !== '' ? $dom->createElement($type, $id) : $dom->createElement($type));
        $distributionConfig->appendChild($trusted);
        $dom->appendChild($distributionConfig);
        //var_dump($dom->saveXML());
        return $dom->saveXML();
    }

    /**
     * Grab CloudFront response
     *
     * @param object &$rest S3Request instance
     * @return object
     * @internal Used to parse the CloudFront S3Request::getResponse() output
     */
    private static function __getCloudFrontResponse(object $rest): object
    {
        $rest->getResponse();
        if ($rest->response->error === false && isset($rest->response->body) &&
            is_string($rest->response->body) && str_starts_with($rest->response->body, '<?xml')) {
            $rest->response->body = simplexml_load_string($rest->response->body);
            // Grab CloudFront errors
            if (isset($rest->response->body->Error, $rest->response->body->Error->Code,
                $rest->response->body->Error->Message)) {
                $rest->response->error = [
                    'code' => (string)$rest->response->body->Error->Code,
                    'message' => (string)$rest->response->body->Error->Message
                ];
                unset($rest->response->body);
            }
        }
        return $rest->response;
    }

    /**
     * Parse a CloudFront distribution config
     *
     * See http://docs.amazonwebservices.com/AmazonCloudFront/latest/APIReference/index.html?GetDistribution.html
     *
     * @param object &$node DOMNode
     * @return array
     * @internal Used to parse the CloudFront DistributionConfig node to an array
     */
    private static function __parseCloudFrontDistributionConfig(object $node): array
    {
        if (isset($node->DistributionConfig))
            return self::__parseCloudFrontDistributionConfig($node->DistributionConfig);
        $dist = [];
        if (isset($node->Id, $node->Status, $node->LastModifiedTime, $node->DomainName)) {
            $dist['id'] = (string)$node->Id;
            $dist['status'] = (string)$node->Status;
            $dist['time'] = strtotime((string)$node->LastModifiedTime);
            $dist['domain'] = (string)$node->DomainName;
        }
        if (isset($node->CallerReference))
            $dist['callerReference'] = (string)$node->CallerReference;
        if (isset($node->Enabled))
            $dist['enabled'] = (string)$node->Enabled === 'true';
        if (isset($node->S3Origin)) {
            if (isset($node->S3Origin->DNSName))
                $dist['origin'] = (string)$node->S3Origin->DNSName;
            $dist['originAccessIdentity'] = isset($node->S3Origin->OriginAccessIdentity) ?
                (string)$node->S3Origin->OriginAccessIdentity : null;
        }
        $dist['defaultRootObject'] = isset($node->DefaultRootObject) ? (string)$node->DefaultRootObject : null;
        $dist['cnames'] = [];
        if (isset($node->CNAME))
            foreach ($node->CNAME as $cname)
                $dist['cnames'][(string)$cname] = (string)$cname;
        $dist['trustedSigners'] = [];
        if (isset($node->TrustedSigners))
            foreach ($node->TrustedSigners as $signer) {
                if (isset($signer->Self))
                    $dist['trustedSigners'][''] = 'Self';
                elseif (isset($signer->KeyPairId))
                    $dist['trustedSigners'][(string)$signer->KeyPairId] = 'KeyPairId';
                elseif (isset($signer->AwsAccountNumber))
                    $dist['trustedSigners'][(string)$signer->AwsAccountNumber] = 'AwsAccountNumber';
            }
        $dist['comment'] = isset($node->Comment) ? (string)$node->Comment : null;
        return $dist;
    }

    /**
     * Get CloudFront distribution info
     *
     * @param string $distributionId Distribution ID from listDistributions()
     * @return array | bool
     * @throws InternalError
     */
    public static function getDistribution(string $distributionId): bool|array
    {
        if (!extension_loaded('openssl')) {
            self::__triggerError(sprintf("S3::getDistribution($distributionId): %s",
                "CloudFront functionality requires SSL"));
            return false;
        }
        $useSSL = self::$useSSL;
        self::$useSSL = true; // CloudFront requires SSL
        $rest = new S3Request('GET', '', '2010-11-01/distribution/' . $distributionId, 'cloudfront.amazonaws.com');
        $rest = self::__getCloudFrontResponse($rest);
        self::$useSSL = $useSSL;
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::getDistribution($distributionId): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        } elseif ($rest->body instanceof SimpleXMLElement) {
            $dist = self::__parseCloudFrontDistributionConfig($rest->body);
            $dist['hash'] = $rest->headers['hash'];
            $dist['id'] = $distributionId;
            return $dist;
        }
        return false;
    }

    /**
     * Update a CloudFront distribution
     *
     * @param array $dist Distribution array info identical to output of getDistribution()
     * @return array | bool
     * @throws InternalError
     */
    public static function updateDistribution(array $dist): bool|array
    {
        if (!extension_loaded('openssl')) {
            self::__triggerError(sprintf("S3::updateDistribution({$dist['id']}): %s",
                "CloudFront functionality requires SSL"));
            return false;
        }
        $useSSL = self::$useSSL;
        self::$useSSL = true; // CloudFront requires SSL
        $rest = new S3Request('PUT', '', '2010-11-01/distribution/' . $dist['id'] . '/config', 'cloudfront.amazonaws.com');
        $rest->data = self::__getCloudFrontDistributionConfigXML(
            $dist['origin'],
            $dist['enabled'],
            $dist['comment'],
            $dist['callerReference'],
            $dist['cnames'],
            $dist['defaultRootObject'],
            $dist['originAccessIdentity'],
            $dist['trustedSigners']
        );
        $rest->size = strlen($rest->data);
        $rest->setHeader('If-Match', $dist['hash']);
        $rest = self::__getCloudFrontResponse($rest);
        self::$useSSL = $useSSL;
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::updateDistribution({$dist['id']}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        } else {
            $dist = self::__parseCloudFrontDistributionConfig($rest->body);
            $dist['hash'] = $rest->headers['hash'];
            return $dist;
        }
    }

    /**
     * Delete a CloudFront distribution
     *
     * @param array $dist Distribution array info identical to output of getDistribution()
     * @return boolean
     * @throws InternalError
     */
    public static function deleteDistribution(array $dist): bool
    {
        if (!extension_loaded('openssl')) {
            self::__triggerError(sprintf("S3::deleteDistribution({$dist['id']}): %s",
                "CloudFront functionality requires SSL"));
            return false;
        }
        $useSSL = self::$useSSL;
        self::$useSSL = true; // CloudFront requires SSL
        $rest = new S3Request('DELETE', '', '2008-06-30/distribution/' . $dist['id'], 'cloudfront.amazonaws.com');
        $rest->setHeader('If-Match', $dist['hash']);
        $rest = self::__getCloudFrontResponse($rest);
        self::$useSSL = $useSSL;
        if ($rest->error === false && $rest->code !== 204)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::deleteDistribution({$dist['id']}): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        }
        return true;
    }

    /**
     * Get a list of CloudFront distributions
     *
     * @return array|bool
     * @throws InternalError
     */
    public static function listDistributions(): bool|array
    {
        if (!extension_loaded('openssl')) {
            self::__triggerError(sprintf("S3::listDistributions(): [%s]",
                "CloudFront functionality requires SSL"));
            return false;
        }
        $useSSL = self::$useSSL;
        self::$useSSL = true; // CloudFront requires SSL
        $rest = new S3Request('GET', '', '2010-11-01/distribution', 'cloudfront.amazonaws.com');
        $rest = self::__getCloudFrontResponse($rest);
        self::$useSSL = $useSSL;
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            self::__triggerError(sprintf("S3::listDistributions(): [%s] %s",
                $rest->error['code'], $rest->error['message']));
            return false;
        } elseif ($rest->body instanceof SimpleXMLElement && isset($rest->body->DistributionSummary)) {
            $list = [];
            foreach ($rest->body->DistributionSummary as $summary)
                $list[(string)$summary->Id] = self::__parseCloudFrontDistributionConfig($summary);
            return $list;
        }
        return [];
    }

    /**
     * List CloudFront Origin Access Identities
     *
     * @return array|bool
     * @throws InternalError
     */
    public static function listOriginAccessIdentities(): bool|array
    {
        if (!extension_loaded('openssl')) {
            self::__triggerError(sprintf("S3::listOriginAccessIdentities(): [%s]",
                "CloudFront functionality requires SSL"));
            return false;
        }
        self::$useSSL = true; // CloudFront requires SSL
        $rest = new S3Request('GET', '', '2010-11-01/origin-access-identity/cloudfront', 'cloudfront.amazonaws.com');
        $rest = self::__getCloudFrontResponse($rest);
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            trigger_error(sprintf("S3::listOriginAccessIdentities(): [%s] %s",
                $rest->error['code'], $rest->error['message']), E_USER_WARNING);
            return false;
        }
        if (isset($rest->body->CloudFrontOriginAccessIdentitySummary)) {
            $identities = [];
            foreach ($rest->body->CloudFrontOriginAccessIdentitySummary as $identity)
                if (isset($identity->S3CanonicalUserId))
                    $identities[(string)$identity->Id] = ['id' => (string)$identity->Id, 's3CanonicalUserId' => (string)$identity->S3CanonicalUserId];
            return $identities;
        }
        return false;
    }

    /**
     * Invalidate objects in a CloudFront distribution
     *
     * Thanks to Martin Lindkvist for S3::invalidateDistribution()
     *
     * @param string $distributionId Distribution ID from listDistributions()
     * @param array $paths Array of object paths to invalidate
     * @return boolean
     * @throws InternalError
     */
    public static function invalidateDistribution(string $distributionId, array $paths): bool
    {
        if (!extension_loaded('openssl')) {
            self::__triggerError(sprintf("S3::invalidateDistribution(): [%s]",
                "CloudFront functionality requires SSL"));
            return false;
        }
        $useSSL = self::$useSSL;
        self::$useSSL = true; // CloudFront requires SSL
        $rest = new S3Request('POST', '', '2010-08-01/distribution/' . $distributionId . '/invalidation', 'cloudfront.amazonaws.com');
        $rest->data = self::__getCloudFrontInvalidationBatchXML($paths, (string)hrtime(true));
        $rest->size = strlen($rest->data);
        $rest = self::__getCloudFrontResponse($rest);
        self::$useSSL = $useSSL;
        if ($rest->error === false && $rest->code !== 201)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            trigger_error(sprintf("S3::invalidate('{$distributionId}',{$paths}): [%s] %s",
                $rest->error['code'], $rest->error['message']), E_USER_WARNING);
            return false;
        }
        return true;
    }

    /**
     * Get a InvalidationBatch DOMDocument
     *
     * @param array $paths Paths to objects to invalidateDistribution
     * @param string $callerReference
     * @return string
     * @internal Used to create XML in invalidateDistribution()
     */
    private static function __getCloudFrontInvalidationBatchXML(array $paths,
                                                                string $callerReference = '0'): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $invalidationBatch = $dom->createElement('InvalidationBatch');
        foreach ($paths as $path)
            $invalidationBatch->appendChild($dom->createElement('Path', $path));
        $invalidationBatch->appendChild($dom->createElement('CallerReference', $callerReference));
        $dom->appendChild($invalidationBatch);
        return $dom->saveXML();
    }

    /**
     * List your invalidation batches for invalidateDistribution() in a CloudFront distribution
     *
     * http://docs.amazonwebservices.com/AmazonCloudFront/latest/APIReference/ListInvalidation.html
     * returned array looks like this:
     *    Array
     *    (
     *        [I31TWB0CN9V6XD] => InProgress
     *        [IT3TFE31M0IHZ] => Completed
     *        [I12HK7MPO1UQDA] => Completed
     *        [I1IA7R6JKTC3L2] => Completed
     *    )
     *
     * @param string $distributionId Distribution ID from listDistributions()
     * @return array|bool
     * @throws InternalError
     */
    public static function getDistributionInvalidationList(string $distributionId): bool|array
    {
        if (!extension_loaded('openssl')) {
            self::__triggerError(sprintf("S3::getDistributionInvalidationList(): [%s]",
                "CloudFront functionality requires SSL"));
            return false;
        }
        $useSSL = self::$useSSL;
        self::$useSSL = true; // CloudFront requires SSL
        $rest = new S3Request('GET', '', '2010-11-01/distribution/' . $distributionId . '/invalidation', 'cloudfront.amazonaws.com');
        $rest = self::__getCloudFrontResponse($rest);
        self::$useSSL = $useSSL;
        if ($rest->error === false && $rest->code !== 200)
            $rest->error = ['code' => $rest->code, 'message' => 'Unexpected HTTP status'];
        if ($rest->error !== false) {
            trigger_error(sprintf("S3::getDistributionInvalidationList('{$distributionId}'): [%s] %s",
                $rest->error['code'], $rest->error['message']), E_USER_WARNING);
            return false;
        } elseif ($rest->body instanceof SimpleXMLElement && isset($rest->body->InvalidationSummary)) {
            $list = [];
            foreach ($rest->body->InvalidationSummary as $summary)
                $list[(string)$summary->Id] = (string)$summary->Status;
            return $list;
        }
        return [];
    }

    /**
     * Generate the auth string: "AWS AccessKey:Signature"
     *
     * @param string $string String to sign
     * @return string
     * @internal Used by S3Request::getResponse()
     */
    #[Pure] public static function __getSignature(string $string): string
    {
        return 'AWS ' . self::$__accessKey . ':' . self::__getHash($string);
    }

    /**
     * Set the service endpoint
     *
     * @param string $host Hostname
     * @return void
     */
    public function setEndpoint(string $host): void
    {
        self::$endpoint = $host;
    }
}