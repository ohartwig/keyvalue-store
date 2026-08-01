<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Session\Backend;

use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Redis;
use ReflectionProperty;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotFoundException;

/**
 * Was passiert, wenn Redis wegbricht.
 *
 * Ein Session-Backend wird bei jedem Seitenaufruf angefasst. Faellt Redis aus,
 * entscheidet sich hier, ob der Benutzer eine Fehlerseite sieht oder eine
 * kaputte Anmeldung — und ob der naechste Versuch eine Chance hat.
 *
 * Zwei Eigenschaften traegt diese Klasse durch den Ausfall:
 *
 * Eine RedisException wird in eine SessionNotFoundException uebersetzt. TYPO3
 * kennt nur diese; eine durchgereichte RedisException traefe im Kern auf
 * niemanden, der sie faengt, und wuerde aus einem Verbindungsproblem einen
 * fatalen Fehler machen.
 *
 * Und die Verbindung wird dabei verworfen. phpredis merkt sich eine tote
 * Verbindung; ohne das Zuruecksetzen liefe jeder Folgeaufruf in dieselbe tote
 * Verbindung, und das Backend erholte sich erst beim naechsten Prozess.
 *
 * Die Faelle sind hier bewusst als Unit-Tests abgedeckt und nicht funktional:
 * ein echtes Redis dazu zu bringen, mitten im Aufruf wegzubrechen, ist
 * aufwendig und unzuverlaessig — ein Double, das wirft, sagt dasselbe klarer.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueSessionBackendFailurePathsTest extends TestCase
{
    #[Test]
    public function aRedisFailureWhileReadingBecomesASessionNotFound(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willThrowException(new \RedisException('connection lost'));

        $backend = $this->backendWith($redis);

        $this->expectException(SessionNotFoundException::class);
        $backend->get('some-session');
    }

    /**
     * Der eigentliche Punkt: die tote Verbindung wird verworfen.
     *
     * phpredis haelt an einer abgebrochenen Verbindung fest. Ohne das
     * Zuruecksetzen liefe jeder weitere Aufruf in denselben Fehler, bis der
     * Prozess endet — bei einem Worker-Prozess also potenziell sehr lange.
     */
    #[Test]
    public function aRedisFailureDropsTheConnectionSoTheNextCallCanReconnect(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willThrowException(new \RedisException('connection lost'));

        $backend = $this->backendWith($redis);

        try {
            $backend->get('some-session');
        } catch (SessionNotFoundException) {
            // erwartet — hier geht es um den Zustand danach
        }

        $property = new ReflectionProperty($backend, 'redis');
        self::assertNull($property->getValue($backend), 'die tote Verbindung wurde nicht verworfen');
    }

    /**
     * Eine unbekannte Session ist kein Fehler des Backends, sondern eine
     * Auskunft — und muss von einem Verbindungsproblem unterscheidbar bleiben.
     */
    #[Test]
    public function anUnknownSessionIsReportedWithoutDroppingTheConnection(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(false);

        $backend = $this->backendWith($redis);

        try {
            $backend->get('nicht-vorhanden');
            self::fail('SessionNotFoundException erwartet');
        } catch (SessionNotFoundException) {
            // erwartet
        }

        $property = new ReflectionProperty($backend, 'redis');
        self::assertNotNull(
            $property->getValue($backend),
            'eine unbekannte Session darf die Verbindung nicht verwerfen',
        );
    }

    /**
     * Ein Datensatz, der kein Array ergibt, liefert ein leeres Array statt
     * eines Typfehlers.
     *
     * In Redis kann alles Moegliche unter einem Schluessel stehen — ein
     * abgeschnittener Schreibvorgang, ein Fremdeintrag, ein alter Formatstand.
     * Das darf den Seitenaufbau nicht abbrechen.
     */
    #[Test]
    public function aRecordThatIsNotAnArrayBecomesAnEmptyOne(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn('"nur ein String"');

        self::assertSame([], $this->backendWith($redis)->get('some-session'));
    }

    private function backendWith(Redis $redis): KeyValueSessionBackend
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'hostname' => 'irrelevant.test',
            'sessionLifetime' => 3600,
            'hashSessionIds' => false,
        ]);

        $property = new ReflectionProperty($backend, 'redis');
        $property->setValue($backend, $redis);

        return $backend;
    }
}
