<?php
declare(strict_types=1);

namespace Npf\Core {

    use Composer\Autoload\ClassLoader;
    use JetBrains\PhpStorm\NoReturn;
    use Npf\Exception\DBQueryError;
    use Npf\Exception\GeneralException;
    use Npf\Exception\InternalError;
    use Npf\Exception\UnknownClass;
    use ReflectionClass;
    use ReflectionException;
    use Throwable;
    use Twig\Error\LoaderError;
    use Twig\Error\RuntimeError;
    use Twig\Error\SyntaxError;

    /**
     * Class App
     * @package Core
     * @property Db $db
     * @property Redis $redis
     * @property Session $session
     * @property Cookie $cookie
     * @property Container $container
     * @property Request $request
     * @property Response $response
     * @property View $view
     * @property Profiler $profiler
     * @property Lock $lock
     * @property Library $library
     */
    final class App extends Event
    {
        /**
         * @var array
         */
        private array $models = [];

        /**
         * @var array
         */
        private array $modules = [];

        /**
         * @var array
         */
        private array $config = [];

        /**
         * @var array
         */
        private array $components = [];

        /**
         * @var string Config Path
         */
        private string $configPath;

        /**
         * @var string Root Path
         */
        private string $rootPath = '';

        /**
         * @var string Base Path
         */
        private string $basePath;

        /**
         * @var int Ignore Error
         */
        private int $exceptionRetry = 0;

        /**
         * App constructor.
         * @param string $roles
         * @param string $appEnv
         * @param string $appName
         */
        final public function __construct(
            private string $roles = 'web',
            private string $appEnv = 'local',
            private string $appName = 'defaultApp'
        )
        {
            parent::__construct();
            $this->roles = !empty($roles) ? $roles : 'web';
            $this->basePath = getcwd();
            $this->configPath = sprintf("Config\\%s\\%s\\", ucfirst($this->appEnv), ucfirst($this->appName));
            $this->request = new Request();
        }

        /**
         * Force redirect https if not secure connection
         * @throws DBQueryError
         * @throws InternalError
         * @throws LoaderError
         * @throws RuntimeError
         * @throws SyntaxError
         */
        final public function forceSecure(): self
        {
            if (!$this->request->isSecure() && isset($_SERVER["HTTP_HOST"]) && isset($_SERVER["REQUEST_URI"]))
                $this->redirect(url: "https://{$_SERVER["HTTP_HOST"]}{$_SERVER["REQUEST_URI"]}");
            return $this;
        }

        /**
         * Force redirect https if not secure connection
         * @param string $url
         * @param int $statsCode
         * @throws DBQueryError
         * @throws InternalError
         * @throws LoaderError
         * @throws RuntimeError
         * @throws SyntaxError
         */
        #[NoReturn] final public function redirect(string $url, int $statsCode = 302): void
        {
            if ($this->request->isXHR()) {
                $this->response->header('Go', $url, true);
            } else {
                $this->response->statusCode($statsCode);
                $this->response->header('Location', $url, true);
                if ($statsCode >= 300) {
                    $this->view->setNone();
                    $this->view->lock();
                }
            }
            $this->end();
        }

        /**
         * App Execute
         * @throws Throwable
         */
        #[NoReturn] final public function __invoke(): void
        {
            $this->emit('appStart', [&$this]);
            $route = new Route($this);
            $this->corsSupport();
            $route();
            $this->end();
        }

        /**
         * @throws DBQueryError
         * @throws InternalError
         * @throws LoaderError
         * @throws RuntimeError
         * @throws SyntaxError
         */
        #[NoReturn] final public function end(): void
        {
            $profiler = $this->profiler->fetch();
            $this->emit('appEnd', [&$this, $profiler]);
            $this->commit();
            $this->emit('appBeforeClean', [&$this, $profiler]);
            $this->clean(true, $profiler);
            $this->response->add('profiler', $this->profiler->fetch());
            $this->view->render();
            exit($this->getRoles() === 'daemon' ? 1 : 0);
        }

        /**
         * @param string $name
         * @param bool $ignoreError
         * @return Container
         * @throws InternalError
         */
        final public function config(string $name, bool $ignoreError = false): Container
        {
            if (isset($this->config[$name]))
                return $this->config[$name];
            else {
                $configClass = $this->configPath . $name;
                if ($ignoreError || class_exists($configClass)) {
                    if (!class_exists($configClass))
                        $this->config[$name] = new Container([], true, true);
                    else {
                        $configObj = new $configClass($this);
                        $this->config[$name] = new Container($configObj, true, true);
                    }
                    return $this->config[$name];
                } else
                    throw new InternalError("Config Not Found: '{$name}''");
            }
        }

        /**
         * @throws InternalError
         */
        private function corsSupport(): void
        {
            $origin = $this->request->header('origin', false);
            $allowCors = $this->config('General')->get('corsSupport', false);
            if ($origin && $allowCors) {
                $pass = false;
                if ($allowCors === '*')
                    $pass = true;
                elseif (!is_array($allowCors))
                    $allowCors = [$allowCors];
                if (!$pass && in_array($origin, $allowCors, true))
                    $pass = true;
                if ($pass === true) {
                    $this->response->header('Access-Control-Allow-Origin', $origin, true);
                    $this->response->header('Access-Control-Allow-Credentials', $this->config('General')->get('corsAllowCredentials', 'true'), true);
                    $this->response->header('Access-Control-Allow-Methods', $this->config('General')->get('corsAllowMethod', 'POST,GET,OPTIONS'), true);
                    $this->response->header('Access-Control-Allow-Headers', $this->request->header('access_control_request_headers', $origin), true);
                    $this->response->header('Access-Control-Max-Age', $this->config('General')->get('corsAge', 3600), true);
                }
            }
        }

        /**
         * App Commit
         * @throws DBQueryError
         */
        final public function commit(): self
        {
            $this->emit('beforeCommit', [&$this]);
            $this->dbCommit();

            if (isset($this->components['session']) && $this->components['session'] instanceof Session)
                $session = $this->components['session'];

            if (isset($session) && $session instanceof Session)
                $session->close();

            $this->emit('afterCommit', [&$this]);
            return $this;
        }

        /**
         * DB Commit
         * @throws DBQueryError
         */
        final public function dbCommit(): bool
        {
            $this->emit('dbCommit', [&$this]);
            if (isset($this->components['db']) && $this->components['db'] instanceof Db)
                $db = $this->components['db'];

            if (isset($db) && $db instanceof Db && $db->isConnected()) {
                if ($db->commit()) {
                    $this->emit('commitDone', [&$this]);
                } else {
                    $this->emit('commitFailed', [&$this]);
                }
                return true;
            }
            return true;
        }

        /**
         * App Components Clean Up
         */
        private function clean(bool $event = true, ?array $profiler = [])
        {
            if ($event)
                $this->emit('appBeforeClean', [&$this, $profiler]);
            foreach ($this->components as $name => $component) {
                if (!in_array($name, ['request', 'response', 'profiler', 'view'], true)) {
                    if (method_exists($component, '__destruct'))
                        $component->__destruct();
                    unset($this->components[$name]);
                }
            }
            $this->config = [];
            $this->models = [];
            $this->modules = [];
            if ($event)
                $this->emit('appAfterClean', [&$this, $profiler]);
        }

        /**
         * @return string
         */
        final public function getRootPath(): string
        {
            if (empty($this->rootPath)) {
                $reflection = new ReflectionClass(ClassLoader::class);
                $rootPath = explode('/', str_replace('\\', '/', dirname($reflection->getFileName(), 2)));
                array_pop($rootPath);
                $this->rootPath = implode("/", $rootPath) . '/';
            }
            return $this->rootPath;
        }

        /**
         * @return string
         */
        final public function getRoles(): string
        {
            return $this->roles;
        }

        /**
         * @return string
         */
        final public function getAppEnv(): string
        {
            return $this->appEnv;
        }

        /**
         * @param string|array $rootPaths
         * @return array
         */
        public function genRouteTable(string|array $rootPaths = 'App'): array
        {
            $appPath = $this->getRootPath();
            if (is_string($rootPaths))
                $rootPaths = explode(",", $rootPaths);
            $results = [];
            foreach ($rootPaths as $rootPath) {
                $rootPath = str_replace("\\", "/", $rootPath);
                if (!str_ends_with($rootPath, "/"))
                    $rootPath .= "/";
                $searchs = [];
                $this->searchFile($appPath . $rootPath, "*.php", $searchs, true);
                foreach ($searchs as $key => &$search) {
                    if (str_ends_with($search, "Router.php"))
                        unset($searchs[$key]);
                    $search = str_replace(['\\', $appPath, '/', '.php'], ['/', '', '\\', ''], $search);
                }
                $results = array_merge($results, $searchs);
            }
            return array_values(array_unique($results));
        }

        /**
         * @param string $path
         * @param string $search
         * @param array|null $results
         * @param bool $includePath
         * @param bool $statCache
         * @return array|null
         */
        public function searchFile(string $path, string $search = "*", ?array &$results = [], bool $includePath = false, bool $statCache = false): ?array
        {
            if (!is_array($results))
                $results = [];
            if (is_dir($path)) {
                $files = scandir($path);
                foreach ($files as $value)
                    if ($value != "." && $value != "..") {
                        $scanValue = realpath($path . DIRECTORY_SEPARATOR . $value);
                        if (!is_dir($scanValue) && fnmatch($search, $scanValue, FNM_CASEFOLD))
                            $results[] = $scanValue;
                        else {
                            if ($includePath)
                                $results[] = "{$scanValue}/";
                            $this->searchFile($scanValue, $search, $results, $includePath, true);
                        }
                    }
            }
            if (!$statCache)
                clearstatcache();
            return $results;
        }


        /**
         * @param string $modelName
         * @param array $params
         * @return mixed
         * @throws InternalError
         */
        final public function model(string $modelName, array $params = []): mixed
        {
            $className = "\\Model\\" . $modelName;

            if (isset($this->models[$className])) {
                return $this->models[$className];
            } else {
                if (class_exists($className)) {
                    $parameters = [&$this];
                    if (!empty($params) && !is_array($params)) {
                        $parameters[] = &$params;
                    } elseif (!empty($params) && is_array($params)) {
                        foreach ($params as &$item)
                            $parameters[] = &$item;
                    }
                    try {
                        $refClass = new ReflectionClass($className);
                        $object = $refClass->newInstanceArgs($parameters);
                        if ($object instanceof Model) {
                            $this->models[$className] = $object;
                            return $object;
                        } else {
                            throw new InternalError("Model Invalid: {$className}");
                        }
                    } catch (ReflectionException $ex) {
                        throw new InternalError($ex->getMessage());
                    }
                } else {
                    throw new InternalError("Model Not Found: {$className}");
                }
            }
        }

        /**
         * @param string $moduleName
         * @param array $params
         * @return mixed
         * @throws InternalError
         */
        final public function module(string $moduleName, array $params = []): mixed
        {
            $className = "\\Module\\" . $moduleName;
            if (isset($this->modules[$className])) {
                return $this->modules[$className];
            } else {
                if (class_exists($className)) {
                    $parameters = [&$this];
                    if (!empty($params) && !is_array($params)) {
                        $parameters[] = &$params;
                    } elseif (!empty($params) && is_array($params)) {
                        foreach ($params as &$item)
                            $parameters[] = &$item;
                    }
                    try {
                        $refClass = new ReflectionClass($className);
                        $object = $refClass->newInstanceArgs($parameters);
                        $this->modules[$className] = $object;
                        return $object;
                    } catch (ReflectionException $ex) {
                        throw new InternalError($ex->getMessage());
                    }
                } else {
                    throw new InternalError("Module Not Found: {$className}");
                }
            }
        }

        /**
         * Setup View File
         * @param string $viewType
         * @param array $twigExtension
         * @return App
         * @throws InternalError
         */
        final public function view(string $viewType, array $twigExtension = []): self
        {
            $viewInfo = null;
            if (!in_array($viewType, ['json', 'xml', 'none', 'static', 'twig'], true)) {
                $viewInfo = $viewType;
                $viewType = 'twig';
            }
            $this->view->setView($viewType, $viewInfo, 2);
            $this->view->addTwigExtension($twigExtension);
            return $this;
        }

        /**
         * @param int $seek
         * @return array|null
         */
        public final function getCallerInfo(int $seek = 1): array|null
        {
            $bt = debug_backtrace();
            return !isset($bt[$seek]['file']) ? null : ['file' => str_replace('\\', '/', $bt[$seek]['file']), 'line' => $bt[$seek]['line'], 'class' => $bt[$seek]['class'] ?? null, 'function' => $bt[$seek]['function'] ?? null];
        }

        /**
         * @param Throwable $exception
         * @param bool $event
         */
        final public function handleException(Throwable $exception, bool $event = false): void
        {
            try {
                $exitCode = 2;
                if (!$exception instanceof Exception) {
                    $exitCode = 3;
                    $exception = new GeneralException($exception);
                }
                $this->response = $exception->response();
                $this->corsSupport();
                $this->rollback();
                $this->view->error();
                $this->response->add('profiler', $this->profiler->fetch());
                $profiler = $this->response->get('profiler');
                if ($exception->sysLog() || $exception instanceof GeneralException) {
                    $desc = is_string($profiler['desc']) ? $profiler['desc'] : json_encode($profiler['desc'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    $this->profiler->logError($this->response->get('error', ''), "{$desc}\nTrace:\n" . implode(",", $profiler['trace']));
                    if ($event)
                        $this->emit('sysReport', [&$this, $profiler]);
                }
                if ($event) {
                    $this->emit('appException', [&$this, $profiler]);
                    $this->emit('exception', [&$this, $profiler]);
                }
                $this->clean($event, $profiler);
                $this->view->render();
                exit($exitCode);
            } catch (\Exception $ex) {
                if ($this->exceptionRetry < 10) {
                    $this->exceptionRetry++;
                    $this->handleException($ex);
                } else {
                    if ($ex instanceof Exception) {
                        $profiler = $this->response->get('profiler');
                        $message = $profiler['desc'];
                        $exitCode = 2;
                    } else {
                        $message = '';
                        if (method_exists($ex, 'getMessage'))
                            $message = $ex->getMessage();
                        $exitCode = 3;
                    }
                    echo($message);
                    echo("stack trace:\n" . implode("\n", Exception::trace($ex)));
                    exit($exitCode);
                }
            }
        }

        /**
         * App Rollback
         * @return self
         */
        final public function rollback(): self
        {
            $this->emit('beforeRollback', [&$this]);

            $this->dbRollback();

            if (isset($this->components['session']) && $this->components['session'] instanceof Session)
                $session = $this->components['session'];

            if (isset($session) && $session instanceof Session)
                $session->rollback();

            $this->emit('afterRollback', [&$this]);

            return $this;
        }

        /**
         * DB Rollback
         * @return bool
         */
        final public function dbRollback(): bool
        {
            $this->emit('dbRollback', [&$this]);
            if (isset($this->components['db']) && $this->components['db'] instanceof Db)
                $db = $this->components['db'];

            if (isset($db) && $db instanceof Db && $db->isConnected())
                return $db->rollback();
            return true;
        }

        /**
         * Return Debug Trace
         * @param Throwable|null $e
         * @param int $seek
         * @return array
         */
        final public function trace(Throwable $e = null, int $seek = 0): array
        {
            if (!$e instanceof Throwable)
                $e = new Exception();
            $trace = explode("\n", $e->getTraceAsString());
            //remove {main} and caller
            array_shift($trace);
            array_pop($trace);
            $length = count($trace);
            $result = [];

            for ($i = $seek; $i < $length; $i++)
                $result[] = ($i + 1) . '.' . substr($trace[$i], strpos($trace[$i], ' ')); // replace '#someNum' with '$i)', set the right ordering
            return $result;
        }

        /**
         * @param array $error
         */
        final public function handleCritical(array $error): void
        {
            try {
                $trace = ["#1. {$error['file']}:{$error['line']}"];
                $this->profiler->logCritical('PHP Critical', "Error Message: ({$error["type"]})\n{$error["message"]}\nTrace:\n" . implode("\n", $trace));
                $profiler = [
                        'desc' => $error["message"],
                        'trace' => $trace,
                        'params' => $this->request->get("*"),
                        'headers' => $this->request->header("*"),
                    ] + $this->profiler->fetch();
                $this->response = new Response([
                    'status' => 'error',
                    'error' => 'critical',
                    'code' => 'FATAL',
                    'profiler' => $profiler,
                ]);
                $this->rollback();
                $this->view->error();
                $this->emit('sysReport', [&$this, $profiler]);
                $this->emit('criticalError', [&$this, $profiler]);
                $this->emit('critical', [&$this, $profiler]);
                $this->clean(true, $profiler);
                $this->view->render();
            } catch (Throwable) {
                exit(6);
            }
            exit(5);
        }

        /**
         * Create a new DB Component
         * @param Container|string $host
         * @param int $port
         * @param string $user
         * @param string $pass
         * @param string $name
         * @param bool $event
         * @param int $timeOut
         * @param string $characterSet
         * @param string $collate
         * @param bool $persistent
         * @return Db
         * @throws DBQueryError
         * @throws InternalError
         * @throws UnknownClass
         */
        final public function createDb(string|Container $host = 'localhost',
                                       int              $port = 3306, string $user = 'root', string $pass = '',
                                       string           $name = '', bool $event = false,
                                       int              $timeOut = 10, string $characterSet = 'UTF8MB4',
                                       string           $collate = 'UTF8MB4_UNICODE_CI', bool $persistent = false): Db
        {
            if ($host instanceof Container)
                $config = $host;
            else {
                $dbConfig = $this->config('Db');
                $config = new Container([
                    'driver' => $dbConfig->get('driver', 'DbMysqli'),
                    'tran' => $dbConfig->get('tran'),
                    'host' => $host,
                    'hosts' => [$host],
                    'port' => $port,
                    'user' => $user,
                    'pass' => $pass,
                    'name' => $name,
                    'event' => $event,
                    'timeOut' => $timeOut,
                    'characterSet' => $characterSet,
                    'collate' => $collate,
                    'persistent' => $persistent,
                ]);
            }
            return new Db($this, $config);
        }

        /**
         * Magic function Get Lazy Load Component
         * @param string $name
         * @return mixed
         * @throws InternalError
         */
        final public function __get(string $name): mixed
        {
            $name = strtolower($name);
            if (!empty($this->components[$name])) {
                return $this->components[$name];
            } elseif (class_exists(($class = "\\Npf\\Core\\" . ucfirst($name)))) {
                $this->components[$name] = (in_array($name, ['container', 'request', 'response'], true)) ? new $class([], false, true) : new $class($this);
                return $this->components[$name];
            } else
                throw new InternalError("Component Not Found: {$name}");
        }

        /**
         * @param string $name
         * @return bool
         */
        final public function __isset(string $name): bool
        {
            return isset($this->components[$name]);
        }

        /**
         * @param string $name
         */
        final public function __unset(string $name): void
        {
            if (isset($this->components[$name]))
                unset($this->components[$name]);
        }

        /**
         * @return string
         */
        final public function getAppName(): string
        {
            return $this->appName;
        }

        /**
         * @return string
         */
        final public function getBasePath(): string
        {
            return $this->basePath;
        }

        /**
         * @param Container $response
         * @return App
         */
        final public function replaceResponse(Container &$response): self
        {
            $this->response = &$response;
            return $this;
        }

        /**
         * Class Destruct, Close DB Connection
         */
        final public function __destruct()
        {
            if (isset($this->db) && is_object($this->db))
                $this->db->close();
            if (isset($this->redis) && is_object($this->redis))
                $this->session->close();
            if (isset($this->redis) && is_object($this->redis))
                $this->redis->close();
        }
    }
}