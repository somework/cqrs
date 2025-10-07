<?php

declare(strict_types=1);

namespace Symfony\Component\Messenger\Stamp;

if (!class_exists('Symfony\\Component\\Messenger\\Stamp\\SendMessageToTransportsStamp')) {
    final class SendMessageToTransportsStamp implements StampInterface
    {
        /**
         * @param list<string> $transports
         */
        public function __construct(private readonly array $transports)
        {
        }

        /**
         * @return list<string>
         */
        public function getTransports(): array
        {
            return $this->transports;
        }

        /**
         * @return list<string>
         */
        public function getTransportNames(): array
        {
            return $this->transports;
        }
    }
}
