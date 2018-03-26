<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2018/3/18
 * Time: 上午2:35
 */

namespace Swoft\WebSocket\Server\Controller;

use Swoft\Http\Message\Server\Request;
use Swoft\Http\Message\Server\Response;
use Swoft\WebSocket\Server\Bean\Annotation\WebSocket;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

/**
 * Class RootController
 * @package Swoft\WebSocket\Server\Controller
 * @WebSocket("/")
 */
class RootController implements HandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function checkHandshake(Request $request, Response $response): array
    {
        return [0, $response];
    }

    /**
     * @param Server $server
     * @param Request $request
     * @param int $fd
     */
    public function onOpen(Server $server, Request $request, int $fd)
    {
        $server->send($fd, 'hello, welcome! :)');
    }

    /**
     * @param Server $server
     * @param Frame $frame
     */
    public function onMessage(Server $server, Frame $frame)
    {
        $server->send($frame->fd, 'hello, I have received your message: ' . $frame->data);
    }

    /**
     * @param Server $server
     * @param int $fd
     */
    public function onClose(Server $server, int $fd)
    {
        $server->send($fd, 'oo, goodbye! :)');
    }
}
