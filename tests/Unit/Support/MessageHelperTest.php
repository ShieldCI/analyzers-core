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
}
