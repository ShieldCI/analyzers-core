<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldCI\AnalyzersCore\Support\MessageHelper;

class MessageHelperTest extends TestCase
{
    private const REDACTION_FAILED = '[error message withheld: redaction failed]';

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

    // ---------------------------------------------------------------------
    // Keyed secrets: one key=value / key: value rule for every sensitive key
    // ---------------------------------------------------------------------

    public function testRedactsAppKey(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('Bad env APP_KEY=base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0MTI=');

        $this->assertSame('Bad env APP_KEY=***', $result);
    }

    public function testRedactsSpaceSeparatedClientSecretWithColon(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('client secret: hunter2');

        $this->assertSame('client secret: ***', $result);
    }

    public function testReplacesNewlinesAndControlCharactersWithASingleSpace(): void
    {
        $this->assertSame('line one line two', MessageHelper::sanitizeErrorMessage("line one\nline two"));
        $this->assertSame('a b c d e', MessageHelper::sanitizeErrorMessage("a\r\nb\tc\x00\x01d\x7Fe"));
        $this->assertSame('x y', MessageHelper::sanitizeErrorMessage("x\r\n\r\n\t\ty"));
    }

    public function testDoesNotTakeAValueFromTheNextLine(): void
    {
        // A value must sit on the same line as its separator. The line break is still
        // folded to a space afterwards, so the output stays on one line.
        $result = MessageHelper::sanitizeErrorMessage("password:\n  host: db");

        $this->assertSame('password:   host: db', $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function underscoreTokenKeys(): array
    {
        return [
            'access_token' => ['access_token=abc123', 'access_token=***'],
            'refresh_token' => ['refresh_token=abc123', 'refresh_token=***'],
            'id_token' => ['id_token=abc123', 'id_token=***'],
        ];
    }

    #[DataProvider('underscoreTokenKeys')]
    public function testRedactsTokenKeysWithAPrefix(string $input, string $expected): void
    {
        $result = MessageHelper::sanitizeErrorMessage("OAuth failed: {$input}&scope=read");

        $this->assertSame("OAuth failed: {$expected}&scope=read", $result);
    }

    public function testRedactsKeyThatContainsSecretBeforeOtherWords(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('aws_secret_access_key=wJalrXUtnFEMI/K7MDENG');

        $this->assertSame('aws_secret_access_key=***', $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function colonSeparatedSecrets(): array
    {
        return [
            'password' => ['password: hunter2', 'password: ***'],
            'token' => ['token: abc123', 'token: ***'],
            'client_secret' => ['client_secret: abc123', 'client_secret: ***'],
        ];
    }

    #[DataProvider('colonSeparatedSecrets')]
    public function testRedactsColonSeparatedSecrets(string $input, string $expected): void
    {
        $this->assertSame("Config error ({$expected})", MessageHelper::sanitizeErrorMessage("Config error ({$input})"));
    }

    public function testRedactsJsonEncodedSecret(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('Response: {"user":"bob","password":"hunter2"}');

        $this->assertSame('Response: {"user":"bob","password":"***"}', $result);
    }

    public function testRedactsWholeQuotedValueContainingSpaces(): void
    {
        $this->assertSame("password='***' rejected", MessageHelper::sanitizeErrorMessage("password='correct horse' rejected"));
        $this->assertSame('password="***" rejected', MessageHelper::sanitizeErrorMessage('password="correct horse" rejected'));
    }

    public function testRedactsQuotedValueHonouringBackslashEscapes(): void
    {
        $bs = chr(92);
        $result = MessageHelper::sanitizeErrorMessage('{"password":"a'.$bs.'"b c'.$bs.$bs.'","user":"bob"}');

        $this->assertSame('{"password":"***","user":"bob"}', $result);
    }

    public function testRedactsUnterminatedQuotedValueToTheEnd(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('Parse error near password="correct horse battery');

        $this->assertSame('Parse error near password="***', $result);
    }

    public function testRedactsPhpArraySyntax(): void
    {
        $result = MessageHelper::sanitizeErrorMessage("Invalid config ['password' => 'hunter2', 'host' => 'db']");

        $this->assertSame("Invalid config ['password' => '***', 'host' => 'db']", $result);
    }

    public function testRedactsCamelCaseJsonKeys(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('{"accessToken":"abc","clientSecret":"def","apiKey":"ghi"}');

        $this->assertSame('{"accessToken":"***","clientSecret":"***","apiKey":"***"}', $result);
    }

    public function testRedactsDottedAndDashedKeys(): void
    {
        $this->assertSame('database.connections.mysql.password=***', MessageHelper::sanitizeErrorMessage('database.connections.mysql.password=hunter2'));
        $this->assertSame('X-Api-Key: ***', MessageHelper::sanitizeErrorMessage('X-Api-Key: abc123'));
        $this->assertSame('DB_PASS=***', MessageHelper::sanitizeErrorMessage('DB_PASS=hunter2'));
    }

    public function testRedactsNestedSecretAssignmentKeepingInnerKey(): void
    {
        $this->assertSame('Auth: api_key=***', MessageHelper::sanitizeErrorMessage('Auth: api_key=sk_live_abc123'));
        $this->assertSame('Auth: bearer ***', MessageHelper::sanitizeErrorMessage('Auth: bearer abc123xyz'));
    }

    public function testRedactsSqlComparisonOnSecretColumn(): void
    {
        $result = MessageHelper::sanitizeErrorMessage("SQLSTATE[42000] (SQL: select * from users where password = 'hunter2')");

        $this->assertSame("SQLSTATE[42000] (SQL: select * from users where password = '***')", $result);
    }

    public function testKeepsMysqlUsingPasswordYesNoIndicator(): void
    {
        $yes = "SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost' (using password: YES)";
        $no = "SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost' (using password: NO)";

        $this->assertSame($yes, MessageHelper::sanitizeErrorMessage($yes));
        $this->assertSame($no, MessageHelper::sanitizeErrorMessage($no));
    }

    // ---------------------------------------------------------------------
    // Connection strings
    // ---------------------------------------------------------------------

    public function testRedactsDsnPasswordContainingSlash(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('Could not connect to mysql://root:pa/ss@db:3306/app');

        $this->assertSame('Could not connect to mysql://***:***@db:3306/app', $result);
    }

    public function testRedactsDsnPasswordUpToTheLastAtSign(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('mysql://root:p@ss/w@rd@db/app failed');

        $this->assertSame('mysql://***:***@db/app failed', $result);
    }

    public function testRedactsDsnWithEmptyUser(): void
    {
        $this->assertSame('redis://***:***@cache:6379', MessageHelper::sanitizeErrorMessage('redis://:s3cret@cache:6379'));
    }

    public function testRedactsUrlWithTokenOnlyUserinfo(): void
    {
        $this->assertSame(
            'fatal: https://***:***@github.com/org/repo.git not found',
            MessageHelper::sanitizeErrorMessage('fatal: https://ghp_abc123@github.com/org/repo.git not found')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function urlsWithoutCredentials(): array
    {
        return [
            'plain path' => ['GET https://example.com/path/to/thing failed'],
            'at sign in path' => ['GET https://example.com/user@x failed'],
            'scoped package path' => ['GET https://registry.npmjs.org/@scope/pkg failed'],
            'email in query' => ['GET https://example.com/path?email=a@b.c failed'],
            'email in query with port' => ['GET https://example.com:8080/path?email=a@b.c failed'],
        ];
    }

    #[DataProvider('urlsWithoutCredentials')]
    public function testLeavesUrlsWithoutCredentialsUnchanged(string $message): void
    {
        $this->assertSame($message, MessageHelper::sanitizeErrorMessage($message));
    }

    public function testRedactsCredentialedUrlWhoseQueryAlsoHasAnAtSign(): void
    {
        // A password may contain '/', so with a ':' in the userinfo the credentials run to
        // the last '@' of the word. That over-redacts the host here, but leaks nothing.
        $result = MessageHelper::sanitizeErrorMessage('GET https://u:p@example.com/x?email=a@b.c failed');

        $this->assertStringNotContainsString('u:p', $result);
        $this->assertSame('GET https://***:***@b.c failed', $result);
    }

    // ---------------------------------------------------------------------
    // Authorization schemes
    // ---------------------------------------------------------------------

    public function testRedactsAuthorizationBasicHeader(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('Request failed with header Authorization: Basic dXNlcjpwYXNz');

        $this->assertSame('Request failed with header Authorization: Basic ***', $result);
    }

    public function testRedactsBareBasicCredentials(): void
    {
        $this->assertSame('sent Basic *** to proxy', MessageHelper::sanitizeErrorMessage('sent Basic dXNlcjpwYXNz to proxy'));
        $this->assertSame('sent basic *** to proxy', MessageHelper::sanitizeErrorMessage('sent basic YWRtaW46c2VjcmV0MTIz to proxy'));
    }

    public function testRedactsAuthorizationBearerHeaderKeepingScheme(): void
    {
        $result = MessageHelper::sanitizeErrorMessage('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abc.def');

        $this->assertSame('Authorization: Bearer ***', $result);
    }

    public function testLeavesAuthorizationWithOnlyASchemeWordUnchanged(): void
    {
        // A scheme word with no token after it carries no credential.
        $this->assertSame('Authorization: Basic', MessageHelper::sanitizeErrorMessage('Authorization: Basic'));
    }

    // ---------------------------------------------------------------------
    // No false positives on benign text
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function benignMessages(): array
    {
        $bs = chr(92);

        return [
            'class not found' => ['Class "App'.$bs.'Models'.$bs.'User" not found'],
            'sql error' => ['SQLSTATE[HY000] [2002] Connection refused (SQL: select * from users where id = 1)'],
            'undefined array key' => ['Undefined array key "token"'],
            'validation message' => ['The password field is required.'],
            'undefined method' => ['Call to undefined method Foo::secret()'],
            'file path' => ['file_get_contents(/var/www/app/config/secrets.php): Failed to open stream'],
            'generic error prefix' => ['Error: Connection refused'],
            'url' => ['https://example.com/path/to/thing'],
            'token mismatch' => ['Token mismatch: expected X'],
            'csrf token mismatch' => ['CSRF token mismatch.'],
            'author is not auth' => ['author: Jane Doe'],
            'bypass is not pass' => ['bypass: enabled'],
            'passed is not pass' => ['passed: 3 of 4'],
            'basic prose' => ['Basic authentication failed for user'],
            'basic short word' => ['Basic AUTH not supported'],
            'static secret call' => ['Foo::secret::bar is invalid'],
            'git ssh remote' => ['git@github.com:org/repo.git: Permission denied'],
        ];
    }

    #[DataProvider('benignMessages')]
    public function testLeavesBenignMessagesUnchanged(string $message): void
    {
        $this->assertSame($message, MessageHelper::sanitizeErrorMessage($message, 1000));
    }

    // ---------------------------------------------------------------------
    // Pathological input
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function pathologicalInputs(): array
    {
        return [
            'repeated password=' => [str_repeat('password=', 12000)],
            'repeated password="' => [str_repeat('password="', 10000)],
            'repeated a:' => [str_repeat('a:', 50000)],
            'repeated double quote' => [str_repeat('"', 100000)],
            'repeated single quote after key' => ['token=\''.str_repeat("'", 100000)],
            'repeated scheme without at sign' => [str_repeat('a://b:', 17000)],
            'repeated scheme after at sign' => ['x@'.str_repeat('a://b:', 17000)],
            'repeated scheme then distant at sign' => [str_repeat('a://b:', 17000).' @'],
            'long key identifier' => [str_repeat('password', 12500).'='],
            'long non-key identifier' => [str_repeat('a', 100000).':x'],
            'repeated basic' => [str_repeat('Basic ', 17000)],
            'repeated pem header' => [str_repeat('-----BEGIN PRIVATE KEY-----', 3800)],
            'repeated jwt prefix' => [str_repeat('eyJ.', 25000)],
            'repeated cookie header' => [str_repeat('Cookie: a=', 10000)],
            'repeated bracket quote' => [str_repeat('["', 50000)],
            'repeated bracket quote after key' => ['secret='.str_repeat('["', 50000)],
            'repeated authorization header' => [str_repeat('Authorization: ', 6700)],
            'repeated mysql command' => [str_repeat('mysql ', 17000)],
            'repeated password flag' => [str_repeat('--password ', 9100)],
            'long cookie name without equals' => ['Cookie: '.str_repeat('a', 100000)],
        ];
    }

    #[DataProvider('pathologicalInputs')]
    public function testPathologicalInputCompletesQuicklyWithoutFailingRedaction(string $input): void
    {
        $this->assertGreaterThanOrEqual(100000, strlen($input));

        $start = $this->cpuSeconds();
        $result = MessageHelper::sanitizeErrorMessage($input, PHP_INT_MAX);
        $elapsed = $this->cpuSeconds() - $start;

        // Each input takes a few milliseconds of CPU; a catastrophic pattern takes seconds
        // or hits the backtrack limit. CPU time is not inflated by other load.
        $this->assertLessThan(5.0, $elapsed);
        $this->assertNotSame(self::REDACTION_FAILED, $result);
    }

    public function testFailsClosedWhenTheRegexEngineGivesUp(): void
    {
        $backtrackLimit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');

        try {
            ini_set('pcre.jit', '0');
            ini_set('pcre.backtrack_limit', '1');

            $result = MessageHelper::sanitizeErrorMessage('password=hunter2 and more text');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
            ini_set('pcre.jit', (string) $jit);
        }

        $this->assertSame(self::REDACTION_FAILED, $result);
    }

    // ---------------------------------------------------------------------
    // Round 2: false positives that must pass through unchanged
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function reviewFalsePositives(): array
    {
        return [
            // A1: file paths and file names
            'A1 middleware path with line' => ['/app/Http/Middleware/VerifyCsrfToken.php:12'],
            'A1 array key then config path' => ['Undefined array key "password" in /app/config/auth.php:12'],
            'A1 service path with line' => ['/var/www/app/Services/PasswordService.php:88'],
            'A1 relative path with line' => ['config/secrets.php:3: syntax error'],
            'A1 yaml file name' => ['secrets.yml: permission denied'],
            'A1 guard file name' => ['TokenGuard.php: Undefined index'],
            'A1 auth file name' => ['auth.php: config missing'],
            // A2: keys that are not secrets, and values that are code
            'A2 max_tokens colon' => ['max_tokens: 4096'],
            'A2 max_tokens equals' => ['max_tokens=4096'],
            'A2 too many tokens' => ['Too many tokens: 5000 > 4096'],
            'A2 token_count' => ['token_count: 12'],
            'A2 TOKEN_TTL' => ['TOKEN_TTL=60'],
            'A2 password_timeout' => ["'password_timeout' => 10800"],
            'A2 passwords broker' => ["'passwords' => 'users'"],
            'A2 auth map' => ["'auth' => ['guard' => 'web']"],
            'A2 secret from env call' => ["'secret' => env('JWT_SECRET')"],
            'A2 secret from static call' => ['"secret" => Config::get($key)'],
            'A2 validation errors json' => ['{"errors":{"password":["The password field is required."]}}'],
            // A3: prose after a colon
            'A3 invalid token' => ['Invalid token: expected string'],
            'A3 unexpected token' => ['Unexpected token: punc ())'],
            'A3 auth failed' => ['auth: failed for user root'],
            'A3 credentials invalid' => ['credentials: invalid'],
            'A3 pass fail counts' => ['pass: 3, fail: 1'],
            'A3 token count' => ['Token: 3 of 4'],
            'A3 password validation sentence' => ['password: The password must be at least 8 characters.'],
            // A4: bare Basic that does not decode to user:password
            'A4 basic word' => ['Basic Laravel123 setup failed'],
            // C1/C4 negatives
            'C1 stripe prefix alone' => ['Set sk_test in your env'],
            'C1 github prefix alone' => ['Tokens look like ghp_ followed by 36 characters'],
            'C1 short github token' => ['ghp_abc is too short'],
            'C1 short slack token' => ['xoxb-123 is invalid'],
            'C1 jwt prefix alone' => ['JWTs start with eyJ'],
            'C4 pdo flag elsewhere' => ['Run phpunit -pdo-tests first'],
            'C4 mysql port flag' => ['mysql -P3306 -uroot app'],
        ];
    }

    #[DataProvider('reviewFalsePositives')]
    public function testLeavesReviewFalsePositivesUnchanged(string $message): void
    {
        $this->assertSame($message, MessageHelper::sanitizeErrorMessage($message, 1000));
    }

    public function testColonFormStopWordValueIsProse(): void
    {
        $this->assertSame('token: expected ")" got "]"', MessageHelper::sanitizeErrorMessage('token: expected ")" got "]"'));
    }

    // ---------------------------------------------------------------------
    // Round 2: leaks
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function reviewLeaks(): array
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig';

        return [
            // B1: bracketed lists and single-entry objects of quoted scalars
            'B1 list after equals' => ['secret=["hunter2"]', 'secret=["***"]'],
            'B1 list after colon' => ['api_key: ["abc123"]', 'api_key: ["***"]'],
            'B1 parenthesised value' => ['password=("hunter2")', 'password=("***")'],
            'B1 value object' => ['"secret":{"value":"hunter2"}', '"secret":{"value":"***"}'],
            'B1 list of two' => ["secret=['a b', 'c']", "secret=['***', '***']"],
            // B2: unquoted values run through quote and brace characters
            'B2 double quote inside' => ['password=ab"cd', 'password=***'],
            'B2 quote and brace inside' => ['DB_PASSWORD=Xy"9}k', 'DB_PASSWORD=***'],
            'B2 brace inside' => ['password=ab}cd', 'password=***'],
            'B2 single quote inside' => ["token=abc'def", 'token=***'],
            'B2 parenthesis inside' => ['password=hunter2(x)', 'password=***)'],
            // B3: Authorization with any scheme
            'B3 github token scheme' => ['Authorization: token ghp_abcdefghijklmnopqrstuvwxyz0123456789', 'Authorization: token ***'],
            'B3 Token scheme' => ['Authorization: Token abc123', 'Authorization: Token ***'],
            'B3 Negotiate scheme' => ['Authorization: Negotiate YIIabc', 'Authorization: Negotiate ***'],
            'B3 AWS signature' => ['Authorization: AWS4-HMAC-SHA256 Credential=AKIA/x, Signature=abc', 'Authorization: AWS4-HMAC-SHA256 ***'],
            'B3 Digest' => ['Authorization: Digest username="u", response="abc"', 'Authorization: Digest ***'],
            'B3 proxy authorization' => ['Proxy-Authorization: Basic dXNlcjpwYXNz', 'Proxy-Authorization: Basic ***'],
            'B3 quoted json header' => ['{"Authorization":"Bearer abc123","Accept":"json"}', '{"Authorization":"Bearer ***","Accept":"json"}'],
            'B3 bare bearer' => ['sent Bearer abc123 upstream', 'sent Bearer *** upstream'],
            // C1: known token prefixes with no key
            'C1 ghp' => ['token ghp_abcdefghijklmnopqrstuvwxyz0123456789 rejected', 'token ghp_*** rejected'],
            'C1 gho' => ['gho_abcdefghijklmnopqrstuvwxyz0123456789', 'gho_***'],
            'C1 ghu' => ['ghu_abcdefghijklmnopqrstuvwxyz0123456789', 'ghu_***'],
            'C1 ghs' => ['ghs_abcdefghijklmnopqrstuvwxyz0123456789', 'ghs_***'],
            'C1 ghr' => ['ghr_abcdefghijklmnopqrstuvwxyz0123456789', 'ghr_***'],
            'C1 github_pat' => ['github_pat_11ABCDEFG0123456789_abcdefghij', 'github_pat_***'],
            'C1 stripe live secret' => ['key sk_live_51Habcdefghijklmnop failed', 'key sk_live_*** failed'],
            'C1 stripe test restricted' => ['rk_test_51Habcdefghijklmnop', 'rk_test_***'],
            'C1 slack bot token' => ['xoxb-1234-5678-abcdef', 'xoxb-***'],
            'C1 jwt' => ["Invalid JWT {$jwt} given", 'Invalid JWT eyJ*** given'],
            'C1 slack webhook' => ['POST https://hooks.slack.com/services/T000/B000/XXXXXXXXXXXXXXXXXXXXXXXX failed', 'POST https://hooks.slack.com/services/*** failed'],
            // C2: cookies and session keys
            'C2 cookie header' => ['Cookie: laravel_session=eyJpdiI6abc; XSRF-TOKEN=def', 'Cookie: laravel_session=***; XSRF-TOKEN=***'],
            'C2 set-cookie header' => ['Set-Cookie: laravel_session=abc; path=/; httponly', 'Set-Cookie: laravel_session=***; path=***; httponly'],
            'C2 bare session key' => ['session laravel_session=abc expired', 'session laravel_session=*** expired'],
            // C3: PEM private key bodies
            'C3 private key' => ['-----BEGIN PRIVATE KEY----- MIIEvQIBADANBg -----END PRIVATE KEY-----', '-----BEGIN PRIVATE KEY----- *** -----END PRIVATE KEY-----'],
            'C3 rsa multi-line' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEow\nIBAAKC\n-----END RSA PRIVATE KEY-----", '-----BEGIN RSA PRIVATE KEY----- *** -----END RSA PRIVATE KEY-----'],
            'C3 ec' => ['x -----BEGIN EC PRIVATE KEY----- MHc -----END EC PRIVATE KEY----- y', 'x -----BEGIN EC PRIVATE KEY----- *** -----END EC PRIVATE KEY----- y'],
            'C3 openssh' => ['-----BEGIN OPENSSH PRIVATE KEY----- b3Bl -----END OPENSSH PRIVATE KEY-----', '-----BEGIN OPENSSH PRIVATE KEY----- *** -----END OPENSSH PRIVATE KEY-----'],
            'C3 encrypted' => ['-----BEGIN ENCRYPTED PRIVATE KEY----- MIIF -----END ENCRYPTED PRIVATE KEY-----', '-----BEGIN ENCRYPTED PRIVATE KEY----- *** -----END ENCRYPTED PRIVATE KEY-----'],
            'C3 unterminated' => ["Bad key: -----BEGIN PRIVATE KEY-----\nMIIEvQ-IBAD", 'Bad key: -----BEGIN PRIVATE KEY----- ***'],
            // C4: CLI password forms
            'C4 mysql attached p' => ['mysql -uroot -phunter2 app', 'mysql -uroot -p*** app'],
            'C4 mysqldump quoted p' => ["mysqldump -u root -p'pa ss' db", 'mysqldump -u root -p*** db'],
            'C4 mysqladmin' => ['mysqladmin -uroot -phunter2 status', 'mysqladmin -uroot -p*** status'],
            'C4 mariadb' => ['mariadb -h db -phunter2', 'mariadb -h db -p***'],
            'C4 password flag with space' => ['mysql --password hunter2', 'mysql --password ***'],
            'C4 password flag with equals' => ['pg_tool --password=hunter2 --verbose', 'pg_tool --password=*** --verbose'],
        ];
    }

    #[DataProvider('reviewLeaks')]
    public function testRedactsReviewLeaks(string $input, string $expected): void
    {
        $this->assertSame($expected, MessageHelper::sanitizeErrorMessage($input, 1000));
    }

    public function testRedactionFailedPlaceholderIsNotPublicApi(): void
    {
        $constant = new \ReflectionClassConstant(MessageHelper::class, 'REDACTION_FAILED');

        $this->assertTrue($constant->isPrivate());
    }

    // ---------------------------------------------------------------------
    // Round 3: leaks and regressions against master
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function roundThreeLeaks(): array
    {
        $bs = chr(92);

        return [
            // 1: key word glued to a prefix
            'R1 PGPASSWORD' => ['The command "PGPASSWORD=hunter2 pg_dump app" failed.', 'The command "PGPASSWORD=*** pg_dump app" failed.'],
            'R1 dbpassword' => ['dbpassword=hunter2', 'dbpassword=***'],
            'R1 newpassword' => ['newpassword=hunter2', 'newpassword=***'],
            'R1 clientsecret' => ['clientsecret=hunter2', 'clientsecret=***'],
            'R1 authtoken' => ['authtoken=hunter2', 'authtoken=***'],
            'R1 oauthtoken' => ['oauthtoken=hunter2', 'oauthtoken=***'],
            'R1 DBPASS' => ['DBPASS=hunter2', 'DBPASS=***'],
            'R1 rootpwd' => ['rootpwd=hunter2', 'rootpwd=***'],
            'R1 REDISPASSWORD' => ['REDISPASSWORD=hunter2', 'REDISPASSWORD=***'],
            'R1 appsecret' => ['appsecret=hunter2', 'appsecret=***'],
            'R1 userpass' => ['userpass=hunter2', 'userpass=***'],
            'R1 glued quoted key' => ["'dbpassword' => 'hunter2'", "'dbpassword' => '***'"],
            // 2: string literal inside a call
            'R2 bcrypt' => ["password=bcrypt('hunter2')", "password=bcrypt('***')"],
            'R2 Hash::make' => ['secret=Hash::make("hunter2")', 'secret=Hash::make("***")'],
            'R2 sha1' => ["password=sha1('hunter2')", "password=sha1('***')"],
            'R2 array bcrypt' => ["'password' => bcrypt('hunter2')", "'password' => bcrypt('***')"],
            // 3: master regressions
            'R3 unquoted list' => ['password=[hunter2]', 'password=[***]'],
            'R3 unquoted object' => ['password={hunter2}', 'password={***}'],
            'R3 two-entry object' => ['password={"v":"hunter2","w":"b"}', 'password={"v":"***","w":"***"}'],
            'R3 double equals' => ['password==hunter2', 'password==***'],
            'R3 bearer tail after quote' => ['Bearer abc"hunter2', 'Bearer ***'],
            'R3 concatenated literals' => ["password='a'.'hunter2'", "password='***'.'***'"],
            // 5: Authorization prose around a real header
            'R5 scheme prose keeps tail' => ['The Authorization: Bearer scheme is required', 'The Authorization: Bearer *** is required'],
            'R5 curl header in quotes' => ['curl -H "Authorization: Bearer abc" x failed', 'curl -H "Authorization: Bearer ***" x failed'],
            'R5 raw token without scheme' => ['Authorization: abc123xyz', 'Authorization: ***'],
            // 6: the enclosing quote stays
            'R6 json cookie key' => ['"Cookie":"laravel_session=abc"', '"Cookie":"laravel_session=***"'],
            'R6 brace kept' => ['{password: hunter2}', '{password: ***}'],
            // 9: SQL identifiers
            'R9 backtick column' => ["select * from `users` where `api_token` = 'hunter2' limit 1", "select * from `users` where `api_token` = '***' limit 1"],
            'R9 backtick unquoted binding' => ['(Connection: mysql, SQL: update `users` set `password` = hunter2 where `id` = 1)', '(Connection: mysql, SQL: update `users` set `password` = *** where `id` = 1)'],
            'R9 sqlsrv bracket column' => ["select * from [users] where [password] = 'hunter2'", "select * from [users] where [password] = '***'"],
            // 10: print_r / var_dump
            'R10 print_r' => ["Array\n(\n    [password] => hunter2\n)\n", 'Array (     [password] => *** ) '],
            'R10 var_dump' => ['["password"]=> string(7) "hunter2"', '["password"]=> string(7) "***"'],
            // 11: _confirmation suffix
            'R11 confirmation form' => ['password=x&password_confirmation=hunter2', 'password=***&password_confirmation=***'],
            'R11 confirmation json' => ['{"password_confirmation":"hunter2"}', '{"password_confirmation":"***"}'],
            // 12: escaped JSON inside a JSON string
            'R12 escaped json' => ['{"data":"{'.$bs.'"password'.$bs.'":'.$bs.'"hunter2'.$bs.'"}"}', '{"data":"{'.$bs.'"password'.$bs.'":'.$bs.'"***'.$bs.'"}"}'],
        ];
    }

    #[DataProvider('roundThreeLeaks')]
    public function testRedactsRoundThreeLeaks(string $input, string $expected): void
    {
        $this->assertSame($expected, MessageHelper::sanitizeErrorMessage($input, 1000));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundThreeFalsePositives(): array
    {
        return [
            // 1: glued key words that are not keys
            'R1 passed' => ['passed=3'],
            // 5: Authorization prose
            'R5 missing header' => ['Missing Authorization: header in request to https://api'],
            'R5 denied' => ['Authorization: denied'],
            // 6: literal non-secrets
            'R6 php null' => ["'secret' => null"],
            'R6 yaml null' => ['token: null'],
            'R6 yaml true' => ['secret: true'],
            'R6 json null' => ['{"password":null}'],
            'R6 json false' => ['{"secret":false}'],
            'R6 yaml tilde' => ['password: ~'],
            'R6 brace not eaten' => ['errors: {password: required}'],
            // 7: Laravel code and prose
            'R7 hashed cast' => ["protected \$casts = ['password' => 'hashed'];"],
            'R7 encrypted cast' => ["'api_token' => 'encrypted'"],
            'R7 validation rules' => ["'password' => 'required|string|min:8|confirmed'"],
            'R7 cookie prose' => ['cookie: name=value pairs must not contain spaces'],
            'R7 password flag prose' => ['Error: --password requires a value'],
            'R7 sql placeholder' => ['update users set remember_token = ? where id = 1'],
            'R7 env lookup kept' => ["'secret' => getenv('JWT_SECRET')"],
            'R7 config lookup kept' => ["'secret' => config('services.jwt.secret')"],
            'R7 superglobal lookup kept' => ["'secret' => \$_ENV['JWT_SECRET']"],
            // 8: colon-form prose
            'R8 mid-message colon prose' => ['Validation failed (password: must be 8+ chars)'],
            'R8 quoted config key' => ['Config key "services.stripe.secret": missing'],
            'R8 json path' => ['Error at path $.credentials.password: expected string'],
            'R8 env not set' => ['DB_PASSWORD: not set'],
            'R8 invalid' => ['token: invalid'],
            // 4: long runs stay intact
            'R4 600 KB word' => ['Error: '.str_repeat('a', 600000)],
            'R4 1 MB word' => ['Error: '.str_repeat('a', 1048576)],
        ];
    }

    #[DataProvider('roundThreeFalsePositives')]
    public function testLeavesRoundThreeFalsePositivesUnchanged(string $message): void
    {
        $this->assertSame($message, MessageHelper::sanitizeErrorMessage($message, PHP_INT_MAX));
    }

    public function testLongDottedNumberRunDoesNotFailClosed(): void
    {
        $result = MessageHelper::sanitizeErrorMessage(str_repeat('10.1.1.', 38600), PHP_INT_MAX);

        $this->assertNotSame(self::REDACTION_FAILED, $result);
        $this->assertStringContainsString('***.*.*.*', $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function largePathologicalSeeds(): array
    {
        $bs = chr(92);

        return [
            'long word' => ['a'],
            'dotted numbers' => ['10.1.1.'],
            'glued keys' => ['dbpassword='],
            'quoted key colon' => ['"a":'],
            'quoted secret key colon' => ['"password":'],
            'secret list' => ['password=['],
            'secret object' => ['password={'],
            'secret call' => ["password=f('"],
            'concatenation' => ["password='a'."],
            'escaped json key' => [$bs.'"password'.$bs.'":'.$bs.'"'],
            'var_dump' => ['["password"]=> string(1) "'],
            'print_r' => ['[password] => '],
            'backtick key' => ['`password` = '],
            'colon prose' => ['password: not '],
            'map list' => ["'auth' => ['a' => "],
            'authorization prose' => ['Authorization: header '],
            'cookie prose' => ['cookie: a=b c '],
            'cookie chain' => ['cookie:'],
            'cookie pair chain' => ['cookie:a='],
            'password flag prose' => ['--password requires a '],
            'bearer quote' => ['Bearer a"'],
            'jwt prefix chain' => ['eyJ-'],
            'spaced password flag' => ['--password = '],
            'repeated secret value' => ['password=hunter2 hunter2 '],
        ];
    }

    #[DataProvider('largePathologicalSeeds')]
    public function testLargePathologicalInputScalesLinearlyWithoutFailingRedaction(string $seed): void
    {
        $small = str_repeat($seed, intdiv(102400, strlen($seed)) + 1);
        $large = str_repeat($seed, intdiv(1048576, strlen($seed)) + 1);

        [$smallElapsed, $smallResult] = $this->fastestOfTwo($small);
        [$largeElapsed, $largeResult] = $this->fastestOfTwo($large);

        $this->assertNotSame(self::REDACTION_FAILED, $smallResult);
        $this->assertNotSame(self::REDACTION_FAILED, $largeResult);

        // About 10x the input should take about 10x the CPU time; a quadratic pattern
        // takes about 100x. A ratio holds whether or not a coverage driver slows every
        // line, and CPU time is not inflated by other load the way wall-clock time is.
        // The best of two runs and the 30x bound absorb what noise remains, and the
        // 3-second floor keeps timer resolution on very fast inputs out of the result.
        $this->assertLessThan(max(30 * $smallElapsed, 3.0), $largeElapsed, sprintf('100 KB: %.3fs, 1 MB: %.3fs', $smallElapsed, $largeElapsed));
        $this->assertLessThan(60.0, $largeElapsed);
    }

    /**
     * @return array{float, string} the lower CPU time of two runs, and the result
     */
    private function fastestOfTwo(string $input): array
    {
        $best = INF;
        $result = '';

        for ($run = 0; $run < 2; $run++) {
            $start = $this->cpuSeconds();
            $result = MessageHelper::sanitizeErrorMessage($input, PHP_INT_MAX);
            $best = min($best, $this->cpuSeconds() - $start);
        }

        return [$best, $result];
    }

    /**
     * CPU time used by this process. Unlike wall-clock time, other processes on a
     * loaded machine do not inflate it.
     */
    private function cpuSeconds(): float
    {
        $usage = getrusage();
        $this->assertIsArray($usage);

        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    }

    // ---------------------------------------------------------------------
    // Round 4: never redact less than master
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function roundFourLeaks(): array
    {
        return [
            // 1: a word in front of a compound key
            'R1 word before api_key' => ['Invalid api_key: abc123xyz789', 'Invalid api_key: ***'],
            'R1 words before auth_token' => ['Request rejected for auth_token: abc123xyz789', 'Request rejected for auth_token: ***'],
            // 2: rule/cast words do not protect unquoted values
            'R2 Password@123' => ['password=Password@123', 'password=***'],
            'R2 Password!' => ['password=Password!', 'password=***'],
            'R2 Email#2024' => ['password=Email#2024', 'password=***'],
            'R2 False#99' => ['password=False#99', 'password=***'],
            'R2 Sail default' => ['DB_PASSWORD=password', 'DB_PASSWORD=***'],
            'R2 quoted password word' => ["'password' => 'password'", "'password' => '***'"],
            // 3: other master regressions
            'R3 port-like password with hash' => ['postgres://app:1234#Secret@db/app', 'postgres://***:***@db/app'],
            'R3 port-like password with query' => ['mysql://root:2024?x@db', 'mysql://***:***@db'],
            'R3 port-like with email query' => ['GET https://example.com:8080?email=a@b.c failed', 'GET https://***:***@b.c failed'],
            'R3 glued authtoken colon' => ['authtoken: abc123xyz789', 'authtoken: ***'],
            'R3 glued apikey' => ['myapikey=abc123xyz789', 'myapikey=***'],
            'R3 number after compound key' => ['api_key: 12345', 'api_key: ***'],
            'R3 key after path' => ['path /login/password=hunter2', 'path /login/password=***'],
            'R3 value on next line' => ["password=\nhunter2", 'password= ***'],
        ];
    }

    #[DataProvider('roundFourLeaks')]
    public function testRedactsRoundFourLeaks(string $input, string $expected): void
    {
        $this->assertSame($expected, MessageHelper::sanitizeErrorMessage($input, 1000));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundFourUnchanged(): array
    {
        return [
            'bare token after word' => ['Invalid token: expected string'],
            'compound key with stop word' => ['DB_PASSWORD: not set'],
            'bare token count' => ['token: 3'],
            'port before path' => ['https://host:8080/a?e=x@y'],
            'file line' => ['/app/Http/Middleware/VerifyCsrfToken.php:12'],
            'colon key after path' => ['/var/secrets/token:12'],
            'hashed cast' => ["['password' => 'hashed']"],
            'rule list' => ["'password' => 'required|string|min:8|confirmed'"],
            'json null' => ['{"password":null}'],
            'colon value on next line' => ["password:\n  host: db"],
        ];
    }

    #[DataProvider('roundFourUnchanged')]
    public function testLeavesRoundFourControlsUnchanged(string $message): void
    {
        $expected = str_replace("\n", ' ', $message);

        $this->assertSame($expected, MessageHelper::sanitizeErrorMessage($message, 1000));
    }

    public function testEveryPatternFailsClosedWhenTheRegexEngineGivesUp(): void
    {
        // Sweep the limit, so the run gives up inside each pattern in turn (with
        // pcre.jit=0 and a limit of 16, the key check used to read a failure as "not a
        // secret"). Whatever pattern gives up, the secret must not come back.
        $backtrackLimit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        $leaks = [];

        try {
            ini_set('pcre.jit', '0');

            foreach (range(1, 400) as $limit) {
                ini_set('pcre.backtrack_limit', (string) $limit);

                if (str_contains(MessageHelper::sanitizeErrorMessage('x DB_PASSWORD=hunter2'), 'hunter2')) {
                    $leaks[] = $limit;
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
            ini_set('pcre.jit', (string) $jit);
        }

        $this->assertSame([], $leaks);
    }

    public function testJwtPrefixChainWithTrailingDotScalesLinearly(): void
    {
        [$smallElapsed, $smallResult] = $this->fastestOfTwo(str_repeat('eyJ-', 25600).'.');
        [$largeElapsed, $largeResult] = $this->fastestOfTwo(str_repeat('eyJ-', 262144).'.');

        $this->assertNotSame(self::REDACTION_FAILED, $smallResult);
        $this->assertNotSame(self::REDACTION_FAILED, $largeResult);
        $this->assertLessThan(max(30 * $smallElapsed, 3.0), $largeElapsed, sprintf('100 KB: %.3fs, 1 MB: %.3fs', $smallElapsed, $largeElapsed));
    }

    // ---------------------------------------------------------------------
    // Round 5: never redact less than master
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}> input, the secret fragment master hides
     */
    public static function masterRedacts(): array
    {
        $bs = chr(92);

        return [
            'escaped newline in json env' => ['{"env":"DB_HOST=127.0.0.1'.$bs.'nDB_PASSWORD=hunter2xyz'.$bs.'nDB_PORT=3306"}', 'hunter2xyz'],
            'escaped newline before colon key' => ['body: "ok'.$bs.'napi_key: sk9f8e7d6c"', 'sk9f8e7d6c'],
            'escaped tab before key' => ['x'.$bs.'tclient_secret=Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'windows path before key' => ['C:'.$bs.'app'.$bs.'pass=Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'glued private key' => ['sshprivatekey=Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'path before colon key' => ['GET /api_key: Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'colon key value on next line' => ["api_key:\n  Zq8Wv7Ux", 'Zq8Wv7Ux'],
            'bearer token on next line' => ["Authorization: Bearer\nZq8Wv7Ux", 'Zq8Wv7Ux'],
            'bearer token with quote' => ["Authorization: Bearer ab'Zq8Wv7Ux", 'Zq8Wv7Ux'],
            'dsn password with hash' => ['redis://Zq8#Wv7Ux@cache:6379', 'Wv7Ux'],
            'url userinfo with query mark' => ['https://Zq8?Wv7Ux@git.example.com/r.git', 'Wv7Ux'],
            'stop word glued to secret' => ['api_key: none!Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'triple equals' => ['password===Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'double equals arrow' => ['password==>Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'colon after equals' => ['password=:Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'call-shaped value' => ['password=Zq8Wv7Ux()', 'Zq8Wv7Ux'],
            'quote then tail' => ["password='ab'Zq8Wv7Ux", 'Zq8Wv7Ux'],
            'bracket then paren' => ['password=[ab(Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'quoted pair then tail' => ['"password=ab"Zq8Wv7Ux', 'Zq8Wv7Ux'],
            'separator on next line' => ["password\n=x9Zq8Wv7", 'x9Zq8Wv7'],
            'bearer value on next line' => ["bearer\nx9Zq8Wv7", 'x9Zq8Wv7'],
        ];
    }

    /**
     * Inputs the previous release redacted although they hold no secret. The
     * compatibility floor still hides every value of 4 bytes or more that it hid.
     * (A shorter value, as in "bypass=1", is the floor's documented exception.)
     *
     * @return array<string, array{string, string}>
     */
    public static function masterOverRedactions(): array
    {
        return [
            'url email query without path' => ['GET https://example.com?email=a@b.c failed', 'GET https://***@b.c failed'],
            'url at sign in fragment' => ['GET https://example.com#a@b failed', 'GET https://***@b failed'],
            'empty call' => ['secret=random()', 'secret=***)'],
            'sql named binding' => ['update users set password = :password where id = 1', 'update users set password = *** where id = 1'],
        ];
    }

    #[DataProvider('masterOverRedactions')]
    public function testKeepsHidingWhatMasterOverRedacted(string $input, string $expected): void
    {
        $this->assertSame($expected, MessageHelper::sanitizeErrorMessage($input, 1000));
    }

    #[DataProvider('masterRedacts')]
    public function testNeverRedactsLessThanMaster(string $input, string $secret): void
    {
        $this->assertStringNotContainsString($secret, self::masterRedact($input), 'master hides it');
        $this->assertStringNotContainsString($secret, MessageHelper::sanitizeErrorMessage($input, 1000));
    }

    public function testHidesEveryValueMasterHidesAcrossGeneratedInputs(): void
    {
        $bs = chr(92);
        $keys = ['password', 'PASSWORD', 'passwd', 'pwd', 'pass', 'DB_PASSWORD', 'dbpassword', 'api_key', 'apikey', 'myapikey', 'api-key', 'auth_token', 'authtoken', 'token', 'secret', 'client_secret', 'private_key', 'privatekey', '--password'];
        $separators = ['=', ' = ', "=\n", '==', ': ', ':', ":\n", '=>', ' =  '];
        $prefixes = ['', ' ', '/', $bs, '"', $bs.'"', '_', 'x', 'by', '--', "\t", '(', 'Error: ', $bs.'n'];
        $values = ['Zq8Wv7Ux', "'Zq8Wv7Ux'", '"Zq8Wv7Ux"', $bs.'"Zq8Wv7Ux'.$bs.'"', "ab'Zq8Wv7Ux", '[Zq8Wv7Ux', 'none!Zq8Wv7Ux', 'Zq8Wv7Ux()'];
        $misses = [];
        $checked = 0;

        foreach ($keys as $key) {
            foreach ($separators as $separator) {
                foreach ($prefixes as $prefix) {
                    foreach ($values as $value) {
                        $input = $prefix.$key.$separator.$value;
                        $hidden = self::masterHiddenValues($input);

                        if ($hidden === []) {
                            continue;
                        }

                        $checked++;
                        $output = MessageHelper::sanitizeErrorMessage($input, 1000);

                        foreach ($hidden as $secret) {
                            if (self::containsValue($output, $secret)) {
                                $misses[] = [$input, $secret, $output];
                            }
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(300, $checked);
        $this->assertSame([], $misses);
    }

    /**
     * The values the previous release's patterns hide in the original message, as the
     * floor promises them: 4 bytes or more, and not a literal such as null.
     *
     * @return list<string>
     */
    private static function masterHiddenValues(string $message): array
    {
        $patterns = [
            ['#(\w+://)([^/\s]+)@#i', 2],
            ['/(password|passwd|pwd|pass)(\s*=\s*)([^\s&;,)\]]+)/i', 3],
            ['/(api[_-]?key|auth[_-]?token)(\s*[=:]\s*)([^\s&;,)\]]+)/i', 3],
            ['/\bbearer\s+([^\s&;,)\]]+)/i', 1],
            ['/\btoken\s*=\s*([^\s&;,)\]]+)/i', 1],
            ['/(private[_-]?key|secret|client[_-]?secret)(\s*=\s*)([^\s&;,)\]]+)/i', 3],
            ['/AKIA[0-9A-Z]{16}/i', 0],
        ];
        $values = [];

        foreach ($patterns as [$pattern, $group]) {
            preg_match_all($pattern, $message, $matches);

            foreach ($matches[$group] ?? [] as $value) {
                if (strlen($value) >= 4 && ! in_array(strtolower($value), ['null', 'true', 'false', 'none', 'undefined'], true)) {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Whether a hidden value survives in the output; a value made only of word
     * characters counts only as a whole word, the way the scrub matches it.
     */
    private static function containsValue(string $output, string $value): bool
    {
        if (strspn($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_') === strlen($value)) {
            return preg_match('/(?<![A-Za-z0-9_])'.preg_quote($value, '/').'(?![A-Za-z0-9_])/', $output) === 1;
        }

        return str_contains($output, $value);
    }

    // ---------------------------------------------------------------------
    // Round 6: the scrub, and the review's regressions
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function roundSixRedactions(): array
    {
        return [
            'M1 password flag with spaced equals' => ['--password = hunter2', '--password = ***'],
            'M1 mysqldump spaced equals' => ['mysqldump --password = hunter2 db', 'mysqldump --password = *** db'],
            'M1 spaced equals quoted value' => ['--password =  "hunter 2"', '--password =  ***'],
        ];
    }

    #[DataProvider('roundSixRedactions')]
    public function testRedactsRoundSixInputs(string $input, string $expected): void
    {
        $this->assertSame($expected, MessageHelper::sanitizeErrorMessage($input, 1000));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundSixUnchanged(): array
    {
        $bs = chr(92);
        $q = $bs.'"';

        return [
            'M2 escaped validation errors' => ['{"message":"{'.$q.'errors'.$q.':{'.$q.'password'.$q.':['.$q.'The password field is required.'.$q.']}}"}'],
            'M2 escaped token error' => ['{"message":"{'.$q.'token'.$q.':['.$q.'This password reset token is invalid.'.$q.']}"}'],
            'M2 escaped confirmation error' => ['{"message":"{'.$q.'password_confirmation'.$q.':['.$q.'The password confirmation does not match.'.$q.']}"}'],
            'M3 shell command not found' => ['.env: line 12: DB_PASSWORD: command not found'],
            'M3 unbound variable' => ['DB_PASSWORD: unbound variable'],
            'M3 readonly variable' => ['APP_KEY: readonly variable'],
            'M4 empty json password' => ['{"password":"","email":"a@b.c"}'],
            'M4 empty json token' => ['{"token":""}'],
            'M4 empty var_dump string' => ['["password"]=> string(0) ""'],
            'M5 camel token undefined' => ['accessToken: undefined'],
            'M5 token undefined' => ['token: undefined'],
            'M5 password incorrect' => ['password: incorrect'],
            'M5 passphrase incorrect' => ['Passphrase: incorrect'],
            'M5 auth driver none' => ['{"auth":"none","driver":"session"}'],
            'M5 auth driver basic' => ['{"auth":"basic"}'],
            'short value under the floor' => ['bypass=1'],
        ];
    }

    #[DataProvider('roundSixUnchanged')]
    public function testLeavesRoundSixInputsUnchanged(string $message): void
    {
        $this->assertSame($message, MessageHelper::sanitizeErrorMessage($message, 1000));
    }

    /**
     * @return array<string, array{string, string}> input, the secret that must not survive
     */
    public static function scrubbedLeaks(): array
    {
        $bs = chr(92);

        return [
            'dotted scheme before bearer' => ['Authorization: X-Api.Bearer hunter2', 'hunter2'],
            'comma scheme before bearer' => ['Authorization: Custom,Bearer hunter2', 'hunter2'],
            'stripe token glued to key' => ['sk_live_abcdefghijbypass=hunter2', 'hunter2'],
            'github token glued to key' => ['ghp_abcdefghijklmnopqrstbypass=hunter2', 'hunter2'],
            'key glued after colon value' => ['password:abcapi_key = hunter2', 'hunter2'],
            'value after newline under a colon key' => ["secret: AUTH_TOKEN=\nhunter2", 'hunter2'],
            'escaped quote before key' => ['passphrase='.$bs.'":\'x secret=("hunter2"', 'hunter2'],
        ];
    }

    #[DataProvider('scrubbedLeaks')]
    public function testScrubsValuesThePreviousReleaseHid(string $input, string $secret): void
    {
        $this->assertStringNotContainsString($secret, MessageHelper::sanitizeErrorMessage($input, 1000));
    }

    public function testScrubLeavesAWordValueInsideALongerWord(): void
    {
        // "pass=word1" hides "word1"; "password" elsewhere is a different word.
        $this->assertSame('pass=*** password', MessageHelper::sanitizeErrorMessage('pass=word1 password', 1000));
        // A scrubbed value is replaced wherever it reappears in the message.
        $this->assertSame('token=*** (***)', MessageHelper::sanitizeErrorMessage('token=abc1234 (abc1234)', 1000));
    }

    public function testScrubHandlesUpToTheValueCap(): void
    {
        $pairs = [];

        for ($i = 1; $i <= 1000; $i++) {
            $pairs[] = "password=value{$i}x";
        }

        $result = MessageHelper::sanitizeErrorMessage(implode(' ', $pairs), PHP_INT_MAX);

        $this->assertNotSame(self::REDACTION_FAILED, $result);
        $this->assertStringNotContainsString('value', $result);
    }

    public function testScrubFailsClosedAboveTheValueCap(): void
    {
        $pairs = [];

        for ($i = 1; $i <= 100000; $i++) {
            $pairs[] = "password=value{$i}";
        }

        $start = $this->cpuSeconds();
        $result = MessageHelper::sanitizeErrorMessage(implode(' ', $pairs), PHP_INT_MAX);

        $this->assertSame(self::REDACTION_FAILED, $result);
        $this->assertLessThan(10.0, $this->cpuSeconds() - $start);
    }

    /**
     * The previous release's redaction, embedded verbatim (without truncation), as
     * the reference the current helper must never fall below.
     */
    private static function masterRedact(string $message): string
    {
        $message = preg_replace('#(\w+://)([^/\s]+)@#i', '$1***:***@', $message) ?? $message;
        $message = preg_replace('/(password|passwd|pwd|pass)(\s*=\s*)[^\s&;,)\]]+/i', '$1$2***', $message) ?? $message;
        $message = preg_replace('/(api[_-]?key|auth[_-]?token)(\s*[=:]\s*)[^\s&;,)\]]+/i', '$1$2***', $message) ?? $message;
        $message = preg_replace('/\bbearer\s+[^\s&;,)\]]+/i', 'bearer ***', $message) ?? $message;
        $message = preg_replace('/\btoken\s*=\s*[^\s&;,)\]]+/i', 'token=***', $message) ?? $message;
        $message = preg_replace('/(private[_-]?key|secret|client[_-]?secret)(\s*=\s*)[^\s&;,)\]]+/i', '$1$2***', $message) ?? $message;
        $message = preg_replace('/AKIA[0-9A-Z]{16}/i', 'AKIA***', $message) ?? $message;

        return preg_replace(
            '/\b(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3})\b/',
            '***.*.*.*',
            $message
        ) ?? $message;
    }
}
