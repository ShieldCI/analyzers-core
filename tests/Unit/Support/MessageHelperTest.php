<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\MessageHelper;

class MessageHelperTest extends TestCase
{
    public function testShortMessageReturnedUnchanged(): void
    {
        $message = 'This is a short error message';
        $this->assertSame($message, MessageHelper::sanitizeErrorMessage($message));
    }

    public function testTruncatesLongMessage(): void
    {
        $result = MessageHelper::sanitizeErrorMessage(str_repeat('a', 250));

        $this->assertSame(203, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    public function testRespectsCustomMaxLength(): void
    {
        $result = MessageHelper::sanitizeErrorMessage(str_repeat('b', 150), 100);

        $this->assertSame(103, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    public function testExactMaxLengthNotTruncated(): void
    {
        $message = str_repeat('c', 200);
        $result = MessageHelper::sanitizeErrorMessage($message);

        $this->assertSame($message, $result);
        $this->assertStringEndsNotWith('...', $result);
    }

    public function testEmptyStringReturned(): void
    {
        $this->assertSame('', MessageHelper::sanitizeErrorMessage(''));
    }

    public function testRedactsRedisConnectionString(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('redis://myuser:mypassword@localhost:6379');

        $this->assertStringContainsString('redis://***:***@localhost:6379', $result);
        $this->assertStringNotContainsString('mypassword', $result);
    }

    public function testRedactsMysqlConnectionString(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('mysql://root:secret123@localhost/mydb');

        $this->assertStringContainsString('mysql://***:***@localhost/mydb', $result);
        $this->assertStringNotContainsString('secret123', $result);
    }

    public function testRedactsPasswordParameters(): void
    {
        $cases = [
            'password=secret123' => 'password=***',
            'pwd=mypassword' => 'pwd=***',
            'pass=12345' => 'pass=***',
            'passwd=admin123' => 'passwd=***',
            'PASSWORD=CAPS' => 'PASSWORD=***',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertStringContainsString($expected, MessageHelper::sanitizeErrorMessage("Error: $input"));
        }
    }

    public function testRedactsApiKeysAndTokens(): void
    {
        $cases = [
            'api_key=sk_live_abc123' => 'api_key=***',
            'apikey=1234567890' => 'apikey=***',
            'token=bearer_abc123' => 'token=***',
            'auth_token=xyz789' => 'auth_token=***',
            'bearer abc123xyz' => 'bearer ***',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertStringContainsString($expected, MessageHelper::sanitizeErrorMessage("Auth: $input"));
        }
    }

    public function testRedactsPrivateKeysAndSecrets(): void
    {
        $cases = [
            'private_key=-----BEGIN' => 'private_key=***',
            'secret=supersecret123' => 'secret=***',
            'client_secret=oauth' => 'client_secret=***',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertStringContainsString($expected, MessageHelper::sanitizeErrorMessage("Error: $input"));
        }
    }

    public function testRedactsAwsAccessKeys(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('AWS Error with key AKIAIOSFODNN7EXAMPLE');

        $this->assertStringContainsString('AKIA***', $result);
        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $result);
    }

    public function testRedactsInternalIpAddresses(): void
    {
        $cases = [
            'Connection to 10.0.0.5 failed',
            'Server at 192.168.1.100 unreachable',
            'Host 172.16.0.1 timeout',
            'Network 172.31.255.255 error',
        ];

        foreach ($cases as $input) {
            $this->assertStringContainsString('***.*.*.*', MessageHelper::sanitizeErrorMessage($input));
        }
    }

    public function testPreservesPublicIps(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('Connection to 8.8.8.8 failed');

        $this->assertStringContainsString('8.8.8.8', $result);
    }

    public function testRedactsMultipleSensitivePatternsAtOnce(): void
    {
        $message = 'Failed to connect redis://admin:pass123@10.0.0.5:6379 with token=secret_abc';
        $result = MessageHelper::sanitizeErrorMessage($message);

        $this->assertStringContainsString('redis://***:***@', $result);
        $this->assertStringContainsString('***.*.*.*', $result);
        $this->assertStringContainsString('token=***', $result);
        $this->assertStringNotContainsString('pass123', $result);
        $this->assertStringNotContainsString('secret_abc', $result);
    }

    public function testRedactsThenTruncates(): void
    {
        $message = 'Error with redis://user:password@localhost '.str_repeat('x', 200);
        $result = MessageHelper::sanitizeErrorMessage($message);

        $this->assertStringContainsString('redis://***:***@localhost', $result);
        $this->assertStringNotContainsString('password', $result);
        $this->assertStringEndsWith('...', $result);
        $this->assertLessThanOrEqual(203, strlen($result));
    }

    public function testTruncationDoesNotSplitTwoByteCharacter(): void
    {
        $result = MessageHelper::sanitizeErrorMessage(str_repeat('a', 199).'é'.'tail');

        $this->assertSame(str_repeat('a', 199).'...', $result);
        $this->assertSame(1, preg_match('//u', $result));
    }

    public function testTruncationDoesNotSplitThreeByteCharacter(): void
    {
        $result = MessageHelper::sanitizeErrorMessage(str_repeat('a', 198).'€'.'tail');

        $this->assertSame(str_repeat('a', 198).'...', $result);
        $this->assertSame(1, preg_match('//u', $result));
    }

    public function testTruncationDoesNotSplitFourByteCharacterAtAnyOffset(): void
    {
        // The cut falls after 1, 2 and 3 of the emoji's 4 bytes.
        foreach ([199, 198, 197] as $prefixLength) {
            $result = MessageHelper::sanitizeErrorMessage(str_repeat('a', $prefixLength).'😀'.'tail');

            $this->assertSame(str_repeat('a', $prefixLength).'...', $result, "prefix {$prefixLength}");
            $this->assertSame(1, preg_match('//u', $result), "prefix {$prefixLength}");
        }
    }

    public function testTruncationOnCharacterBoundaryKeepsWholeCharacter(): void
    {
        // 'é' ends exactly at the cut, so nothing is stepped back over.
        $result = MessageHelper::sanitizeErrorMessage(str_repeat('a', 198).'é'.'tail');

        $this->assertSame(str_repeat('a', 198).'é...', $result);
        $this->assertSame(1, preg_match('//u', $result));
    }

    public function testTruncationAtZeroLengthReturnsOnlyEllipsis(): void
    {
        $this->assertSame('...', MessageHelper::sanitizeErrorMessage('é', 0));
    }

    public function testTruncationWithSmallLengthDropsSplitCharacter(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('é€', 3);

        $this->assertSame('é...', $result);
        $this->assertSame(1, preg_match('//u', $result));
    }

    public function testTruncationCutsStrayContinuationBytesWhereTheyFall(): void
    {
        // Invalid input: a run of bare continuation bytes has no character to protect,
        // so the cut stays at the byte budget, as it did before the UTF-8 fix.
        $message = str_repeat('a', 10).str_repeat(chr(0x80), 10);

        $this->assertSame(
            str_repeat('a', 10).str_repeat(chr(0x80), 5).'...',
            MessageHelper::sanitizeErrorMessage($message, 15)
        );
    }

    public function testTruncationOfLeadingStrayContinuationBytesStaysInsideTheString(): void
    {
        $this->assertSame(
            chr(0x80).chr(0x80).'...',
            MessageHelper::sanitizeErrorMessage(str_repeat(chr(0x80), 4), 2)
        );
    }

    public function testNegativeMaxLengthIsTreatedAsZeroForMultibyteInput(): void
    {
        // substr() with a negative length counts from the end of the string, which
        // split 'é' and kept text instead of dropping it.
        $result = MessageHelper::sanitizeErrorMessage('aé', -1);

        $this->assertSame('...', $result);
        $this->assertSame(1, preg_match('//u', $result));
    }

    public function testNegativeMaxLengthIsTreatedAsZeroForAsciiInput(): void
    {
        $this->assertSame('...', MessageHelper::sanitizeErrorMessage('abc', -1));
    }

    public function testNegativeMaxLengthLeavesEmptyStringUnchanged(): void
    {
        $this->assertSame('', MessageHelper::sanitizeErrorMessage('', -1));
    }

    public function testTruncationKeepsWholeCharacterBeforeStrayContinuationByte(): void
    {
        // Invalid input: a complete emoji, then a stray continuation byte at the cut.
        // The step-back must not walk into the emoji and leave its bare lead byte.
        $emoji = chr(0xF0).chr(0x9F).chr(0x98).chr(0x80);
        $result = MessageHelper::sanitizeErrorMessage($emoji.chr(0x80).'tail', 4);

        $this->assertSame($emoji.'...', $result);
        $this->assertSame(1, preg_match('//u', $result));
    }

    public function testTruncationKeepsWholeCharacterBeforeStrayContinuationByteAfterPrefix(): void
    {
        $emoji = chr(0xF0).chr(0x9F).chr(0x98).chr(0x80);
        $result = MessageHelper::sanitizeErrorMessage('a'.$emoji.chr(0x80).'tail', 5);

        $this->assertSame('a'.$emoji.'...', $result);
        $this->assertSame(1, preg_match('//u', $result));
    }

    public function testTruncationKeepsTwoByteCharacterBeforeStrayContinuationByte(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('é'.chr(0x80).'x', 2);

        $this->assertSame('é...', $result);
        $this->assertSame(1, preg_match('//u', $result));
    }

    public function testTruncationDoesNotDropAsciiBeforeStrayContinuationBytes(): void
    {
        $this->assertSame(
            'ab'.chr(0x80).'...',
            MessageHelper::sanitizeErrorMessage('ab'.chr(0x80).chr(0x80), 3)
        );
    }
}
