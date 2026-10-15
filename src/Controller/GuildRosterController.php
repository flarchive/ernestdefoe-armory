<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The configured guild's full member roster — see Armory::guildRoster()
 * (client credentials, cached an hour, connected-realm aware).
 */
class GuildRosterController implements RequestHandlerInterface
{
    public function __construct(protected Armory $armory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->armory->guildRoster();

        if (! is_array($data)) {
            return new JsonResponse(['ok' => false, 'error' => 'unavailable']);
        }

        return new JsonResponse(['ok' => true] + $data);
    }
}
