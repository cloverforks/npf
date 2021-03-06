<?php
declare(strict_types=1);

namespace Npf\Library;

use JetBrains\PhpStorm\Pure;
use stdClass;

/**
 * S3 Request class
 *
 * @link http://undesigned.org.za/2007/10/22/amazon-s3-php-class
 * @version 0.5.0-dev
 */
final class S3Request
{
    /**
     * Use HTTP PUT?
     *
     * @access public
     */
    public mixed $fp;
    /**
     * PUT file size
     *
     * @var int
     * @access public
     */
    public int $size = 0;
    /**
     * PUT post fields
     *
     * @var array|bool
     * @access public
     */
    public bool|array|string $data = false;
    /**
     * S3 request respone
     *
     * @var stdClass
     * @access public
     */
    public stdClass $response;
    /**
     * Final object URI
     *
     * @var string
     * @access private
     */
    private string $resource;
    /**
     * Additional request parameters
     *
     * @var array
     * @access private
     */
    private array $parameters = [];
    /**
     * Amazon specific request headers
     *
     * @var array
     * @access private
     */
    private array $amzHeaders = [];
    /**
     * HTTP request headers
     *
     * @var array
     * @access private
     */
    private array $headers = [
        'Host' => '', 'Date' => '', 'Content-MD5' => '', 'Content-Type' => ''
    ];

    /**
     * Constructor
     *
     * @param string $verb Verb
     * @param string $bucket Bucket name
     * @param string $uri Object URI
     * @param string $endpoint AWS endpoint URI
     */
    function __construct(private string $verb,
                         private string $bucket = '',
                         private string $uri = '',
                         private string $endpoint = 's3.amazonaws.com')
    {
        $this->uri = $this->uri !== '' ? '/' . str_replace('%2F', '/', rawurlencode($this->uri)) : '/';
        if ($this->bucket !== '') {
            if ($this->__dnsBucketName($this->bucket)) {
                $this->headers['Host'] = $this->bucket . '.' . $this->endpoint;
                $this->resource = '/' . $this->bucket . $this->uri;
            } else {
                $this->headers['Host'] = $this->endpoint;
                if ($this->bucket !== '') $this->uri = '/' . $this->bucket . $this->uri;
                $this->bucket = '';
                $this->resource = $this->uri;
            }
        } else {
            $this->headers['Host'] = $this->endpoint;
            $this->resource = $this->uri;
        }
        $this->headers['Date'] = gmdate('D, d M Y H:i:s T');
        $this->response = new stdClass();
        $this->response->error = false;
        $this->response->body = null;
        $this->response->headers = [];
    }

    /**
     * Check DNS conformity
     *
     * @param string $bucket Bucket name
     * @return boolean
     */
    private function __dnsBucketName(string $bucket): bool
    {
        if (strlen($bucket) > 63 || preg_match("/[^a-z0-9.-]/", $bucket) > 0) return false;
        if (S3::$useSSL && strstr($bucket, '.') !== false) return false;
        if (strstr($bucket, '-.') !== false) return false;
        if (strstr($bucket, '..') !== false) return false;
        if (!preg_match("/^[0-9a-z]/", $bucket)) return false;
        if (!preg_match("/[0-9a-z]$/", $bucket)) return false;
        return true;
    }

    /**
     * Set request parameter
     *
     * @param string $key Key
     * @param string|null $value Value
     * @return void
     */
    public function setParameter(string $key, ?string $value)
    {
        $this->parameters[$key] = $value;
    }

    /**
     * Set request header
     *
     * @param string $key Key
     * @param string|null $value Value
     * @return void
     */
    public function setHeader(string $key, ?string $value)
    {
        $this->headers[$key] = $value;
    }

    /**
     * Set x-amz-meta-* header
     *
     * @param string $key Key
     * @param string|null $value Value
     * @return void
     */
    public function setAmzHeader(string $key, ?string $value)
    {
        $this->amzHeaders[$key] = $value;
    }

    /**
     * Get the S3 response
     *
     * @return object | bool
     */
    public function getResponse(): object|bool
    {
        if (sizeof($this->parameters) > 0) {
            $query = !str_ends_with($this->uri, '?') ? '?' : '&';
            foreach ($this->parameters as $var => $value)
                if ($value == null || $value == '') $query .= $var . '&';
                else $query .= $var . '=' . rawurlencode($value) . '&';
            $query = substr($query, 0, -1);
            $this->uri .= $query;
            if (array_key_exists('acl', $this->parameters) ||
                array_key_exists('location', $this->parameters) ||
                array_key_exists('torrent', $this->parameters) ||
                array_key_exists('website', $this->parameters) ||
                array_key_exists('logging', $this->parameters))
                $this->resource .= $query;
        }
        $url = (S3::$useSSL ? 'https://' : 'http://') . ($this->headers['Host'] !== '' ? $this->headers['Host'] : $this->endpoint) . $this->uri;
        //var_dump('bucket: ' . $this->bucket, 'uri: ' . $this->uri, 'resource: ' . $this->resource, 'url: ' . $url);
        // Basic setup
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_USERAGENT, 'S3/php');
        if (S3::$useSSL) {
            // Set protocol version
            curl_setopt($curl, CURLOPT_SSLVERSION, S3::$useSSLVersion);
            // SSL Validation can now be optional for those with broken OpenSSL installations
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, S3::$useSSLValidation ? 2 : 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, S3::$useSSLValidation ? 1 : 0);
            if (S3::$sslKey !== null) curl_setopt($curl, CURLOPT_SSLKEY, S3::$sslKey);
            if (S3::$sslCert !== null) curl_setopt($curl, CURLOPT_SSLCERT, S3::$sslCert);
            if (S3::$sslCACert !== null) curl_setopt($curl, CURLOPT_CAINFO, S3::$sslCACert);
        }
        curl_setopt($curl, CURLOPT_URL, $url);
        if (S3::$proxy != null && isset(S3::$proxy['host'])) {
            curl_setopt($curl, CURLOPT_PROXY, S3::$proxy['host']);
            curl_setopt($curl, CURLOPT_PROXYTYPE, S3::$proxy['type']);
            if (isset(S3::$proxy['user'], S3::$proxy['pass']) && S3::$proxy['user'] != null && S3::$proxy['pass'] != null)
                curl_setopt($curl, CURLOPT_PROXYUSERPWD, sprintf('%s:%s', S3::$proxy['user'], S3::$proxy['pass']));
        }
        // Headers
        $headers = [];
        $amz = [];
        foreach ($this->amzHeaders as $header => $value)
            if (strlen($value) > 0) $headers[] = $header . ': ' . $value;
        foreach ($this->headers as $header => $value)
            if (strlen($value) > 0) $headers[] = $header . ': ' . $value;
        // Collect AMZ headers for signature
        foreach ($this->amzHeaders as $header => $value)
            if (strlen($value) > 0) $amz[] = strtolower($header) . ':' . $value;
        // AMZ headers must be sorted
        if (sizeof($amz) > 0) {
            //sort($amz);
            usort($amz, [&$this, '__sortMetaHeadersCmp']);
            $amz = "\n" . implode("\n", $amz);
        } else $amz = '';
        if (S3::hasAuth()) {
            // Authorization string (CloudFront stringToSign should only contain a date)
            if ($this->headers['Host'] == 'cloudfront.amazonaws.com')
                $headers[] = 'Authorization: ' . S3::__getSignature($this->headers['Date']);
            else {
                $headers[] = 'Authorization: ' . S3::__getSignature(
                        $this->verb . "\n" .
                        $this->headers['Content-MD5'] . "\n" .
                        $this->headers['Content-Type'] . "\n" .
                        $this->headers['Date'] . $amz . "\n" .
                        $this->resource
                    );
            }
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, [&$this, '__responseWriteCallback']);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, [&$this, '__responseHeaderCallback']);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        // Request types
        switch ($this->verb) {
            case 'PUT':
            case 'POST': // POST only used for CloudFront
                if ($this->fp != false) {
                    curl_setopt($curl, CURLOPT_PUT, true);
                    curl_setopt($curl, CURLOPT_INFILE, $this->fp);
                    if ($this->size >= 0)
                        curl_setopt($curl, CURLOPT_INFILESIZE, $this->size);
                } elseif ($this->data !== false) {
                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $this->data);
                } else
                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
                break;
            case 'HEAD':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
                curl_setopt($curl, CURLOPT_NOBODY, true);
                break;
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'GET':
            default:
                break;
        }
        // Execute, grab errors
        if (curl_exec($curl))
            $this->response->code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        else
            $this->response->error = [
                'code' => curl_errno($curl),
                'message' => curl_error($curl),
                'resource' => $this->resource
            ];
        @curl_close($curl);
        // Parse body into XML
        if (!$this->response->error  && isset($this->response->headers['type']) &&
            $this->response->headers['type'] == 'application/xml' && isset($this->response->body)) {
            $this->response->body = simplexml_load_string($this->response->body);
            // Grab S3 errors
            if (!in_array($this->response->code, [200, 204, 206]) &&
                isset($this->response->body->Code, $this->response->body->Message)) {
                $this->response->error = [
                    'code' => (string)$this->response->body->Code,
                    'message' => (string)$this->response->body->Message
                ];
                if (isset($this->response->body->Resource))
                    $this->response->error['resource'] = (string)$this->response->body->Resource;
                unset($this->response->body);
            }
        }
        // Clean up file resources
        if ($this->fp !== false && is_resource($this->fp)) fclose($this->fp);
        return $this->response;
    }

    /**
     * Sort compare for meta headers
     *
     * @param string $a String A
     * @param string $b String B
     * @return integer
     * @internal Used to sort x-amz meta headers
     */
    #[Pure] private function __sortMetaHeadersCmp(string $a, string $b): int
    {
        $lenA = strpos($a, ':');
        $lenB = strpos($b, ':');
        $minLen = min($lenA, $lenB);
        $ncmp = strncmp($a, $b, $minLen);
        if ($lenA == $lenB) return $ncmp;
        if (0 == $ncmp) return $lenA < $lenB ? -1 : 1;
        return $ncmp;
    }

    /**
     * CURL write callback
     *
     * @param resource &$curl CURL resource
     * @param string &$data Data
     * @return integer
     */
    private function __responseWriteCallback(&$curl, string $data): int
    {
        if (in_array($this->response->code, [200, 206]) && $this->fp)
            return fwrite($this->fp, $data);
        else
            $this->response->body .= $data;
        return strlen($data);
    }

    /**
     * CURL header callback
     *
     * @param resource $curl CURL resource
     * @param string $data Data
     * @return integer
     */
    private function __responseHeaderCallback($curl, string $data): int
    {
        if (($strlen = strlen($data)) <= 2) return $strlen;
        if (str_starts_with($data, 'HTTP'))
            $this->response->code = (int)substr($data, 9, 3);
        else {
            $data = trim($data);
            if (!str_contains($data, ': ')) return $strlen;
            list($header, $value) = explode(': ', $data, 2);
            if ($header == 'Last-Modified')
                $this->response->headers['time'] = strtotime($value);
            elseif ($header == 'Date')
                $this->response->headers['date'] = strtotime($value);
            elseif ($header == 'Content-Length')
                $this->response->headers['size'] = (int)$value;
            elseif ($header == 'Content-Type')
                $this->response->headers['type'] = $value;
            elseif ($header == 'ETag')
                $this->response->headers['hash'] = $value[0] == '"' ? substr($value, 1, -1) : $value;
            elseif (preg_match('/^x-amz-meta-.*$/', $header))
                $this->response->headers[$header] = $value;
        }
        return $strlen;
    }
}