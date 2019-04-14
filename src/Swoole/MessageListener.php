<?php declare(strict_types=1);

namespace Swoft\WebSocket\Server\Swoole;

use Swoft\Bean\Annotation\Mapping\Bean;
use Swoft\Bean\BeanFactory;
use Swoft\Context\Context;
use Swoft\Server\Swoole\MessageInterface;
use Swoft\Session\Session;
use Swoft\SwoftEvent;
use Swoft\WebSocket\Server\Exception\Dispatcher\WsErrorDispatcher;
use Swoft\WebSocket\Server\WsDispatcher;
use Swoft\WebSocket\Server\Context\WsMessageContext;
use Swoft\WebSocket\Server\WsServerEvent;
use Swoole\Websocket\Frame;
use Swoole\Websocket\Server;

/**
 * Class MessageListener
 *
 * @Bean()
 *
 * @since 2.0
 */
class MessageListener implements MessageInterface
{
    /**
     * @param Server $server
     * @param Frame  $frame
     * @throws \Throwable
     */
    public function onMessage(Server $server, Frame $frame): void
    {
        $fd  = $frame->fd;
        $sid = (string)$fd;

        /** @var WsMessageContext $ctx */
        $ctx = WsMessageContext::new($frame);

        // Storage context
        Context::set($ctx);
        // Bind cid => sid(fd)
        Session::bindCo($sid);

        /** @var WsDispatcher $dispatcher */
        $dispatcher = BeanFactory::getSingleton('wsDispatcher');

        try {
            \server()->log("Message: conn#{$fd} received message: {$frame->data}", [], 'debug');
            \Swoft::trigger(WsServerEvent::MESSAGE_BEFORE, $fd, $server, $frame);

            // Parse and dispatch message
            $dispatcher->message($server, $frame);

            \Swoft::trigger(WsServerEvent::MESSAGE_AFTER, $fd, $server, $frame);
        } catch (\Throwable $e) {
            \Swoft::trigger(WsServerEvent::HANDSHAKE_ERROR, $e, $frame);

            \server()->log("Message: conn#{$fd} error: " . $e->getMessage(), [], 'error');

            /** @var WsErrorDispatcher $errDispatcher */
            $errDispatcher = BeanFactory::getSingleton(WsErrorDispatcher::class);
            $errDispatcher->messageError($e, $frame);
        } finally {
            // Defer
            \Swoft::trigger(SwoftEvent::COROUTINE_DEFER);

            // Destroy
            \Swoft::trigger(SwoftEvent::COROUTINE_COMPLETE);

            // Unbind cid => sid(fd)
            Session::unbindCo();
        }
    }
}
