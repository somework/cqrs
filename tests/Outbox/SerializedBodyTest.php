<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\SerializedBody;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\PayloadStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\UnserializeGadget;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function addslashes;
use function base64_encode;
use function serialize;
use function str_replace;
use function strlen;

#[CoversClass(SerializedBody::class)]
final class SerializedBodyTest extends TestCase
{
    public function test_reads_the_message_class_and_every_class_of_an_envelope(): void
    {
        $body = (new PhpSerializer())->encode(new Envelope(new CreateTaskCommand('1', "it's"), [new BusNameStamp('bus'), new PayloadStamp(new UnserializeGadget())]))['body'];

        self::assertSame([
            'messageClass' => CreateTaskCommand::class,
            'classes' => [Envelope::class, BusNameStamp::class, PayloadStamp::class, UnserializeGadget::class, CreateTaskCommand::class],
            'custom' => [],
        ], SerializedBody::inspect($body));
    }

    public function test_class_names_inside_strings_are_not_objects(): void
    {
        $decoy = "\0message\";O:".strlen(CreateTaskCommand::class).':"'.CreateTaskCommand::class.'"';
        $body = (new PhpSerializer())->encode(new Envelope(new UnserializeGadget(), [new BusNameStamp($decoy)]))['body'];

        self::assertSame(['messageClass' => UnserializeGadget::class, 'classes' => [Envelope::class, BusNameStamp::class, UnserializeGadget::class], 'custom' => []], SerializedBody::inspect($body));
    }

    public function test_reads_a_base64_body(): void
    {
        $body = base64_encode(addslashes(serialize(new Envelope(new CreateTaskCommand('1', "\xff")))));

        self::assertSame(CreateTaskCommand::class, SerializedBody::inspect($body)['messageClass']);
    }

    /**
     * @return iterable<string, array{string, list<string>, 2?: list<string>}>
     */
    public static function values(): iterable
    {
        yield 'signed object length' => [str_replace('O:8:"stdClass"', 'O:+8:"stdClass"', serialize(new \stdClass())), ['stdClass']];
        yield 'custom serialization' => ['C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}', ['ArrayObject'], ['ArrayObject']];
        yield 'serialized with __serialize()' => [serialize(new \ArrayObject([new \stdClass()])), ['ArrayObject', 'stdClass']];
        yield 'enum' => [serialize([\SomeWork\CqrsBundle\Registry\MessageType::Command]), [\SomeWork\CqrsBundle\Registry\MessageType::class]];
        yield 'scalars and references' => [serialize([1, -2, 1.5, true, null, 'x', [3]]), []];
        yield 'escaped string' => ['a:1:{i:0;S:2:"\\41b";}', []];
    }

    /**
     * @param list<string> $classes
     * @param list<string> $custom
     */
    #[DataProvider('values')]
    public function test_reads_every_kind_of_value(string $serialized, array $classes, array $custom = []): void
    {
        self::assertSame(['messageClass' => null, 'classes' => $classes, 'custom' => $custom], SerializedBody::inspect(addslashes($serialized)));
    }

    public function test_an_unreadable_body_lists_every_class_token(): void
    {
        // Another serializer's body names no classes; a malformed payload names them all, conservatively.
        self::assertSame(['messageClass' => null, 'classes' => [], 'custom' => []], SerializedBody::inspect('{"id":"1","name":"x"}'));
        self::assertSame(['messageClass' => null, 'classes' => ['App\Gadget', 'App\Other'], 'custom' => ['App\Other']], SerializedBody::inspect(addslashes('O:10:"App\Gadget":1:{s:1:"a";C:9:"App\Other":broken}')));
    }
}
