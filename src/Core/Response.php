<?php
declare(strict_types=1);

namespace Npf\Core {

    use JetBrains\PhpStorm\ArrayShape;

    /**
     * Class Response
     * @package Core
     */
    final class Response extends Container
    {
        /**
         * @var array
         */
        private array $headers = [];
        /**
         * @var bool|int Response Http Status Code
         */
        private int|bool $statusCode = false;
        /**
         * @var array
         */
        private array $initial = [
            'status' => 'ok',
            'error' => '',
            'code' => ''
        ];

        /**
         * Response constructor
         * @param array|null $data
         */
        public function __construct(?array $data = NULL)
        {
            $initial = $this->initial;
            if (is_array($data))
                $initial = array_merge($this->initial, $data);
            parent::__construct($initial, false, true);
        }

        /**
         * @param string $error
         * @param string $desc
         * @param string $code
         * @return self
         */
        public function error(string $error, string $desc = '', string $code = ''): self
        {
            $this->set('status', $error);
            if ($desc)
                $this->set('profiler', ['desc' => $desc]);
            if ($code)
                $this->set('code', $code);
            return $this;
        }

        /**
         * @param array $data
         * @return self
         */
        public function success(array $data = []): self
        {
            $this->set('status', 'ok');
            $this->set('error', '');
            $this->import($data);
            return $this;
        }

        /**
         * Response constructor
         * @param null $statusCode
         * @return bool|int
         */
        public function statusCode($statusCode = null): bool|int
        {
            if (!empty($statusCode)) {
                $statusCode = (int)$statusCode;
                $this->statusCode = $statusCode;
            }
            return $this->statusCode;
        }

        /**
         * Response constructor
         * @param array|null $array
         * @param bool $overwrite
         * @return self
         */
        public function setHeaders(?array $array, bool $overwrite = false): self
        {
            if (!empty($array) && is_array($array)) {
                if (!is_array($this->headers))
                    $this->headers = [];
                foreach ($array as $name => $value)
                    $this->header((string)$name, $value, $overwrite);
            }
            return $this;
        }

        /**
         * Response constructor
         * @return array
         */
        public function getHeaders(): array
        {
            if (!is_array($this->headers))
                $this->headers = [];
            return $this->headers;
        }

        /**
         * Response constructor
         * @param string $name
         * @param mixed $value
         * @param bool $overwrite
         * @return mixed
         */
        public function header(string $name, mixed $value = null, bool $overwrite = false): mixed
        {
            if (!is_array($this->headers))
                $this->headers = [];
            if (!empty($value) && !empty($name) && is_string($name)) {
                if (!$overwrite && isset($this->headers[$name]))
                    return $this->headers[$name];
                if (is_array($value) || is_object($value))
                    $value = json_encode($value);
                $this->headers[$name] = (string)$value;
            }
            return $this->headers[$name] ?? null;
        }

        /**
         * @return array
         */
        #[ArrayShape(['statusCode' => "bool|int", 'body' => "array"])]
        public function fetch(): array
        {
            return [
                'statusCode' => $this->statusCode,
                'body' => $this->__dump()
            ];
        }

        /**
         * @param string $name
         * @param mixed $value
         * @return self
         */
        public function add(string $name, mixed $value): self
        {
            $data = $this->{$name};
            switch (gettype($data)) {
                case 'integer':
                case 'double':
                    $data += $value;
                    break;
                case 'string':
                    $data .= $value;
                    break;
                case 'array':
                    $data = array_merge($data, $value);
                    break;
                default:
                    $data = $value;
            }
            $this->{$name} = $data;
            return $this;
        }

        /**
         * Change a buffer item
         * @param string $name
         * @param mixed $data
         * @return self
         */
        final public function chg(string $name, mixed $data): self
        {
            if (!empty($name))
                $this->{$name} = $data;
            return $this;
        }

        /**
         * Clear buffer
         */
        final public function clear(): self
        {
            parent::clear();
            $this->import($this->initial);
            return $this;
        }
    }
}