<?php

namespace Npf\Core {

    use Npf\Exception\InternalError;
    use SessionHandlerInterface;

    /**
     * Class Session
     * @package Npf\Core
     */
    class Session
    {
        /**
         * @var App
         */
        private $app;

        /**
         * @var Container
         */
        private $config;

        /**
         * @var bool
         */
        private $status = false;

        /**
         * Session constructor.
         * @param App $app
         * @throws InternalError
         */
        public function __construct(App $app)
        {
            $this->app = &$app;
            $this->config = $app->config('Session');
        }

        /**
         * Session Get Data
         * @param string $name
         * @param null $default
         * @param string $separator
         * @return mixed|null
         * @throws InternalError
         */
        public function get($name = null, $default = null, $separator = '.')
        {
            if (!$this->status)
                $this->start();

            $data = $_SESSION;
            if ($name === null || $name === '*')
                return $data;
            elseif ($separator && strpos($name, $separator)) {
                $parts = explode($separator, $name);
                while ($key = array_shift($parts)) {
                    if (isset($data[$key]))
                        $data = $data[$key];
                    elseif ($key)
                        return $default;
                    else
                        break;
                }
                return !$parts ? $data : $default;
            } else
                return isset($data[$name]) ? $data[$name] : $default;
        }

        /**
         * Start PHP Session
         * @throws InternalError
         */
        public function start()
        {
            if (!$this->status) {
                if (!$this->config->get('enable', false))
                    return false;
                $driver = "Npf\\Core\\Session\\Session" . $this->config->get('driver', 'Php');
                if ($driver !== 'Npf\\Core\\Session\\SessionPhp') {
                    if (!class_exists($driver))
                        throw new InternalError('Session Driver Not Found.', $driver);
                    $handler = new $driver($this->app, $this->config);
                    if (!$handler instanceof SessionHandlerInterface)
                        throw new InternalError('Session Driver signature is invalid.');
                    session_set_save_handler($handler, true);
                }

                session_set_cookie_params(
                    $this->config->get('cookieLifetime', 0),
                    $this->config->get('cookiePath', null),
                    $this->config->get('cookieDomain', null),
                    $this->config->get('cookieSecurity', false),
                    $this->config->get('cookieHttpOnly', true)
                );
                $sessionName = $this->config->get('name', 'PHPSESSID');
                session_name($sessionName);

                if ($this->app->request->get('sessionid'))
                    $_COOKIE[$sessionName] = $this->app->request->get('sessionid');

                if (session_start()) {
                    $this->status = true;
                    return true;
                } else
                    throw new InternalError("Unable to start session");
            }
            return false;
        }

        /**
         * Session Set Data
         * @param $name
         * @param $value
         * @param string $separator
         * @return  bool
         * @throws InternalError
         */
        public function set($name, $value, $separator = '.')
        {
            if (!$this->status)
                $this->start();

            $data = &$_SESSION;
            if ($separator && strpos($name, $separator)) {
                $parts = explode($separator, $name);
                $lastKey = array_pop($parts);
                while ($key = array_shift($parts)) {
                    if (!isset($data[$key]))
                        $data[$key] = [];
                    $data = &$data[$key];
                }
                $data[$lastKey] = $value;
            } else
                $data[$name] = $value;
            return true;
        }

        /**
         * Session Delete Key
         * @param string $name
         * @param string $separator
         * @return bool
         * @throws InternalError
         */
        public function del($name, $separator = '.')
        {
            if (!$this->status)
                $this->start();

            $data = &$_SESSION;
            if ($separator && strpos($name, $separator)) {
                $parts = explode($separator, $name);
                $lastKey = array_pop($parts);
                while ($key = array_shift($parts)) {
                    if (!isset($data[$key]))
                        return false;
                    $data = &$data[$key];
                }
                unset($data[$lastKey]);
            } else
                unset($data[$name]);
            return true;
        }

        /**
         * Session Clear Data
         * @throws InternalError
         */
        public function clear()
        {
            if (!$this->status)
                $this->start();
            if (isset($_SESSION))
                $_SESSION = [];
            return true;
        }

        /**
         * Session Clear Data
         * @return bool
         */
        public function rollback()
        {
            if (!$this->status)
                session_reset();
            return true;
        }

        /**
         * Session Close
         * @return bool
         */
        public function close()
        {
            if (!$this->status)
                return false;
            session_write_close();
            $this->status = false;
            return true;
        }
    }
}