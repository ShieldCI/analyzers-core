<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Support;

/**
 * Helper utilities for message formatting and sanitization.
 */
class MessageHelper
{
    /**
     * Returned in place of the message when a redaction pattern fails to run (for
     * example on hitting pcre.backtrack_limit). Failing closed keeps an unredacted
     * message from leaking when the regex engine gives up.
     */
    private const REDACTION_FAILED = '[error message withheld: redaction failed]';

    /**
     * The previous release's seven redaction patterns, each with the number of the
     * group that holds the text it replaced. The pattern text is the previous
     * release's VERBATIM, except that the value part of the four key patterns and of
     * the two token patterns is wrapped in one extra capturing group. Do not loosen
     * or "fix" these: they define the compatibility floor (see previousReleaseValues()).
     */
    private const PREVIOUS_RELEASE_PATTERNS = [
        ['#(\w+://)([^/\s]+)@#i', 2],
        ['/(password|passwd|pwd|pass)(\s*=\s*)([^\s&;,)\]]+)/i', 3],
        ['/(api[_-]?key|auth[_-]?token)(\s*[=:]\s*)([^\s&;,)\]]+)/i', 3],
        ['/\bbearer\s+([^\s&;,)\]]+)/i', 1],
        ['/\btoken\s*=\s*([^\s&;,)\]]+)/i', 1],
        ['/(private[_-]?key|secret|client[_-]?secret)(\s*=\s*)([^\s&;,)\]]+)/i', 3],
        ['/AKIA[0-9A-Z]{16}/i', 0],
    ];

    /** Literal values the floor does not scrub (compared case-insensitively). */
    private const FLOOR_KEEP_WORDS = ['null', 'true', 'false', 'none', 'undefined'];

    /**
     * The most distinct values the floor scrubs. A message holding more fails closed:
     * scrubbing is a pass over the message per distinct value length, so an unbounded
     * count would let one message cost quadratic time.
     */
    private const FLOOR_VALUE_CAP = 1000;

    /** Characters of a "word" value, matched only as a whole word by the scrub. */
    private const WORD_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    /** A camelCase word-part boundary (fooBar, CSRFToken), whatever the case flag. */
    private const CAMEL_BOUNDARY = '(?-i:(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z]))';

    /**
     * Key names that hold a secret in an assignment (key=value, key => value,
     * "key": value), tested against the END of the key. The long words also match
     * glued to any prefix (PGPASSWORD, dbpassword, oauthtoken, userpass), except
     * the English words ending in "pass"; the rest need a word-part boundary.
     * Plurals, *_count, *_ttl and file names never end in a key word.
     */
    private const ASSIGNMENT_KEY = '/(?:passw(?:or)?d|pwd|secret|token|api[_.-]?key|(?<!by|com|tres|sur|over|under)pass'
        .'|(?:\A|[_.-]|'.self::CAMEL_BOUNDARY.')(?:passphrase|credential|auth|(?<=_)session'
        .'|(?:api|app|access|private|encryption|signing|secret)[_.-]?key))\z/i';

    /**
     * Key names that hold a secret in the bare "key: value" form, which is also how
     * prose reads, so: boundary-only matching (apikey and authtoken excepted), and no
     * "pass" or "auth".
     */
    private const COLON_KEY = '/(?:api[_.-]?key|auth[_.-]?token|(?:\A|[_.-]|'.self::CAMEL_BOUNDARY.')(?:passw(?:or)?d|passphrase|pwd'
        .'|secret|token|credential|(?<=_)session|(?:api|app|access|private|encryption|signing|secret)[_.-]?key))\z/i';

    /**
     * Single-word keys. Only these get the bare-form prose guards (a word in front,
     * a short number after), because "Invalid token: 3" reads as prose while a
     * compound key such as api_key or DB_PASSWORD is a key wherever it appears.
     */
    private const BARE_KEY_WORDS = ['password', 'passwd', 'passphrase', 'pwd', 'secret', 'token', 'credential'];

    /**
     * A key and its separator. The key may sit in quotes (also JSON-escaped ones),
     * backticks or brackets (SQL, print_r, var_dump); it is a possessive run, so a
     * long word is scanned once, and it must end the way a key word ends, so most
     * words never become candidates. Whether the key names a secret, and what its
     * value is, is decided in redactKeyedSecrets(), so a rejected candidate consumes
     * only its own key and separator. After '=' or '=>' the value may start on the
     * next line, as master allowed; after ':' it must be on the same line.
     */
    private const KEY_CANDIDATE = '/(?<![\w.\\\\-])(?<open>\[["\']?|\\\\?["\']|`)?(?<key>[\w.-]++)'
        .'(?<=(?i:pass|pwd|word|swd|phrase|secret|token|credential|auth|key|session|confirmation))'
        .'(?<close>["\']?\]|\\\\?["\']|`)?(?<sep>[ \t]*+(?:(?:=>|==?(?![=>]))\s*+|:(?![:\/])[ \t]*+))/';

    private const DOUBLE_QUOTED = '"(?:[^"\\\\\r\n]++|\\\\.)*+"';

    private const SINGLE_QUOTED = '\'(?:[^\'\\\\\r\n]++|\\\\.)*+\'';

    /** Laravel validation rules and casts. Not "password": Sail's real default DB_PASSWORD is "password". */
    private const LARAVEL_RULE = '(?:required|nullable|sometimes|string|confirmed|hashed|encrypted|bail|filled|present'
        .'|current_password|alpha_num|alpha_dash|email|(?:min|max|size|between|digits|digits_between):[\d,]++)';

    /**
     * A quoted value that is exactly a rule or cast, or a pipe-joined list of them
     * ('hashed', 'required|string|min:8'). Kept only in the => and quoted-key forms.
     */
    private const RULE_VALUE = '/\G(["\'])'.self::LARAVEL_RULE.'(?:\|'.self::LARAVEL_RULE.')*+\1/Ai';

    /**
     * The value after a secret key's separator, anchored at it. Named branches:
     * keep (null/true/false/none/undefined/~, a ? or :name binding, each as the whole value; a
     * config lookup by key name; a call on a variable or no arguments), call (string literals inside a
     * call), dump (var_dump's string(N) "..."), quoted (one quoted string, or a run
     * of concatenated ones), escaped (a JSON-escaped string), list, object, plain.
     */
    private const KEYED_VALUE = '/\G(?:(?<keep>(?:null|true|false|none|undefined|~|\?|:\w++)(?=[\s"\',)\]}]|\z)'
        .'|(?:env|getenv|config|config::get)[ \t]*+\(|\$_(?:env|server)\['
        .'|[a-z_\\\\][\w\\\\]*+(?:::\w++)?[ \t]*+\([ \t]*+[$)])'
        .'|(?<call>[a-z_\\\\][\w\\\\]*+(?:::\w++)?[ \t]*+\([ \t]*+(?=["\'])[^)\r\n]*+\)?)'
        .'|(?<dump>string\(\d++\)[ \t]*+)(?<dumped>"(?:[^"\\\\\r\n]++|\\\\.)*+"?)'
        .'|(?<quoted>(?:"(?:[^"\\\\\r\n]++|\\\\.)*+"?|\'(?:[^\'\\\\\r\n]++|\\\\.)*+\'?)'
        .'(?:[ \t]*+\.[ \t]*+(?:'.self::DOUBLE_QUOTED.'|'.self::SINGLE_QUOTED.'))*+)'
        .'|(?<escaped>\\\\"(?:[^"\\\\\r\n]++|\\\\(?!")[^\r\n])*+(?:\\\\")?)'
        .'|(?<list>[\[(][^\[\]()\r\n]*+[\])]?)'
        .'|(?<object>\{[^{}\r\n]*+\}?)'
        .'|(?<plain>[^\s&;,)\]]++))/Ai';

    /** A whole quoted value of an "auth" key that names a driver, not a secret ({"auth":"none"}). */
    private const AUTH_DRIVER = '/\G(["\'])(?:none|basic|session|token|api)\1/Ai';

    /** Whole-word values that make a "key: value" pair prose ("password: must be 8 chars"). */
    private const COLON_STOP_WORD = '/\G(?:not|must|missing|required|expected|empty|invalid|null|none|unset|set|cannot'
        .'|should|is|was|too|does|did|command|unbound|readonly|undefined|incorrect)(?![\w.-])/Ai';

    /** Bare "key: value" values that read as prose: a sentence. */
    private const BARE_COLON_PROSE = '/\G[A-Z][a-z]*+[ \t]++[a-z]/A';

    /** A bare single-word key followed by a number of up to five digits is prose ("Token: 3 of 4"). */
    private const BARE_WORD_NUMBER = '/\G\d{1,5}+(?![\w.-])/A';

    /**
     * Sanitize error message for display in recommendations.
     *
     * Redacts sensitive information (passwords, credentials, API keys), folds
     * control characters so the message stays on one line, and limits message
     * length to prevent overly long recommendations.
     */
    public static function sanitizeErrorMessage(string $error, int $maxLength = 200): string
    {
        // First, redact sensitive information
        $sanitized = self::redactSensitiveInfo($error) ?? self::REDACTION_FAILED;

        // Then truncate if needed. A negative length would make substr() count from
        // the end and keep text instead of dropping it, so treat it as zero.
        $maxLength = max(0, $maxLength);

        if (strlen($sanitized) > $maxLength) {
            // $maxLength is a byte budget; cut without splitting a UTF-8 character.
            return Utf8::cut($sanitized, $maxLength).'...';
        }

        return $sanitized;
    }

    /**
     * Redact sensitive information from error messages.
     *
     * Removes passwords, API keys, tokens, and credentials from error messages
     * while preserving enough context for debugging, then folds control characters
     * so the message stays on one line. Returns null when a pattern fails to run,
     * so the caller can fail closed.
     *
     * The patterns run on bytes (no /u modifier), so invalid UTF-8 cannot make
     * them fail, and every repetition is possessive or bounded so that no input
     * backtracks catastrophically. Values are taken from the same line as their
     * key; line breaks are folded only after redaction.
     */
    private static function redactSensitiveInfo(string $message): ?string
    {
        // Captured on the original, before any rule rewrites it.
        $previousReleaseValues = self::previousReleaseValues($message);

        if ($previousReleaseValues === null) {
            return null;
        }

        $message = preg_replace_callback_array([
            // PEM private key bodies, which span lines. An unterminated block is
            // redacted to the end of the message.
            '/(-----BEGIN (?:[A-Z0-9]++ )*?PRIVATE KEY-----)(?:[^-]++|-(?!----END ))*+(-----END [A-Z0-9 ]*+-----)?/'
                => static fn (array $match): string => $match[1].' ***'.(isset($match[2]) ? ' '.$match[2] : ''),

            // Authorization / Proxy-Authorization headers. The value runs to its closing
            // quote, to a quote opened before the key (curl -H "Authorization: ..."), or
            // to the end of the line; redactAuthorizationValue() decides what in it is a
            // credential.
            '/(?<![\w.\/\\\\-])(["\']?)((?:proxy-)?authorization(["\']?)[ \t]*+(?:=>|=|:(?!:))[ \t]*+)(["\']?)([^\r\n]*+)/i'
                => static fn (array $match): string => $match[1].$match[2].$match[4]
                    .self::redactAuthorizationValue($match[5], $match[4] !== '' ? $match[4] : ($match[3] === '' ? $match[1] : '')),

            // Cookie / Set-Cookie headers: redact the value of every name=value pair. The
            // first pair's value must not run on into words ("cookie: name=value pairs
            // must not contain spaces" is prose). The look-ahead is bounded, so a run of
            // "cookie:" with no whitespace is not rescanned to its end from each one.
            '/(?<![\w-])((?:set-)?cookie[ \t]*+:[ \t]*+)(?=[^=;\s]{1,256}+=[^;\s]{0,256}+(?![ \t]++[^\s;]))([^\r\n]++)/i'
                => static fn (array $match): string => $match[1].self::redactCookieValues($match[2]),

            // Connection string whose userinfo has a password: scheme://user:pass@host.
            // Passwords may contain '/' and '@', so the userinfo runs to the LAST '@' of
            // the whitespace-delimited word. Done per word in PHP: a regex scan for the
            // last '@' would rescan the word from every "scheme://" in it.
            '/\S++/' => static fn (array $match): string => self::redactUrlPassword($match[0]),

            // Connection string with userinfo but no password-like ':': scheme://token@host.
            // The userinfo ends at the last '@' before the path, query or fragment, so an
            // '@' in a path (https://host/user@x) or a query (?email=a@b.c) is not one.
            '~(?<!\w)(\w++://)(?:[^\s/?#@]*+@)++~' => static fn (array $match): string => $match[1].'***:***@',

            // MySQL client tools: -p<password> attached to the flag. Only within the
            // command's next 20 arguments, so a "-p..." flag of another tool is left alone.
            '/\b((?:mysql(?:dump|admin)?|mariadb)(?![\w.-])(?:[ \t]++(?!-p)[^ \t\r\n]++){0,20}+[ \t]++-p)'
                .'(?:"[^"\r\n]*+"?|\'[^\'\r\n]*+\'?|\S++)/'
                => static fn (array $match): string => $match[1].'***',

            // --password=<value> / --password = <value> / --password <value>, for any command. With a space, a
            // value that starts a lowercase phrase is prose ("--password requires a value").
            '/(?<![\w-])(--password(?:[ \t]*+=[ \t]*+|[ \t]++(?!(?-i:[a-z]++[ \t]++[a-z]))))(?!-)(?:"[^"\r\n]*+"?|\'[^\'\r\n]*+\'?|\S++)/i'
                => static fn (array $match): string => $match[1].'***',

            // Bearer tokens: "Bearer <token>", with master's stop set. A token already
            // redacted by the Authorization rule is left alone, so its quote survives.
            '/\b(bearer)[ \t]++(?!\*\*\*)[^\s&;,)\]]++/i' => static fn (array $match): string => $match[1].' ***',

            // Basic credentials: "Basic <base64>", when it decodes to "user:password".
            '~\b((?i:basic))[ \t]++([A-Za-z0-9+/]{8,}+={0,2}+)(?![\w+/=])~'
                => static fn (array $match): string => self::redactBasicCredentials($match[0], $match[1], $match[2]),
        ], $message);

        // key=value, key: value, key => value, "key":"value" for keys that name a secret.
        $message = $message === null ? null : self::redactKeyedSecrets($message);

        $message = $message === null ? null : preg_replace_callback_array([
            // Tokens recognisable by their prefix, with no key in front of them.
            '/\b(gh[pousr]_|github_pat_)[A-Za-z0-9_]{20,}+/' => static fn (array $match): string => $match[1].'***',
            '/\b([sr]k_(?:live|test)_|xox[abprs]-)[A-Za-z0-9-]{10,}+/' => static fn (array $match): string => $match[1].'***',
            '/(?<![\w-])(eyJ)[\w-]{10,}+\.eyJ[\w-]++\.[\w-]*+/' => static fn (array $match): string => $match[1].'***',
            '~(https?://hooks\.slack\.com/services/)[\w/-]++~i' => static fn (array $match): string => $match[1].'***',

            // AWS-style access keys (pattern: AKIA followed by 16 chars)
            '/AKIA[0-9A-Z]{16}/i' => static fn (): string => 'AKIA***',

            // What looks like internal IP addresses (10.x.x.x, 172.16-31.x.x, 192.168.x.x)
            '/\b(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3})\b/'
                => static fn (): string => '***.*.*.*',
        ], $message);

        // The compatibility floor: values the previous release hid, still present.
        $message = $message === null ? null : self::scrubPreviousReleaseValues($message, $previousReleaseValues);

        // Last: fold each run of control characters (CR, LF, tab, other C0, DEL) into
        // one space, so the message stays on one line.
        return $message === null ? null : preg_replace('/[\x00-\x1F\x7F]++/', ' ', $message);
    }

    /**
     * The compatibility floor, part 1: what the previous release would have hidden.
     *
     * Applies the previous release's patterns to the ORIGINAL message and collects
     * the text each would have replaced. After every rule has run, the scrub removes
     * any of it still present, so every value of 4 bytes or more that the previous
     * release hid stays hidden, whatever the newer rules did to the text around it.
     * Values under 4 bytes ("bypass=1") and literals such as null are the documented
     * exception: scrubbing them everywhere in the message would destroy prose.
     *
     * Returns null, so the message fails closed, when a pattern fails to run or when
     * the message holds more than FLOOR_VALUE_CAP distinct values.
     *
     * @return list<string>|null
     */
    private static function previousReleaseValues(string $message): ?array
    {
        $values = [];

        foreach (self::PREVIOUS_RELEASE_PATTERNS as [$pattern, $group]) {
            if (preg_match_all($pattern, $message, $matches) === false) {
                return null;
            }

            foreach ($matches[$group] ?? [] as $value) {
                if (strlen($value) >= 4 && ! in_array(strtolower($value), self::FLOOR_KEEP_WORDS, true)) {
                    $values[$value] = $value;
                }
            }
        }

        return count($values) > self::FLOOR_VALUE_CAP ? null : array_values($values);
    }

    /**
     * The compatibility floor, part 2: replace every captured value still present
     * with "***". Values made only of letters, digits and '_' are replaced as whole
     * words, so a value "pass" leaves "password" alone; any other value is replaced
     * wherever it occurs. strtr() takes the longest match first, and runs before the
     * word pass, so a longer value is never cut short by a shorter one inside it.
     *
     * A newer rule may have redacted only part of a value the previous release hid
     * whole (Bearer ab'secret leaves 'secret after the token). So when a value is no
     * longer present intact, every run of 4 or more word characters left from it is
     * scrubbed as a word too. Returns null when the word pass fails to run.
     *
     * @param list<string> $values
     */
    private static function scrubPreviousReleaseValues(string $message, array $values): ?string
    {
        $words = [];
        $others = [];

        foreach ($values as $value) {
            if (strspn($value, self::WORD_CHARACTERS) === strlen($value)) {
                $words[$value] = true;
            } elseif (str_contains($message, $value)) {
                $others[$value] = '***';
            } else {
                $words += self::wordPieces($value);
            }
        }

        $message = strtr($message, $others);

        return $words === [] ? $message : preg_replace_callback(
            '/[A-Za-z0-9_]++/',
            static fn (array $match): string => isset($words[$match[0]]) ? '***' : $match[0],
            $message
        );
    }

    /**
     * The runs of 4 or more word characters in a value, other than literals such as
     * null and call names (a run followed by '(' or '::', as in bcrypt('x') or
     * Hash::make('x'), where the rule kept the call and redacted its literal).
     *
     * @return array<string, true>
     */
    private static function wordPieces(string $value): array
    {
        $pieces = [];
        $length = strlen($value);

        for ($at = strcspn($value, self::WORD_CHARACTERS); $at < $length; $at += $run + strcspn($value, self::WORD_CHARACTERS, $at + $run)) {
            $run = strspn($value, self::WORD_CHARACTERS, $at);
            $piece = substr($value, $at, $run);

            $next = substr($value, $at + $run, 2);

            if ($run >= 4 && ! in_array(strtolower($piece), self::FLOOR_KEEP_WORDS, true) && ! str_starts_with($next, '(') && $next !== '::') {
                $pieces[$piece] = true;
            }
        }

        return $pieces;
    }

    /**
     * Redact the value of every key that names a secret.
     *
     * Candidates (a key and its separator) are found left to right; each is then
     * judged in PHP and, when it names a secret, its value is matched anchored right
     * after the separator. Text is consumed only for a redacted value, so a value can
     * never hide a later key. Returns null when a pattern fails to run.
     */
    private static function redactKeyedSecrets(string $message): ?string
    {
        $output = '';
        $cursor = 0;
        $offset = 0;
        $failed = false;

        // One candidate at a time: the search resumes after the separator, or after a
        // redacted value, so the text inside a redacted value is never searched again.
        while (! $failed && ($found = preg_match(self::KEY_CANDIDATE, $message, $candidate, PREG_OFFSET_CAPTURE, $offset)) === 1) {
            $start = $candidate[0][1];
            $valueStart = $start + strlen($candidate[0][0]);
            [$open, $key, $close, $sep] = [$candidate['open'][0], $candidate['key'][0], $candidate['close'][0], $candidate['sep'][0]];
            $names = self::namesSecret($message, $start, $open, $key, $close, $sep);
            $redaction = $names === true ? self::redactKeyedValue($message, $valueStart, $open, $key, $close, $sep, $start) : null;
            $failed = $names === null || $redaction === false;

            if (is_array($redaction)) {
                $output .= substr($message, $cursor, $valueStart - $cursor).$redaction[1];
                $cursor = $valueStart + $redaction[0];
            }

            $offset = max($valueStart, $cursor);
        }

        return $failed || ($found ?? 0) === false ? null : $output.substr($message, $cursor);
    }

    /**
     * Whether a key candidate names a secret, judged by its form: an assignment
     * (=, ==, =>, or a quoted/bracketed key with ':') or the bare "key: value" form.
     * In the bare form a key right after '/' is a file path (token:12), and a
     * single-word key preceded by another word is prose ("Invalid token:"), except
     * "client secret:"; a compound key (api_key, DB_PASSWORD) is always a key. A
     * "_confirmation" suffix is ignored. Returns null when the pattern fails to run.
     */
    private static function namesSecret(string $message, int $start, string $open, string $key, string $close, string $sep): ?bool
    {
        $assignment = $close !== '' || str_contains($sep, '=');
        $matched = preg_match($assignment ? self::ASSIGNMENT_KEY : self::COLON_KEY, self::keyTail($key));

        if ($assignment || $matched !== 1) {
            return $matched === false ? null : $matched === 1;
        }

        $before = substr($message, max(0, $start - 7), min(7, $start)).$open;
        $afterWord = strlen($before) >= 2 && strpbrk(substr($before, -1), " \t") !== false
            && (ctype_alnum($before[strlen($before) - 2]) || $before[strlen($before) - 2] === '_');

        return substr($before, -1) !== '/'
            && ! ($afterWord && self::isBareKeyWord($key) && strcasecmp(substr($before, -7, 6), 'client') !== 0);
    }

    /**
     * The end of a key, without a "_confirmation" suffix. Key words are short, so
     * only the tail matters, which keeps a long run linear.
     */
    private static function keyTail(string $key): string
    {
        $tail = substr($key, -64);

        return strcasecmp(substr($tail, -12), 'confirmation') === 0 ? rtrim(substr($tail, 0, -12), '_.-') : $tail;
    }

    /**
     * Whether a key is a single key word (token, password) rather than a compound.
     */
    private static function isBareKeyWord(string $key): bool
    {
        return in_array(strtolower($key), self::BARE_KEY_WORDS, true);
    }

    /**
     * Redact the value that starts at $offset, after a secret key's separator.
     *
     * Returns [length consumed, replacement], null when the value is not a secret
     * (a literal, a rule, a config lookup, prose), or false when a pattern failed.
     *
     * @return array{int, string}|false|null
     */
    private static function redactKeyedValue(string $message, int $offset, string $open, string $key, string $close, string $sep, int $start): array|false|null
    {
        // Each check below fails closed: a pattern that fails to run reads as "not
        // prose" or "not a rule", so the value goes on to be redacted.
        if (str_contains($sep, ':') && ! str_contains($sep, '=')) {
            $prose = preg_match(self::COLON_STOP_WORD, $message, $unused, 0, $offset) === 1
                || ($close === '' && preg_match(self::BARE_COLON_PROSE, $message, $unused, 0, $offset) === 1)
                || ($close === '' && self::isBareKeyWord($key) && preg_match(self::BARE_WORD_NUMBER, $message, $unused, 0, $offset) === 1);

            if ($prose) {
                return null;
            }
        }

        // An "auth" key naming its driver ({"auth":"none"}) holds no secret.
        if (strcasecmp($key, 'auth') === 0 && preg_match(self::AUTH_DRIVER, $message, $unused, 0, $offset) === 1) {
            return null;
        }

        // A rule or cast is a non-secret only as a quoted value in the => or quoted-key form.
        if ((str_contains($sep, '=>') || ($close !== '' && str_contains($sep, ':'))) && preg_match(self::RULE_VALUE, $message, $unused, 0, $offset) === 1) {
            return null;
        }

        $matched = preg_match(self::KEYED_VALUE, $message, $value, PREG_UNMATCHED_AS_NULL, $offset);

        if ($matched !== 1 || $value['keep'] !== null) {
            return $matched === false ? false : null;
        }

        if ($value['call'] !== null) {
            return [strlen($value['call']), self::redactQuotedStrings($value['call'])];
        }

        // An empty string holds no secret ({"password":""}, string(0) "").
        if (in_array($value['quoted'] ?? $value['dumped'], ['""', "''"], true)) {
            return null;
        }

        if ($value['dump'] !== null && $value['dumped'] !== null) {
            return [strlen($value['dump'].$value['dumped']), $value['dump'].self::redactQuotedStrings($value['dumped'])];
        }

        if ($value['quoted'] !== null) {
            return [strlen($value['quoted']), self::redactQuotedStrings($value['quoted'])];
        }

        if ($value['escaped'] !== null) {
            return [strlen($value['escaped']), '\\"***'.(strlen($value['escaped']) > 3 && str_ends_with($value['escaped'], '\\"') ? '\\"' : '')];
        }

        if (($value['list'] ?? $value['object']) !== null) {
            return self::redactBracketedValue($value['list'] ?? $value['object']);
        }

        // Plain value. Inside a quoted string ("Cookie":"laravel_session=abc") or a
        // brace ({password: x}) it stops at the character that closes that structure.
        $stop = $open !== '' && $close === '' ? substr($open, -1) : (substr($message, max(0, $start - 1), min(1, $start)) === '{' ? '}' : '');
        $plain = $value['plain'] ?? '';
        $plain = $stop === '' ? $plain : substr($plain, 0, strcspn($plain, $stop));

        return $plain === '' ? null : [strlen($plain), '***'];
    }

    /**
     * Redact a bracketed value: the quoted strings of a list or a flat object (an
     * object's quoted keys are kept), or the whole content when nothing is quoted.
     * Left alone: an empty value, a PHP map (['guard' => 'web']), and a list whose
     * first entry reads as a sentence (Laravel validation errors, also JSON-escaped).
     *
     * @return array{int, string}|null
     */
    private static function redactBracketedValue(string $value): ?array
    {
        $inner = trim($value, "[](){} \t");

        if ($inner === '' || str_contains($value, '=>') || preg_match('/\A[\[(][ \t]*+\\\\?["\'][A-Z][a-z]*+[ \t]++[a-z]/', $value) === 1) {
            return null;
        }

        if (strpbrk($inner, '"\'') !== false) {
            return [strlen($value), self::redactQuotedStrings($value)];
        }

        $last = substr($value, -1);

        return [strlen($value), $value[0].'***'.(strpbrk($last, ')]}') !== false ? $last : '')];
    }

    /**
     * Redact every quoted string, keeping its quotes; a quoted string followed by ':'
     * is an object key and is kept whole, and an unterminated one is redacted to the end.
     */
    private static function redactQuotedStrings(string $value): string
    {
        // The key branch skips past a key whole, so the scan does not restart at its
        // closing quote and read '":"' as a string.
        return preg_replace_callback(
            '/(?:'.self::DOUBLE_QUOTED.'|'.self::SINGLE_QUOTED.')[ \t]*+:(*SKIP)(*FAIL)'
                .'|"(?:[^"\\\\\r\n]++|\\\\.)*+("?)|\'(?:[^\'\\\\\r\n]++|\\\\.)*+(\'?)/',
            static fn (array $match): string => $match[0][0].'***'.($match[1] ?? '').($match[2] ?? ''),
            $value
        ) ?? '***'; // Fail closed: drop the whole value.
    }

    /**
     * Redact the credential in an Authorization header value.
     *
     * The value ends at $terminator (an unescaped quote) when one is given. Within
     * it: a single-token scheme (Basic, Bearer, token, Negotiate, NTLM, DPoP) keeps
     * the scheme and redacts the one token after it; any other scheme word that is
     * capitalised or contains a digit (Digest, AWS4-HMAC-SHA256) keeps the scheme and
     * redacts the rest; a lone token that is not a plain word is redacted whole. A
     * plain word ("denied", "header in request") is prose and is left alone.
     */
    private static function redactAuthorizationValue(string $value, string $terminator): string
    {
        $end = $terminator === '' ? strlen($value) : self::quotedLength($value, $terminator);

        $credential = preg_replace(
            '/\A(?|((?:basic|bearer|token|negotiate|ntlm|dpop)[ \t]++)[^\s"\'\\\\]++'
                .'|((?:digest|(?-i:[A-Z][\w-]*+)|[\w-]*?\d[\w-]*+)[ \t]++)(?=\S).*+'
                .'|()(?!(?-i:[A-Z]?[a-z]++)(?![^\s"\'\\\\]))[^\s"\'\\\\]++)/is',
            '$1***',
            substr($value, 0, $end)
        ) ?? '***'; // Fail closed: drop the whole header value.

        return $credential.substr($value, $end);
    }

    /**
     * The length of a quoted string's content, up to its unescaped closing $quote.
     */
    private static function quotedLength(string $value, string $quote): int
    {
        return preg_match('/\A(?:[^'.$quote.'\\\\]++|\\\\.)*+/s', $value, $match) === 1 ? strlen($match[0]) : strlen($value);
    }

    /**
     * Redact the value of every name=value pair in a Cookie or Set-Cookie header.
     */
    private static function redactCookieValues(string $header): string
    {
        return preg_replace('/(?<![^;\s])([^=;\s]++=)[^;\r\n]*+/', '$1***', $header) ?? '***';
    }

    /**
     * A bare "Basic <token>" is a credential only when the token is strict base64
     * of printable text containing ':' (user:password), so that prose such as
     * "Basic Laravel123 setup" is left alone.
     */
    private static function redactBasicCredentials(string $whole, string $scheme, string $token): string
    {
        $decoded = base64_decode($token, true);

        if ($decoded !== false && str_contains($decoded, ':') && ctype_print($decoded)) {
            return $scheme.' ***';
        }

        return $whole;
    }

    /**
     * Redact the userinfo of a connection string in one whitespace-delimited word.
     *
     * Applies when the userinfo has a ':' (user:password) whose text is not a port,
     * i.e. not digits followed by '/' (https://host:8080/a?e=x@y). Digits followed by
     * '?' or '#' still have an '@' later in the word here, so they may be a password
     * (postgres://app:1234#Secret@db) and are redacted: redaction wins the tie. The
     * password then runs to the last '@' in the word, because it may contain '/'
     * and '@'. With other '@'s later in the word, such as one in a query string,
     * this over-redacts the host; it never leaves part of the password behind.
     */
    private static function redactUrlPassword(string $word): string
    {
        $at = strrpos($word, '@');

        if ($at === false) {
            return $word;
        }

        $userinfo = preg_replace(
            '~(?<!\w)(\w++://)[^/:@]*+:(?!\d++/).*+~s',
            '$1***:***@',
            substr($word, 0, $at + 1)
        ) ?? '***'; // Fail closed: drop the whole credential-bearing part.

        return $userinfo.substr($word, $at + 1);
    }
}
