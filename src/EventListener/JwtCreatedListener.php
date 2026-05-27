<?php

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Adds userId to JWT so the socket.io server can join user:{id} rooms.
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
final class JwtCreatedListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $id = $user->getId();
        if ($id === null) {
            return;
        }

        $payload = $event->getData();
        $payload['userId'] = $id;
        $event->setData($payload);
    }
}
