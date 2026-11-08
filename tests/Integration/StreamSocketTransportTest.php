<?php

declare(strict_types=1);

/*
 * This file is part of the UtafitiLabs IMAP Bundle.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace UtafitiLabs\ImapBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use UtafitiLabs\ImapBundle\Client\StreamSocketTransport;
use UtafitiLabs\ImapBundle\Exception\ImapConnectionException;

/**
 * Talks TLS to a local PHP server holding a self-signed certificate for
 * "localhost": certificate and host name are always verified, and the only
 * way to trust a private certificate is to name it as the CA file.
 */
final class StreamSocketTransportTest extends TestCase
{
    private static string $directory;

    /** @var resource|null */
    private $server;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/utafitilabs_imap_tls_'.getmypid();
        @mkdir(self::$directory);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $request = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
        self::assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $request);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256'], random_int(1, \PHP_INT_MAX));
        self::assertInstanceOf(\OpenSSLCertificate::class, $certificate);

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);
        self::assertIsString($certificatePem);
        self::assertIsString($keyPem);
        file_put_contents(self::$directory.'/ca.pem', $certificatePem);
        file_put_contents(self::$directory.'/server.pem', $certificatePem.$keyPem);
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$directory.'/*') ?: []);
        @rmdir(self::$directory);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    public function testItRefusesACertificateNoTrustedAuthoritySigned(): void
    {
        $port = $this->startServer('implicit');

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessageMatches('/localhost:'.$port.'.*certificate verify failed/s');

        (new StreamSocketTransport())->connect('localhost', $port, true, 5.0);
    }

    public function testItRefusesATrustedCertificateIssuedForAnotherHostName(): void
    {
        $port = $this->startServer('implicit');

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessage("did not match expected CN=`127.0.0.1'");

        (new StreamSocketTransport(self::$directory.'/ca.pem'))->connect('127.0.0.1', $port, true, 5.0);
    }

    public function testItTalksToAVerifiedServer(): void
    {
        $port = $this->startServer('implicit');
        $transport = new StreamSocketTransport(self::$directory.'/ca.pem');
        $transport->connect('localhost', $port, true, 5.0);

        self::assertSame("* OK [CAPABILITY IMAP4rev1] test ready\r\n", $transport->readLine(5.0, 1024));
        $transport->write("A0002 NOOP\r\n", 5.0);
        self::assertSame("A0002 OK A0002 NOOP {5}\r\n", $transport->readLine(5.0, 1024));
        self::assertSame('hello', $transport->read(5, 5.0));
        self::assertSame("\r\n", $transport->readLine(5.0, 1024));
        self::assertNull($transport->readLine(0.2, 1024));

        $transport->close();
    }

    public function testItRefusesALineLongerThanTheCap(): void
    {
        $port = $this->startServer('implicit');
        $transport = new StreamSocketTransport(self::$directory.'/ca.pem');
        $transport->connect('localhost', $port, true, 5.0);

        $this->expectException(ImapConnectionException::class);
        $transport->readLine(5.0, 10);
    }

    public function testItUpgradesAPlainConnectionWithStarttls(): void
    {
        $port = $this->startServer('starttls');
        $transport = new StreamSocketTransport(self::$directory.'/ca.pem');
        $transport->connect('localhost', $port, false, 5.0);

        self::assertSame("* OK [CAPABILITY IMAP4rev1 STARTTLS] plain ready\r\n", $transport->readLine(5.0, 1024));
        $transport->write("A0001 STARTTLS\r\n", 5.0);
        self::assertSame("A0001 OK Begin TLS negotiation now.\r\n", $transport->readLine(5.0, 1024));
        $transport->enableTls(5.0);

        self::assertSame("* OK [CAPABILITY IMAP4rev1] test ready\r\n", $transport->readLine(5.0, 1024));
    }

    public function testAConnectionToAClosedPortFails(): void
    {
        $this->expectException(ImapConnectionException::class);

        (new StreamSocketTransport())->connect('127.0.0.1', 1, true, 1.0);
    }

    private function startServer(string $mode): int
    {
        $portFile = self::$directory.'/port-'.$mode.'-'.uniqid();
        $this->server = proc_open(
            [\PHP_BINARY, __DIR__.'/../Fixtures/tls-server.php', $mode, self::$directory.'/server.pem', $portFile],
            [],
            $pipes,
        ) ?: null;
        for ($i = 0; $i < 100 && !is_file($portFile); ++$i) {
            usleep(20000);
        }
        usleep(20000);

        return (int) file_get_contents($portFile);
    }
}
