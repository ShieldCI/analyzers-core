<?php

declare(strict_types=1);

namespace ShieldCI\AnalyzersCore\Enums;

/**
 * Why a file the analyzer suite was pointed at never produced an AST.
 *
 * The distinction is about who fixes it: broken code is the author's to repair,
 * whereas a parser older than the runtime is repaired by a toolchain upgrade.
 *
 * Intentionally carries no label() or recommendation(). Rendering a sentence for
 * a user is a reporting concern, and reporting lives in the consuming package —
 * this package is framework-agnostic and has no output voice of its own. A cause
 * that only a consumer can detect (a template that would not compile, say) also
 * belongs to that consumer's own enum, not here: every case listed below is one
 * this package actually observes, so a consumer's exhaustive match() never has to
 * carry an arm that cannot happen.
 */
enum ParseFailureCause: string
{
    /** No PHP runtime would accept this file either: the code itself is broken. */
    case SyntaxError = 'syntax-error';

    /** The running PHP accepts this file; the pinned parser is the one that cannot read it. */
    case UnsupportedSyntax = 'unsupported-syntax';

    /** The file never reached a parser at all: its bytes could not be read. */
    case Unreadable = 'unreadable';
}
