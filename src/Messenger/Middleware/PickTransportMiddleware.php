<?php

declare(strict_types=1);

namespace ADS\Bundle\EventEngineBundle\Messenger\Middleware;

use ADS\Bundle\EventEngineBundle\Command\Command;
use ADS\Bundle\EventEngineBundle\Event\Event;
use ADS\Bundle\EventEngineBundle\Message\Message;
use ADS\Bundle\EventEngineBundle\Messenger\Queueable;
use EventEngine\Messaging\Message as EventEngineMessage;
use EventEngine\Messaging\MessageBag;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

class PickTransportMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        /** @var EventEngineMessage|Message $eventEngineMessage */
        $eventEngineMessage = $envelope->getMessage();
        $message = $eventEngineMessage instanceof Message
            ? $eventEngineMessage
            : $eventEngineMessage->get(MessageBag::MESSAGE);

        $eventEngineMessage = $eventEngineMessage instanceof EventEngineMessage ? $eventEngineMessage : null;
        $sendAsync = $eventEngineMessage?->getMetaOrDefault('async', null) ?? $message instanceof Queueable;

        if ($eventEngineMessage instanceof MessageBag && $sendAsync) {
            $envelope = $envelope->with(new TransportNamesStamp([$eventEngineMessage->messageType()]));
        }

        if ($message instanceof Queueable && $sendAsync) {
            $transport = match (true) {
                $message instanceof Command => 'command',
                $message instanceof Event => 'event',
                default => 'query',
            };

            $envelope = $envelope->with(new TransportNamesStamp([$transport]));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
