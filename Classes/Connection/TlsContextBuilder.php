<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Connection;

final class TlsContextBuilder
{
    /**
     * Build a PHP stream context array for TLS/mTLS.
     *
     * Expected options:
     *  - tls (bool) enable TLS
     *  - ca_file (string) CA file path
     *  - cert_file (string) client certificate path (mTLS) — requires key_file
     *  - key_file (string) client private key path (mTLS) — requires cert_file
     *  - verify_peer (bool) default: true
     *  - verify_peer_name (bool) default: true
     *  - allow_self_signed (bool) default: false
     *  - peer_name (string) override SNI/peer name
     *
     * @throws \InvalidArgumentException if cert_file is set without key_file or vice versa
     * @param array<string, mixed> $options
     *
     * @return array{ssl: array<string, mixed>}|null the stream context, or null when TLS is off
     */
    public function build(array $options): ?array
    {
        if (!(bool) ($options['tls'] ?? false)) {
            return null;
        }

        $ssl = [
            'verify_peer' => (bool) ($options['verify_peer'] ?? true),
            'verify_peer_name' => (bool) ($options['verify_peer_name'] ?? true),
            'allow_self_signed' => (bool) ($options['allow_self_signed'] ?? false),
        ];

        $ca = (string) ($options['ca_file'] ?? '');
        if ('' !== $ca) {
            $ssl['cafile'] = $ca;
        }

        $cert = (string) ($options['cert_file'] ?? '');
        $key = (string) ($options['key_file'] ?? '');
        if ('' !== $cert || '' !== $key) {
            if ('' === $cert || '' === $key) {
                throw new \InvalidArgumentException('mTLS requires both cert_file and key_file to be set.');
            }
            $ssl['local_cert'] = $cert;
            $ssl['local_pk'] = $key;
        }

        $peerName = (string) ($options['peer_name'] ?? '');
        if ('' !== $peerName) {
            // peer_name covers SNI in PHP 5.6+; SNI_enabled/SNI_server_name are deprecated.
            $ssl['peer_name'] = $peerName;
        }

        return ['ssl' => $ssl];
    }
}
