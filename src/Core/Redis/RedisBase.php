<?php
declare(strict_types=1);

namespace Npf\Core\Redis {

    use Exception;
    use Npf\Core\App;
    use Npf\Exception\InternalError;

    /**
     * Class RedisBase
     *
     * Key Timing
     * @method ttl(string $name): int
     * @method pTtl(string $name): int
     * @method expireAt(string $name, int $timestamp)
     * @method pExpire(string $name, int $milliSecond)
     * @method pExpireAt(string $name, int $milliSecondTimestamp)
     * @method expire(string $name, int $second): boolean
     *
     * Standard key
     * @method move(string $name, int $dbIndex): int
     * @method object(string $method, string $name): mixed
     * @method type(string $name): string
     * @method exists(string $name): int
     * @method persist(string $name): int
     * @method strLen(string $name): int
     * @method pSetEx(string $name, int $milliSec): bool
     * @method getSet(string $name, string $value): mixed
     * @method append(string $name, string $value): int
     * @method randomKey(): string
     * @method rename(string $oriKey, string $newKey): boolean
     * @method renameNx(string $oriKey, string $newKey): boolean
     * @method incr(string $name): int
     * @method incrBy(string $name, int $value): int
     * @method incrByFloat(string $name, float $value): float
     * @method decr(string $name): int
     * @method decrBy(string $name, int $value): int
     * @method getRange(string $name, int $start, int $end)
     * @method setRange(string $name, int $offset, string $value)
     * @method getBit(string $name, int $offset): int
     * @method setBit(string $name, int $offset, int $value): int
     *
     * M Series (Multi)
     * @method mGet(string ...$name): array
     * @method mSet(string ...$args): bool
     * @method mSetNx(string ...$args): int
     *
     * L Series (List)
     * @method sort(string $name, ...$arg): array
     * @method lPush(string $name, string ...$value): int
     * @method rPush(string $name, string ...$value): int
     * @method lPushX(string $name, string $value): int
     * @method rPushX(string $name, string $value): int
     * @method lPop(string $name): string
     * @method rPop(string $name): string
     * @method lLen(string $name): int
     * @method lRem(string $name, int $index, string $value): int
     * @method lTrim(string $name, int $start, int $end): bool
     * @method lIndex(string $name, int $index): null|bool|string
     * @method lRange(string $name, int $start, int $end): array
     * @method lSet(string $name, int $index, string $value): bool
     * @method lInsert(string $name, string $position, string $search, string $value): int
     *
     * S Series
     * @method sAdd(string $name, string ...$member): int
     * @method sRem(string $name, string ...$member): int
     * @method sCard(string $name): int
     * @method sMove(string $oriName, string $newName, string $member): int
     * @method sPop(string $name, int $count): array
     * @method sRandMember(string $name, int $count): array
     * @method sMembers(string $name): array
     * @method sDiff (string $source, string $destination): array
     * @method sDiffStore(string $name, string $source, string $destination): int
     * @method sIsMember(string $name, string $value): int
     * @method sInter(string $source, string $destination): array
     * @method sInterStore(string $name, string $source, string $destination): int
     * @method sUnion(string $source, string $destination): array
     * @method sUnionStore(string $name, string $source, string $destination): int
     *
     * H Series (Hash Map)
     * @method hGet(string $name, string $field): string|null
     * @method hSet(string $name, string $field, string $value): int
     * @method hSetNx(string $name, string $field, string $value): int
     * @method hDel(string $name, string ...$field): int
     * @method hLen(string $name): int
     * @method hKeys(string $name): array
     * @method hVals(string $name): array
     * @method hExists(string $name, string $field): int
     * @method hIncrBy(string $name, string $field, int $number): int
     * @method hIncrByFloat(string $name, string $field, float $number): float
     * @method hMSet(string $name, string ...$args): bool
     * @method hMGet(string $name, string ...$args): array
     *
     * Z Series (Z Score)
     * @method zAdd(string $name, string ...$args): int
     * @method zRem(string $name, string $member): int
     * @method zRange(string $name, int $start, int $end, string $withScores)
     * @method zRangeByScore(string $name, int $min, int $max, string $withScores): array
     * @method zRevRangeByScore(string $name, int $max, int $min, string $withScores): array
     * @method zCount(string $name, int $min, int $max): int
     * @method zCard(string $name): int
     * @method zScore(string $name, string $member): string
     * @method zRank(string $name, string $member): int|null
     * @method zRevRank(string $name, string $member): int|null
     * @method zInter(int $numberKey, string ...$args): array
     * @method zInterStore(string $destination, int $numberKey, string ...$args): array
     * @method zUnion(int $numberKey, string ...$args): array
     * @method zUnionStore(string $name, int $numberKey, string ...$args): int
     * @method zIncrBy(string $name, int $increment, string $member)
     * @method rPopLPush(string $source, string $destination): string
     *
     * Db Function
     * @method flushDB(int $dbIndex): bool
     * @method flushAll(): bool
     * @method dbSize(): int
     * @method time(): array
     * @method dump(string $name): string
     * @method getLastError()
     * @method clearLastError()
     * @method _prefix()
     *
     * Transaction (Watch/Unwatch)
     * @method watch()
     * @method unwatch()
     *
     * Redis server Control/Info
     * @method info(string $section): string
     * @method save(): bool
     * @method lastSave(): int
     */
    class RedisBase
    {
        private bool $connected = false;
        private mixed $socket = null;
        private string $mode = '';
        private int $rwTimeout = 3;
        private string $lastError = '';
        private bool $trans = false;
        private bool $transError = false;
        private int $bufferSize = 10240;
        private array $errorSocket = [
            'no' => 0,
            'msg' => '',
        ];
        private string $currentHost = '';
        private array $readFnc = ['GETOPTION', 'TIME', 'EXISTS', 'GET', 'GETUNSERIALISE',
            'LASTSAVE', 'GETRANGE', 'STRLEN', 'HGET', 'HLEN', 'HKEYS', 'HVALS', 'HGETALL',
            'HEXISTS', 'LINDEX', 'LGET', 'LLEN', 'LSIZE', 'SCARD', 'SSIZE', 'SDIFF',
            'SCONTAINS', 'SISMEMBER', 'SMEMBERS', 'SGETMEMBERS', 'SUNION', 'ZCARD', 'ZSIZE',
            'ZCOUNT', 'ZRANGE', 'ZREVRANGE', 'ZRANGEBYSCORE', 'ZREVRANGEBYSCORE',
            'ZRANGEBYLEX', 'ZRANK', 'ZREVRANK', 'ZUNION'];

        /**
         * RedisClient constructor.
         * @param App $app
         * @param array $hosts
         * @param string $authPass
         * @param int $db
         * @param int $timeout
         * @param int $rwTimeout
         * @param bool $persistent
         */
        final public function __construct(private App    $app,
                                          private array  $hosts,
                                          private string $authPass,
                                          private int    $db,
                                          private int    $timeout = 10,
                                          int            $rwTimeout = 0,
                                          private bool   $persistent = false)
        {
            if ($rwTimeout != 0)
                $this->rwTimeout = $rwTimeout;
        }

        final public function __destruct()
        {
            $this->disconnect();
        }

        /**
         * Disconnect from redis
         */
        private function disconnect(): void
        {
            if (is_resource($this->socket) && !empty($this->currentHost)) {
                $this->app->profiler->timerStart("redis");
                @fclose($this->socket);
                $this->app->profiler->saveQuery("disconnected {$this->currentHost}", "redis");
            }
            $this->socket = null;
            $this->connected = false;
            $this->currentHost = '';
            $this->mode = '';
            $this->errorSocket = ['no' => 0, 'msg' => ''];
        }

        /**
         * @return bool
         * @throws InternalError
         */
        public function ping(): bool
        {
            return $this->__execmd('ping') === 'PONG';
        }

        /**
         * @return string|array|bool|int|null
         * @throws InternalError
         */
        private function __execmd(): string|array|bool|int|null
        {
            $args = func_get_args();
            $masterOnly = !in_array(strtoupper($args[0]), $this->readFnc, true);
            try {
                $this->lastError = '';
                if (!$this->connected || $masterOnly && $this->mode !== 'master')
                    $this->connect($masterOnly);
                if (!$this->connected)
                    throw new InternalError("not yet connected to redis");
                $args[0] = strtoupper($args[0]);
                if (!$this->__write($args))
                    $this->__errorHandle('Unable write to redis');
                return $this->__read();
            } catch (InternalError) {
                if ($this->lastError !== '')
                    $this->__errorHandle($this->lastError);
            }
            return false;
        }

        /**
         * @param array $arguments
         * @return bool|int
         * @throws InternalError
         */
        private function __write(array $arguments): bool|int
        {
            $raw = '';
            $reqlen = $this->__request($raw, $arguments);
            $raw = "*{$reqlen}\r\n{$raw}";
            return $this->__socketWrite($raw);
        }

        /**
         * @param string $raw
         * @param array $arguments
         * @return int
         */
        private function __request(string &$raw, array $arguments): int
        {
            $count = 0;
            foreach ($arguments as $argument) {
                if (is_array($argument)) {
                    $count += $this->__request($raw, $argument);
                } else {
                    $count++;
                    $argument = (string)$argument;
                    $argLen = strlen($argument);
                    $raw .= "\${$argLen}\r\n{$argument}\r\n";
                }
            }
            return $count;
        }

        /**
         * @param string $data
         * @return bool|int
         * @throws InternalError
         */
        private function __socketWrite(string $data): bool|int
        {
            $retry = 5;
            $bytes_to_write = strlen($data);
            $bytes_written = 0;
            if (100 <= 0)
                $retry = 3;
            while ($bytes_written < $bytes_to_write) {
                if ($retry <= 0)
                    return false;
                $rv = $bytes_written == 0 ? fwrite($this->socket, $data) : fwrite($this->socket,
                    substr($data, $bytes_written));
                if ($rv == 0) {
                    if ($rv === false) {
                        $this->lastError = 'Error while writing data to server.';
                        $this->__errorHandle($this->lastError);
                    }
                    return ($bytes_written == 0 ? false : $bytes_written);
                }
                $bytes_written += $rv;
                $retry--;
            }
            return $bytes_written;
        }

        /**
         * @param string $desc
         * @throws InternalError
         */
        private function __errorHandle(string $desc)
        {
            $this->disconnect();
            throw new InternalError(trim($desc), "REDIS_ERROR");
        }

        /**
         * @param int $times
         * @return int|bool|array|string|null
         * @throws InternalError
         */
        private function __read(int $times = 0): null|int|bool|array|string
        {
            $response = $this->__receive($this->bufferSize);
            if (!$response) {
                if (FALSE === $response) {
                    $this->lastError = 'Error while reading line from the server.';
                    $this->__errorHandle($this->lastError);
                }
                return FALSE;
            }

            $prefix = substr($response, 0, 1);
            $payload = substr($response, 1);

            switch ($prefix) {
                case '+':
                    if (in_array(strtoupper($payload), ['OK', 'QUEUED'], true))
                        return true;
                    return $payload;

                case '-':
                    if ($this->trans)
                        $this->transError = true;
                    $this->lastError = $payload;
                    if (strtoupper(substr($this->lastError, 0, 3)) === 'ERR')
                        $this->lastError = substr($this->lastError, 4);
                    if (empty($this->lastError))
                        $this->__errorHandle($this->lastError);
                    return false;

                case ':':
                    $integer = (int)$payload;
                    return $integer == $payload ? $integer : $payload;

                case '$':
                    $len = intval($payload);
                    if ($len === -1)
                        return null;
                    $bulkData = '';
                    $bytesLeft = ($len += 2);
                    do {
                        $chunk = fread($this->socket, min($bytesLeft, 4096));
                        if ($chunk === false || $chunk === '')
                            $this->lastError = 'Error while reading bytes from the server.';
                        $bulkData .= $chunk;
                        $bytesLeft = $len - strlen($bulkData);
                    } while ($bytesLeft > 0);
                    return substr($bulkData, 0, -2);

                case '*':
                    $count = intval($payload);
                    if ($count === -1)
                        return null;
                    $multiResult = [];
                    for ($i = 0; $i < $count; $i++)
                        $multiResult[] = $this->__read(++$times);
                    return $multiResult;

                default:
                    $this->lastError = "Unknown response prefix: '$prefix'.";
                    $this->__errorHandle($this->lastError);
                    return null;
            }
        }

        /**
         * @param int $len
         * @return bool|int|float|string|array
         */
        private function __receive(int $len = 4096): bool|int|float|string|array
        {
            $response = @fgets($this->socket, $len);
            return is_string($response) ? trim($response) : $response;
        }

        /**
         * Connect to Redis
         * @param bool $masterOnly
         * @return void
         * @throws InternalError
         */
        private function connect(bool $masterOnly = false): void
        {
            $success = $this->__connect($masterOnly);
            if ($this->connected) {
                if (!empty($this->authPass))
                    if (!$this->auth($this->authPass))
                        $success = false;
                if (!$this->select($this->db))
                    $success = false;
            }
            if ($success === false)
                $this->__errorHandle("Connect {$this->currentHost} Failed, {$this->errorSocket['msg']}");
        }

        /**
         * Connect Redis Any or master only
         * @param bool $masterOnly
         * @param int $retry
         * @return bool
         * @throws InternalError
         */
        public function __connect(bool $masterOnly = false, int $retry = 1): bool
        {
            if (!empty($this->hosts)) {
                $hosts = $this->hosts;
                shuffle($hosts);
                foreach ($hosts as $config)
                    if (is_array($config) && count($config) === 2 && isset($config[0]) && isset($config[1]) && (int)$config[1] !== 0 &&
                        $this->__openSocket($config[0], $config[1], $this->timeout, $this->rwTimeout)) {
                        $role = $this->role();
                        $this->mode = $role[0];
                        if ($this->mode === 'master')
                            return true;
                        elseif ($masterOnly)
                            for ($i = 0; $i < 10; $i++)
                                if ($role[3] === 'connected') {
                                    if ($this->__openSocket($role[1], (int)$role[2], $this->timeout, $this->
                                    rwTimeout)) {
                                        $this->mode = 'master';
                                        return true;
                                    } else
                                        return false;
                                } else {
                                    usleep(300000);
                                    $role = $this->role();
                                }
                        else
                            return true;
                    }
                if ($retry > 0) {
                    usleep(500000);
                    return $this->__connect($masterOnly, $retry - 1);
                } else
                    return false;
            } else
                return false;
        }

        /**
         * @param string $host
         * @param int $port
         * @param int $timeout
         * @param int $rwTimeout
         * @return bool
         */
        private function __openSocket(string $host, int $port, int $timeout, int $rwTimeout): bool
        {
            try {
                $this->disconnect();
                $this->currentHost = "tcp://{$host}";
                $this->app->profiler->timerStart("redis");
                $this->socket = $this->persistent ? @pfsockopen($this->currentHost, $port, $this->errorSocket['no'], $this->errorSocket['msg'],
                    $timeout) : @fsockopen($this->currentHost, $port, $this->errorSocket['no'], $this->errorSocket['msg'], $timeout);
                if (!$this->socket || !is_resource($this->socket)) {
                    $this->app->profiler->saveQuery("failed connect {$this->currentHost}", "redis");
                    $this->socket = null;
                    return false;
                }
                if (!stream_set_timeout($this->socket, $rwTimeout, 0)) {
                    $this->app->profiler->saveQuery("failed connect {$this->currentHost}", "redis");
                    $this->disconnect();
                    return false;
                } else {
                    $this->app->profiler->saveQuery("connected {$this->currentHost}", "redis");
                    $this->connected = true;
                    return true;
                }
            } catch (Exception) {
                $this->app->profiler->saveQuery("failed connect {$this->currentHost}", "redis");
                $this->disconnect();
                return false;
            }
        }

        /**
         * Get redis role
         * @return array|bool|null|string
         * @throws InternalError
         */
        private function role(): array|bool|null|string
        {
            if (!$this->connected)
                return false;
            elseif ($this->__write(['ROLE'])) {
                return $this->__read();
            } else
                return false;
        }

        /**
         * Select Redis DB
         * @param string $pass
         * @return array|bool|null|string
         * @throws InternalError
         */
        private function auth(string $pass): array|bool|null|string
        {
            if (!$this->connected)
                return false;
            elseif ($this->__write(['AUTH', $pass])) {
                return $this->__read();
            } else
                return false;
        }

        /**
         * Select Redis DB
         * @param int $db
         * @return array|bool|null|string
         * @throws InternalError
         */
        public function select(int $db): array|bool|null|string
        {
            $this->app->profiler->timerStart("redis");
            if (!$this->connected)
                return false;
            elseif ($this->__write(['SELECT', $db])) {
                $result = $this->__read();
                if ($result)
                    $this->db = $db;
                $this->app->profiler->saveQuery("selectDb: {$db}", "redis");
                return $result;
            } else
                return false;
        }

        /**
         * Start Redis Transaction
         * @return string|array|bool|int|null
         * @throws InternalError
         */
        public function multi(): string|array|bool|int|null
        {
            if (!$this->trans) {
                $this->trans = $this->__execmd('multi');
                return $this->trans;
            } else
                return true;
        }

        /**
         * Commit Trans & Exec all command submit after multi block
         * @return bool
         * @throws InternalError
         */
        public function exec(): bool
        {
            $results = null;
            if (!$this->transError)
                if (!$results = $this->__execmd('exec'))
                    return false;
            $this->trans = false;
            $success = true;
            if (is_array($results) && !empty($results)) {
                foreach ($results as $result) {
                    $result = is_numeric($result) ? (double)$result : $result;
                    if ($result <= 0 && $result === true)
                        $success = false;
                }
            } else {
                return (bool)$results;
            }
            return $success;
        }

        /**
         * Discard or rollback the transaction
         * @return bool
         * @throws InternalError
         */
        public function discard(): bool
        {
            if ($this->trans) {
                if ($this->__execmd('discard')) {
                    $this->trans = false;
                    $this->transError = false;
                    return true;
                } else
                    return false;
            } else
                return true;
        }

        /**
         * @param string $name
         * @return mixed
         * @throws InternalError
         */
        public function getUnserialise(string $name): mixed
        {
            return $this->varUnserialise($this->get($name));
        }

        /**
         * @param string|null $json
         * @return mixed
         */
        private function varUnserialise(?string $json): mixed
        {
            $json = (string)$json;
            return @unserialize($json, ['allowed_classes' => true]);
        }

        /**
         * @param string $name
         * @return string|int|bool|array|null
         * @throws InternalError
         */
        public function get(string $name): string|int|bool|array|null
        {
            return $this->__execmd('get', $name);
        }

        /**
         * @param string $name
         * @param string $value
         * @param int $expired
         * @return bool
         * @throws InternalError
         */
        public function setEx(string $name, string $value, int $expired): bool
        {
            return (boolean)$this->__execmd('setex', $name, $expired, $value);
        }

        /**
         * @param string $name
         * @param string $value
         * @param int|null $lifeTime
         * @param bool $noExists
         * @return bool
         */
        public function setSerialise(string $name, mixed $value, ?int $lifeTime = null, ?bool $noExists = null): bool
        {
            $value = $this->varSerialise($value);
            return (boolean)$this->set($name, $value, $lifeTime, $noExists);
        }

        /**
         * @param mixed $data
         * @return string
         */
        private function varSerialise(mixed $data): string
        {
            return serialize($data);
        }

        /**
         * @param string $name Key Name
         * @param string $value Key Value
         * @param int|null $lifeTime Set key Life Time in second
         * @param bool $noExists Set only key not exists
         * @return mixed
         */
        public function set(string $name, string $value, ?int $lifeTime = null, ?bool $noExists = null): mixed
        {
            $param = ['set', $name, $value];
            if (!empty($lifeTime))
                $param = array_merge($param, ['EX', $lifeTime]);
            if (null !== $noExists)
                $param[] = $noExists ? 'NX' : 'XX';
            return call_user_func_array([$this, '__execmd'], $param);
        }

        /**
         * @param string $name
         * @param array $args
         * @return mixed
         */
        public function __call(string $name, array $args): mixed
        {
            if (!is_array($args) || (array_diff_key($args, array_keys(array_keys
                ($args))))
            )
                $args = [];
            array_unshift($args, $name);
            return call_user_func_array([$this, '__execmd'], $args);
        }

        /**
         * @param string $name
         * @param mixed $value
         * @param int|null $expired
         * @return bool
         */
        public function setNxSerialise(string $name, mixed $value, ?int $expired = null): bool
        {
            $value = $this->varSerialise($value);
            return $this->setNx($name, $value, $expired);
        }

        /**
         * @param string $name
         * @param string $value
         * @param int|null $expired
         * @return bool
         */
        public function setNx(string $name, string $value, ?int $expired = null): bool
        {
            $expired = (int)$expired;
            return (bool)$this->set($name, $value, $expired, true);
        }

        /**
         * @param string $name
         * @return array
         * @throws InternalError
         */
        public function hGetAll(string $name): array
        {
            $data = (array)$this->__execmd('hgetall', $name);
            $result = [];
            $key = "";
            foreach ($data as $index => $value)
                if ($index % 2 === 0)
                    $key = $value;
                else
                    $result[$key] = $value;
            return $result;
        }
    }
}
