<?php declare(strict_types=1);

namespace Swoft\WebSocket\Server;

use ReflectionException;
use ReflectionType;
use Swoft;
use Swoft\Bean\Annotation\Mapping\Bean;
use Swoft\Bean\Annotation\Mapping\Inject;
use Swoft\Log\Helper\CLog;
use Swoft\Session\Session;
use Swoft\WebSocket\Server\Contract\MessageHandlerInterface;
use Swoft\WebSocket\Server\Contract\MiddlewareInterface;
use Swoft\WebSocket\Server\Contract\RequestInterface;
use Swoft\WebSocket\Server\Contract\ResponseInterface;
use Swoft\WebSocket\Server\Exception\WsMessageParseException;
use Swoft\WebSocket\Server\Exception\WsMessageRouteException;
use Swoft\WebSocket\Server\Message\Message;
use Swoft\WebSocket\Server\Message\Request;
use Swoft\WebSocket\Server\Message\Response;
use Swoft\WebSocket\Server\Router\Router;
use Swoole\WebSocket\Frame;
use Throwable;
use function server;

/**
 * Class WsMessageDispatcher
 *
 * @since 2.0
 *
 * @Bean("wsMsgDispatcher")
 */
class WsMessageDispatcher implements MiddlewareInterface
{
    /**
     * @Inject("wsRouter")
     * @var Router
     */
    private $router;

    /**
     * Pre-check whether the route matches successfully.
     * True  - Check if the status matches successfully after matching.
     * False - check the status after the middleware process
     *
     * @var bool
     */
    private $preCheckRoute = true;

    /**
     * User defined global middlewares
     *
     * @var array
     */
    private $middlewares = [];

    /**
     * User defined global pre-middlewares
     *
     * @var array
     */
    private $preMiddlewares = [];

    /**
     * User defined global after-middlewares
     *
     * @var array
     */
    private $afterMiddlewares = [];

    /**
     * Dispatch ws message handle
     *
     * @param array    $module
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     * @throws ReflectionException
     * @throws WsMessageParseException
     * @throws WsMessageRouteException
     */
    public function dispatch(array $module, Request $request, Response $response): Response
    {
        /** @var Connection $conn */
        $conn  = Session::current();
        $frame = $request->getFrame();

        CLog::info('Message: message data parser is %s', $conn->getParserClass());

        // Parse message data and dispatch route handle
        try {
            $parser  = $conn->getParser();
            $message = $parser->decode($frame->data);

            // Save Message to request
            $request->setMessage($message);
        } catch (Throwable $e) {
            throw new WsMessageParseException("parse message error '{$e->getMessage()}", 500, $e);
        }

        /** @var Router $router */
        $path  = $module['path'];
        $cmdId = $message->getCmd() ?: $module['defaultCommand'];

        $result = $this->router->matchCommand($path, $cmdId);
        $status = $result[0];

        // Storage route info
        $request->set(Request::ROUTE_INFO, $result);

        // Found, get command middlewares
        $middlewares = [];
        if ($status === Router::FOUND) {
            $middlewares = $router->getCmdMiddlewares($command);

            // Append command middlewares
            if ($middlewares) {
                $middlewares = array_merge($this->middlewares, $middlewares);
            }

            // If this->preCheckRoute is True, pre-check route match status
        } elseif ($this->preCheckRoute) {
            throw new WsMessageRouteException("message command '$cmdId' is not found, in module {$path}");
        }

        [$ctlClass, $ctlMethod] = $route['handler'];

        $logMsg = "Message: conn#{$frame->fd} call message command handler '{$ctlClass}::{$ctlMethod}'";
        server()->log($logMsg, $message->toArray(), 'debug');

        $object = Swoft::getSingleton($ctlClass);
        $params = $this->getBindParams($ctlClass, $ctlMethod, $request, $response);
        $result = $object->$ctlMethod(...$params);

        if ($result && $result instanceof Response) {
            $response = $result;
        } elseif ($result !== null) {
            // Set user data and change default opcode
            $response->setData($result);
            $response->setOpcode((int)$route['opcode']);
        }

        return $response;
    }

    protected function dispatchMessage(Request $request, Response $response): ResponseInterface
    {

        return $response;
    }

    /**
     * @param RequestInterface|Request $request
     * @param MessageHandlerInterface  $handler
     *
     * @return ResponseInterface
     * @throws Swoft\Exception\SwoftException
     * @internal for middleware dispatching
     */
    public function process(RequestInterface $request, MessageHandlerInterface $handler): ResponseInterface
    {
        /** @var Response $response */
        $response = context()->getResponse();

        return $this->dispatchMessage($request, $response);
    }

    /**
     * Get method bounded params
     *
     * @param string   $class
     * @param string   $method
     * @param Request  $request
     * @param Response $response
     *
     * @return array
     * @throws ReflectionException
     */
    private function getBindParams(string $class, string $method, Request $request, Response $response): array
    {
        $classInfo = Swoft::getReflection($class);
        if (!isset($classInfo['methods'][$method])) {
            return [];
        }

        // binding params
        $bindParams   = [];
        $methodParams = $classInfo['methods'][$method]['params'];

        /**
         * @var string         $name
         * @var ReflectionType $paramType
         * @var mixed          $devVal
         */
        foreach ($methodParams as [$name, $paramType, $devVal]) {
            // Defined type of the param
            $type = $paramType ? $paramType->getName() : '';

            if ($type === 'string' && $name === 'data') {
                $bindParams[] = $request->getRawData();
            } elseif ($type === Frame::class) {
                $bindParams[] = $request->getFrame();
            } elseif ($type === Message::class) {
                $bindParams[] = $request->getMessage();
            } elseif ($type === Request::class) {
                $bindParams[] = $request;
            } elseif ($type === Response::class) {
                $bindParams[] = $response;
            } else {
                $bindParams[] = null;
            }
        }

        return $bindParams;
    }

    /**
     * @return bool
     */
    public function isPreCheckRoute(): bool
    {
        return $this->preCheckRoute;
    }

    /**
     * @param bool $preCheckRoute
     */
    public function setPreCheckRoute(bool $preCheckRoute): void
    {
        $this->preCheckRoute = $preCheckRoute;
    }

    /**
     * @return array
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * @param string $middleware
     */
    public function addMiddleware(string $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * @param array $middlewares
     */
    public function addMiddlewares(array $middlewares): void
    {
        if ($middlewares) {
            $this->middlewares = array_merge($this->middlewares, $middlewares);
        }
    }

    /**
     * @param array $middlewares
     */
    public function setMiddlewares(array $middlewares): void
    {
        $this->middlewares = $middlewares;
    }

    /**
     * @return array
     */
    public function getPreMiddlewares(): array
    {
        return $this->preMiddlewares;
    }

    /**
     * @param array $preMiddlewares
     */
    public function setPreMiddlewares(array $preMiddlewares): void
    {
        $this->preMiddlewares = $preMiddlewares;
    }

    /**
     * @return array
     */
    public function getAfterMiddlewares(): array
    {
        return $this->afterMiddlewares;
    }

    /**
     * @param array $afterMiddlewares
     */
    public function setAfterMiddlewares(array $afterMiddlewares): void
    {
        $this->afterMiddlewares = $afterMiddlewares;
    }

    /**
     * merge all middlewares
     *
     * @param array $middlewares
     *
     * @return array
     */
    protected function mergeMiddlewares(array $middlewares): array
    {
        if ($middlewares) {
            return array_merge($this->preMiddlewares, $middlewares, $this->afterMiddlewares);
        }

        return array_merge($this->preMiddlewares, $this->afterMiddlewares);
    }
}
