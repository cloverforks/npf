<?php

namespace Npf\Core\Session {

    use Exception;
    use Npf\Core\App;
    use Npf\Core\Container;
    use Npf\Exception\InternalError;
    use SessionHandlerInterface;

    /**
     * Class SessionRedis
     * @package Npf\Core\Session
     */
    class SessionRedis implements SessionHandlerInterface
    {

        /**
         * @var App
         */
        private $app;

        private $keyPrefix;
        private $lockAttempt = 3;
        private $lockTime = 60;
        private $lockStatus = false;
        private $lockKey = null;
        private $sessionId = null;
        private $fingerprint = null;
        /**
         * @var Container
         */
        private $config;

        // ------------------------------------------------------------------------

        /**
         * Class constructor
         * @param App $app
         * @param Container $config
         */
        public function __construct(App $app, Container &$config)
        {
            $this->app = &$app;
            $this->config = &$config;
            $this->keyPrefix = $config->get('prefix', 'sess');
        }

        /**
         * Open
         * Sanitizes save_path and initializes connection.
         * @param string $path Server path
         * @param string $name Session cookie name, unused
         * @return    bool
         */
        public function open($path, $name)
        {
            return true;
        }

        /**
         * Read
         * Reads session data and acquires a lock
         * @param string $id Session ID
         * @return    string    Serialized session data
         * @throws InternalError
         */
        public function read($id)
        {
            if ($this->_acquire_lock($id)) {
                $this->sessionId = $id;
                $data = $this->app->redis->get($this->keyPrefix . $id);
                if ($data === null) {
                    $this->fingerprint = null;
                    $this->app->redis->set($this->keyPrefix . $id, '', $this->config->get('sessionTtl', 10800));
                } else
                    $this->fingerprint = sha1($data);
                return $data;
            } else
                throw new InternalError("Unable to read session");
        }

        /**
         * Get lock
         * Acquires an (emulated) lock.
         * @param string $sessionId Session ID
         * @return    bool
         */
        private function _acquire_lock($sessionId)
        {
            if (!empty($this->lockKey))
                return $this->app->redis->expire($this->lockKey, $this->lockTime);

            // 30 attempts to obtain a lock, in case another request already has it
            $lockKey = $this->keyPrefix . $sessionId . ':lock';
            $attempt = 0;
            do {
                if (($ttl = $this->app->redis->ttl($lockKey)) > 0)
                    sleep(1);

                $ret = $this->app->redis->setnx($lockKey, time(), $this->config->get('lockTime', 600));
                if ($ret) {
                    $this->lockKey = $lockKey;
                    break;
                }
            } while (++$attempt < $this->lockAttempt);

            if ($attempt === $this->lockAttempt) {
                $this->app->profiler->logInfo('Session', 'Session: Unable to obtain lock for ' . $this->keyPrefix . $sessionId . ' after attempts, aborting.');
                return false;
            } elseif ($ttl === -1)
                $this->app->profiler->logInfo('Session', 'Session: Lock for ' . $this->keyPrefix . $sessionId . ' had no TTL, overriding.');

            $this->lockStatus = true;
            return true;
        }

        /**
         * Write
         * Writes (create / update) session data
         * @param string $id Session ID
         * @param string $data Serialized session data
         * @return    bool
         */
        public function write($id, $data)
        {
            // Was the ID regenerated?
            if ($id !== $this->sessionId) {
                if (!$this->_release_lock() || !$this->_acquire_lock($id))
                    return false;
                $this->fingerprint = sha1('');
                $this->sessionId = $id;
            }

            if (!empty($this->lockKey)) {
                $this->app->redis->expire($this->lockKey, $this->lockTime);
                $fingerprint = sha1($data);
                if ($this->fingerprint === null || $this->fingerprint !== $fingerprint) {
                    if ($this->app->redis->set($this->keyPrefix . $id, $data, $this->config->get('sessionTtl', 10800))) {
                        $this->fingerprint = $fingerprint;
                        return true;
                    }
                    return false;
                }
                return $this->app->redis->expire($this->keyPrefix . $id, $this->config->get('sessionTtl', 10800));
            }
            return false;
        }

        /**
         * Release lock
         * Releases a previously acquired lock
         * @return    bool
         */
        private function _release_lock()
        {
            if (!empty($this->lockKey) && $this->lockStatus) {
                if (!$this->app->redis->del($this->lockKey)) {
                    $this->app->profiler->logInfo('Session', 'Session: Error while trying to free lock for ' . $this->lockKey);
                    return false;
                }
                $this->lockKey = NULL;
                $this->lockStatus = false;
            }
            return true;
        }

        /**
         * Close
         * Releases locks and closes connection.
         * @return    bool
         */
        public function close()
        {
            try {
                if ($this->lockStatus === true)
                    $this->_release_lock();
                return true;
            } catch (Exception $e) {
                $this->app->profiler->logInfo('Session', 'Session: Got Exception on close(): ' . $e->getMessage());
            }
            return false;
        }

        /**
         * Destroy
         * Destroys the current session.
         * @param string $id Session ID
         * @return    bool
         */
        public function destroy($id)
        {
            if ($this->lockStatus === true && !empty($this->lockKey)) {
                if (($result = $this->app->redis->del($this->keyPrefix . $id)) !== 1) {
                    $this->app->profiler->logInfo('Session', 'Session: Redis::del() expected to return 1, got ' . var_export($result, true) . ' instead.');
                }
            }
            return true;
        }

        /**
         * Garbage Collector
         * Deletes expired sessions
         * @param int $max_lifetime Maximum lifetime of sessions
         * @return    bool
         */
        public function gc($max_lifetime)
        {
            // Not necessary, Redis takes care of that.
            return true;
        }

        /**
         */
        public function __destruct()
        {
            if ($this->lockStatus === true)
                $this->_release_lock();
            return true;
        }
    }
}
