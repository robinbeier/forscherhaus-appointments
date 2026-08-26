#!/usr/bin/env python3
"""Fail-closed ROB-490 activation of Kuma retention monitoring."""

import argparse
import ctypes
import errno
import fcntl
import hashlib
import json
import os
import re
import secrets
import stat
import sys
from pathlib import Path


ENV_PATH = '/root/backups/uptime-kuma-push.env'
STATE_ROOT = '/var/lib/fh-kuma-monitoring-v1'
LOCK_PATH = '/run/fh-kuma-monitoring-v1.lock'
KEY = 'KUMA_RELEASE_RETENTION_MONITOR_ENABLED'
CONFIRMATION = 'ROB-490'
SCHEMA = 'fh_kuma_monitoring_recovery.v1'
MAX_ENV_BYTES = 4_000_000
MAX_EVIDENCE_BYTES = 4_100_000
RENAME_NOREPLACE = 1
RENAME_EXCHANGE = 2
SHELL_WORD_BREAKS = b' \t;|&(){}<>'


class ContractError(Exception):
    def __init__(self, reason, mutated=False, rollback='not_required'):
        super().__init__(reason)
        self.reason = reason
        self.mutated = mutated
        self.rollback = rollback


def fail(reason, mutated=False, rollback='not_required'):
    raise ContractError(reason, mutated, rollback)


def emit(status, ready=False, mutated=False, monitoring='unknown', recovery='unknown',
         rollback='not_required', reason=None):
    payload = {
        'execution_ready': ready,
        'monitoring_state': monitoring,
        'mutation_performed': mutated,
        'recovery_state': recovery,
        'rollback_outcome': rollback,
        'status': status,
    }
    if reason is not None:
        payload['reason'] = reason
    sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n')


def digest(data):
    return hashlib.sha256(data).hexdigest()


def identity(value):
    return (
        value.st_dev,
        value.st_ino,
        value.st_mode,
        value.st_uid,
        value.st_gid,
        value.st_nlink,
        value.st_size,
        value.st_mtime_ns,
        value.st_ctime_ns,
    )


def exchange_stable_identity(value):
    return identity(value)[:-1]


def trusted_gid(expected_uid):
    return 0 if expected_uid == 0 else os.getegid()


def mapped(root_prefix, absolute_path):
    if not absolute_path.startswith('/'):
        fail('path_contract_invalid')
    if root_prefix == Path('/'):
        return Path(absolute_path)
    return root_prefix / absolute_path.lstrip('/')


def validate_directory(path, expected_uid, exact_mode=None):
    try:
        before = os.lstat(path)
        after = os.lstat(path)
    except OSError:
        fail('directory_contract_invalid')
    mode = stat.S_IMODE(after.st_mode)
    if (
        identity(before) != identity(after)
        or not stat.S_ISDIR(after.st_mode)
        or after.st_uid != expected_uid
        or after.st_gid != trusted_gid(expected_uid)
        or (mode & 0o022) != 0
        or (exact_mode is not None and mode != exact_mode)
    ):
        fail('directory_contract_invalid')
    return after


def validate_ancestors(path, stop, expected_uid):
    try:
        path = Path(os.path.abspath(path))
        stop = stop.resolve(strict=True)
        relative = path.relative_to(stop)
    except (OSError, ValueError):
        fail('path_contract_invalid')
    current = stop
    validate_directory(current, expected_uid)
    for leaf in relative.parts:
        current /= leaf
        validate_directory(current, expected_uid)


def stable_read(path, expected_uid, expected_mode, maximum, reason):
    try:
        before = os.lstat(path)
        fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        fail(reason)
    try:
        opened = os.fstat(fd)
        if (
            identity(before) != identity(opened)
            or not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != expected_uid
            or opened.st_gid != trusted_gid(expected_uid)
            or stat.S_IMODE(opened.st_mode) != expected_mode
            or opened.st_nlink != 1
            or opened.st_size < 0
            or opened.st_size > maximum
        ):
            fail(reason)
        data = bytearray()
        while True:
            chunk = os.read(fd, min(131072, maximum + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
            if len(data) > maximum:
                fail(reason)
        after = os.fstat(fd)
        if identity(after) != identity(opened):
            fail(reason.replace('_contract_invalid', '_changed'))
    finally:
        os.close(fd)
    try:
        current = os.lstat(path)
    except OSError:
        fail(reason.replace('_contract_invalid', '_changed'))
    if identity(current) != identity(opened):
        fail(reason.replace('_contract_invalid', '_changed'))
    return bytes(data), opened


def parse_heredoc_operator(body, offset):
    index = offset + 2
    if index < len(body) and body[index:index + 1] == b'<':
        fail('env_shell_context_invalid')
    strip_tabs = False
    if index < len(body) and body[index:index + 1] == b'-':
        strip_tabs = True
        index += 1
    while index < len(body) and body[index:index + 1] in {b' ', b'\t'}:
        index += 1
    if index >= len(body):
        fail('env_shell_context_invalid')
    delimiter = bytearray()
    while index < len(body) and body[index:index + 1] not in b' \t;|&()<>':
        current = body[index:index + 1]
        if current == b"'":
            end = body.find(b"'", index + 1)
            if end < 0:
                fail('env_shell_context_invalid')
            delimiter.extend(body[index + 1:end])
            index = end + 1
            continue
        if current == b'"':
            index += 1
            while index < len(body) and body[index:index + 1] != b'"':
                current = body[index:index + 1]
                if current == b'\\':
                    if index + 1 >= len(body):
                        fail('env_shell_context_invalid')
                    following = body[index + 1:index + 2]
                    if following in {b'$', b'`', b'"', b'\\'}:
                        delimiter.extend(following)
                        index += 2
                        continue
                delimiter.extend(current)
                index += 1
            if index >= len(body):
                fail('env_shell_context_invalid')
            index += 1
            continue
        if current == b'\\':
            if index + 1 >= len(body):
                fail('env_shell_context_invalid')
            delimiter.extend(body[index + 1:index + 2])
            index += 2
            continue
        delimiter.extend(current)
        index += 1
    delimiter = bytes(delimiter)
    if not delimiter or b'\x00' in delimiter:
        fail('env_shell_context_invalid')
    return (delimiter, strip_tabs), index


def command_projection_literal(value):
    # Quoted shell separators and whitespace remain part of one shell word.
    # A fixed placeholder preserves that word boundary without exposing data.
    return b'x' if value in SHELL_WORD_BREAKS else value


def projection_starts_word(buffer, current):
    projected = bytes(buffer + current)
    return not projected or projected[-1:] in SHELL_WORD_BREAKS


def empty_quote_closes_word(body, index, projection, projection_start, starts_word):
    return (
        projection_start == len(projection)
        and starts_word
        and (
            index + 1 >= len(body)
            or body[index + 1:index + 2] in SHELL_WORD_BREAKS
        )
    )


def function_block_tail_is_definition_only(tail):
    candidate = tail
    redirection = re.compile(
        rb'^[ \t]*(?:(?:[0-9]+|\{[A-Za-z_][A-Za-z0-9_]*\})?'
        rb'(?:<<<|<<-|>>|<>|>\||<&|>&|>|<)|&(?:>>|>))'
        rb'[ \t]*[^ \t;|&]+'
    )
    while True:
        match = redirection.match(candidate)
        if match is None:
            break
        candidate = candidate[match.end():]
    return re.fullmatch(rb'[ \t;]*', candidate) is not None


def projected_command_is_statically_unexecuted(projected_commands, match):
    # This is intentionally a narrow proof, not a general evaluator for Bash
    # conditionals. A literal ``false &&`` cannot execute its immediate RHS.
    # The caller separately rejects this construct inside a subshell because
    # the failed LHS then becomes a status-bearing enclosing command.
    prefix = projected_commands[:match.start('wrappers')]
    return re.search(rb'(?:^|;)[ \t]*false[ \t]*&&[ \t]*$', prefix) is not None


def projection_status_depends_on_substitution(buffer, current):
    projected = bytes(buffer + current)
    command = re.split(rb'[;|&]', projected)[-1]
    redirection_operator = (
        rb'(?:(?:[0-9]+|\{[A-Za-z_][A-Za-z0-9_]*\})?'
        rb'(?:<<<|<<-|>>|<>|>\||<&|>&|>|<)|&(?:>>|>))'
    )
    redirection_without_target = re.fullmatch(
        rb'[ \t]*(?:'
        + redirection_operator
        + rb'[ \t]*[^ \t;|&]+[ \t]+)*'
        + redirection_operator
        + rb'[ \t]*',
        command,
    ) is not None
    return not command.strip(b' \t') or redirection_without_target or (
        re.fullmatch(
            rb'[ \t]*(?:[A-Za-z_][A-Za-z0-9_]*=[^ \t;|&]*[ \t]+)*'
            rb'[A-Za-z_][A-Za-z0-9_]*=',
            command,
        ) is not None
    )


def simple_parameter_end(value, index):
    if value[index:index + 1] != b'$' or index + 1 >= len(value):
        return None
    following = value[index + 1:index + 2]
    if re.match(rb'[A-Za-z_]', following):
        end = index + 2
        while end < len(value) and re.match(rb'[A-Za-z0-9_]', value[end:end + 1]):
            end += 1
        return end
    if re.match(rb'[0-9@*#?$!\-]', following):
        return index + 2
    return None


def shell_line_contexts(data):
    # Invariants: structural stacks preserve Bash balance; the projection keeps
    # only command-word semantics; continuation state joins physical lines; and
    # function spans scope return without weakening exit/exec detection.
    contexts = []
    quote = None
    compounds = []
    blocks = []
    continuation_kind = None
    continuation_escape_joins_word = False
    heredocs = []
    early_control = False
    invalid_closer = False
    projection_compound_depth = None
    projection_return_quote = None
    projection_function_depths = []
    projection_function_spans = []
    projection_subshell_depths = []
    projection_subshell_spans = []
    projection_function_block_depth = None
    projection_function_block_start = None
    arithmetic_invalid = False
    arithmetic_status_unknown = False
    status_bearing_command_substitution_unknown = False
    backtick_return_quote = None
    quote_projection_start = None
    quote_starts_word = False
    expansion_marker = b'\x00'
    command_projection_buffer = bytearray()
    pending_function_header = False
    redirection = (
        rb'(?:(?:(?:[0-9]+|\{[A-Za-z_][A-Za-z0-9_]*\})?'
        rb'(?:<<<|<<-|>>|<>|>\||<&|>&|>|<)|&(?:>>|>))'
        rb'[ \t]*[^ \t;|&]+[ \t]+)'
    )
    wrapper = (
        rb'(?:builtin[ \t]+(?:' + redirection + rb')*'
        rb'(?:--[ \t]+(?:' + redirection + rb')*)?'
        rb'|command[ \t]+(?:(?:-[pVv]+)[ \t]+|' + redirection + rb')*'
        rb'(?:--[ \t]+(?:' + redirection + rb')*)?)'
    )
    reserved_pipeline_prefix = (
        rb'(?:(?:!|time(?:[ \t]+-p)?|then|do|else|elif)[ \t]+)*'
    )
    command_word = re.compile(
        rb'(?:^|[;|&])[ \t]*'
        + reserved_pipeline_prefix +
        rb'(?:(?:[A-Za-z_][A-Za-z0-9_]*=[^ \t;|&]*)[ \t]+)*'
        rb'(?P<wrappers>(?:' + wrapper + rb')*)'
        rb'(?P<word>[A-Za-z_][A-Za-z0-9_]*)'
        rb'(?=$|[ \t;|&(){}<>])'
    )
    projection_command_word = re.compile(
        rb'(?:^|[;|&])[ \t]*'
        + reserved_pipeline_prefix +
        rb'(?:(?:[A-Za-z_][A-Za-z0-9_]*=[^ \t;|&]*)[ \t]+)*'
        rb'(?P<wrappers>(?:' + wrapper + rb')*)'
        rb'(?P<word>[A-Za-z_\x00][A-Za-z0-9_\x00]*)'
        rb'(?=$|[ \t;|&(){}<>])'
    )
    redirection_token = re.compile(
        rb'^(?:(?:[0-9]+|\{[A-Za-z_][A-Za-z0-9_]*\})?'
        rb'(?:<<<|<<-|>>|<>|>\||<&|>&|>|<)|&(?:>>|>))(?P<target>.*)$'
    )
    block_openers = {
        b'case': b'esac',
        b'for': b'done',
        b'if': b'fi',
        b'select': b'done',
        b'until': b'done',
        b'while': b'done',
    }
    function_header = re.compile(
        rb'(?:^|[;|&])[ \t]*(?:'
        rb'function[ \t]+[A-Za-z_][A-Za-z0-9_]*(?:[ \t]*\([ \t]*\))?'
        rb'|[A-Za-z_][A-Za-z0-9_]*[ \t]*\([ \t]*\)'
        rb')[ \t]*$'
    )
    function_block_opener = re.compile(
        rb'(?P<word>case|for|if|select|until|while)(?=$|[ \t])'
    )
    function_definition_name = re.compile(
        rb'(?:^|[;|&])[ \t]*(?:'
        rb'function[ \t]+(?P<function_name>[A-Za-z_][A-Za-z0-9_]*)'
        rb'(?:[ \t]*\([ \t]*\))?'
        rb'|(?P<plain_name>[A-Za-z_][A-Za-z0-9_]*)[ \t]*\([ \t]*\)'
        rb')[ \t]*(?=\{|\(\(|\[\[|case(?:$|[ \t])|for(?:$|[ \t])|'
        rb'if(?:$|[ \t])|select(?:$|[ \t])|until(?:$|[ \t])|'
        rb'while(?:$|[ \t])|$)'
    )
    defined_function_names = set()

    lines = data.split(b'\n')
    for line_index, body in enumerate(lines):
        if heredocs:
            contexts.append(False)
            delimiter, strip_tabs = heredocs[0]
            candidate = body.lstrip(b'\t') if strip_tabs else body
            if candidate == delimiter:
                heredocs.pop(0)
            continue

        incoming_continuation = continuation_kind
        incoming_escape_joins_word = continuation_escape_joins_word
        incoming_function_header = pending_function_header
        incoming_function_brace = False
        incoming_function_block_word = None
        incoming_function_conditional = False
        contexts.append(
            quote is None
            and not compounds
            and not blocks
            and incoming_continuation is None
            and not incoming_function_header
        )
        continuation_kind = None
        continuation_escape_joins_word = False
        if incoming_function_header:
            stripped = body.lstrip(b' \t')
            if not stripped or stripped.startswith(b'#'):
                pending_function_header = True
            elif stripped.startswith(b'{') and (
                len(stripped) == 1 or stripped[1:2] in b' \t'
            ):
                incoming_function_brace = True
            elif stripped.startswith(b'('):
                pending_function_header = False
            elif stripped.startswith(b'[[') and (
                len(stripped) == 2 or stripped[2:3] in b' \t'
            ):
                # [[ ... ]] is a Bash compound command and therefore a valid
                # split function body. Its operands are not shell commands.
                pending_function_header = False
                incoming_function_conditional = True
            else:
                block_match = function_block_opener.match(stripped)
                pending_function_header = False
                if block_match:
                    incoming_function_block_word = block_match.group('word')
                else:
                    invalid_closer = True
        # structural_visible retains source offsets for balance/continuation.
        # command_projection is the quote-aware view used only for command words.
        structural_visible = bytearray(b' ' * len(body))
        command_projection = bytearray()
        declared_heredocs = []
        index = 0
        while index < len(body):
            current = body[index:index + 1]
            if quote == b"'":
                if current == quote:
                    if (
                        projection_compound_depth is None
                        and empty_quote_closes_word(
                            body,
                            index,
                            command_projection,
                            quote_projection_start,
                            quote_starts_word,
                        )
                    ):
                        command_projection.extend(b'x')
                    elif projection_compound_depth is None:
                        # Preserve that this shell word used quoting. The NUL
                        # marker is stripped for builtin control names but
                        # prevents quoted reserved words becoming syntax.
                        command_projection.extend(expansion_marker)
                    quote = None
                    quote_projection_start = None
                    quote_starts_word = False
                elif projection_compound_depth is None:
                    command_projection.extend(command_projection_literal(current))
                index += 1
                continue
            if quote in {b'"', b'`'}:
                if quote == b'"' and current == b'$':
                    parameter_end = simple_parameter_end(body, index)
                    if parameter_end is not None:
                        if projection_compound_depth is None:
                            command_projection.extend(expansion_marker)
                        index = parameter_end
                        continue
                    token = body[index:index + 2]
                    if token in {b'$(', b'${', b'$['}:
                        if (
                            token == b'$('
                            and projection_compound_depth is None
                            and projection_status_depends_on_substitution(
                                command_projection_buffer,
                                command_projection,
                            )
                        ):
                            # Assignment-only and substitution-only commands
                            # inherit the substitution status. Arbitrary Env
                            # code is not executed here to guess that status.
                            status_bearing_command_substitution_unknown = True
                        closers = {b'$(': b')', b'${': b'}', b'$[': b']'}
                        compounds.append(closers[token])
                        if projection_compound_depth is None:
                            command_projection.extend(expansion_marker)
                            projection_compound_depth = len(compounds)
                            projection_return_quote = b'"'
                        quote = None
                        index += 2
                        continue
                if current == b'\\':
                    if index + 1 >= len(body):
                        continuation_kind = 'escape'
                        continuation_escape_joins_word = False
                        index += 1
                    else:
                        following = body[index + 1:index + 2]
                        if projection_compound_depth is None and quote != b'`':
                            special = b'$`"\\' if quote == b'"' else b'$`\\'
                            if following not in special:
                                command_projection.extend(b'\\')
                            command_projection.extend(command_projection_literal(following))
                        index += 2
                    continue
                if current == quote:
                    closing_quote = quote
                    if (
                        closing_quote == b'"'
                        and projection_compound_depth is None
                        and empty_quote_closes_word(
                            body,
                            index,
                            command_projection,
                            quote_projection_start,
                            quote_starts_word,
                        )
                    ):
                        command_projection.extend(b'x')
                    elif closing_quote == b'"' and projection_compound_depth is None:
                        command_projection.extend(expansion_marker)
                    quote = backtick_return_quote if closing_quote == b'`' else None
                    backtick_return_quote = None
                    if closing_quote == b'"':
                        quote_projection_start = None
                        quote_starts_word = False
                elif quote == b'"' and current == b'`':
                    if projection_compound_depth is None:
                        command_projection.extend(expansion_marker)
                    backtick_return_quote = b'"'
                    quote = b'`'
                elif projection_compound_depth is None and quote != b'`':
                    command_projection.extend(command_projection_literal(current))
                index += 1
                continue
            if current == b'#' and (
                index == 0 or body[index - 1:index] in b' \t;|&(){}'
            ) and not (
                index == 0
                and incoming_continuation == 'escape'
                and incoming_escape_joins_word
            ):
                break
            if current == b'\\':
                if index + 1 >= len(body):
                    continuation_kind = 'escape'
                    projected = bytes(command_projection_buffer + command_projection)
                    continuation_escape_joins_word = bool(
                        projected and projected[-1:] not in SHELL_WORD_BREAKS
                    )
                    index += 1
                else:
                    following = body[index + 1:index + 2]
                    if projection_compound_depth is None:
                        command_projection.extend(command_projection_literal(following))
                        command_projection.extend(expansion_marker)
                    index += 2
                continue
            parameter_end = simple_parameter_end(body, index)
            if parameter_end is not None:
                structural_visible[index] = ord('x')
                if projection_compound_depth is None:
                    command_projection.extend(expansion_marker)
                index = parameter_end
                continue
            if current in {b"'", b'"'}:
                structural_visible[index] = ord('x')
                quote_projection_start = len(command_projection)
                quote_starts_word = projection_starts_word(
                    command_projection_buffer,
                    command_projection,
                )
                quote = current
                index += 1
                continue
            if current == b'`':
                structural_visible[index] = ord('x')
                if projection_compound_depth is None:
                    command_projection.extend(expansion_marker)
                backtick_return_quote = None
                quote = b'`'
                index += 1
                continue
            if body[index:index + 2] == b'<<':
                specification, index = parse_heredoc_operator(body, index)
                declared_heredocs.append(specification)
                if projection_compound_depth is None:
                    command_projection.extend(b' ')
                continue
            if body[index:index + 2] == b'[[':
                compounds.append(b']]')
                if (
                    incoming_function_conditional
                    and not body[:index].strip(b' \t')
                    and projection_compound_depth is None
                ):
                    # A split ``[[ ... ]]`` function body is definition-only.
                    # Hide its operands from command-word classification.
                    command_projection.extend(b';')
                    projection_compound_depth = len(compounds)
                index += 2
                continue
            if body[index:index + 2] == b'((':
                arithmetic_close = body.find(b'))', index + 2)
                if arithmetic_close >= 0 and re.match(
                    rb'[ \t]*(?:exec|exit|return)[ \t]+[^ \t]',
                    body[index + 2:arithmetic_close],
                ):
                    # A control-word spelling followed by a separate operand
                    # is not a valid arithmetic expression. It must not be
                    # reclassified as a successful subshell control.
                    arithmetic_invalid = True
                arithmetic_definition_only = bool(
                    projection_function_depths
                    or function_header.search(body[:index])
                    or (incoming_function_header and not body[:index].strip(b' \t'))
                )
                if not arithmetic_definition_only:
                    # Arithmetic command status depends on evaluated Env state:
                    # zero is failure and nonzero is success. The helper never
                    # evaluates arbitrary Env expressions to predict errexit.
                    arithmetic_status_unknown = True
                compounds.append(b'))')
                if projection_compound_depth is None:
                    command_projection.extend(b';')
                    projection_compound_depth = len(compounds)
                index += 2
                continue
            if body[index:index + 2] == b'))' and compounds and compounds[-1] == b'))':
                compounds.pop()
                if (
                    projection_compound_depth is not None
                    and len(compounds) < projection_compound_depth
                ):
                    projection_compound_depth = None
                    if projection_return_quote is not None:
                        quote = projection_return_quote
                        projection_return_quote = None
                index += 2
                continue
            if body[index:index + 2] == b']]':
                if compounds and compounds[-1] == b']]':
                    compounds.pop()
                    if (
                        projection_compound_depth is not None
                        and len(compounds) < projection_compound_depth
                    ):
                        projection_compound_depth = None
                        if projection_return_quote is not None:
                            quote = projection_return_quote
                            projection_return_quote = None
                    index += 2
                elif compounds and compounds[-1] == b']':
                    # Arithmetic array syntax can close immediately before the
                    # legacy $[...] delimiter. Consume one bracket at a time.
                    compounds.pop()
                    if (
                        projection_compound_depth is not None
                        and len(compounds) < projection_compound_depth
                    ):
                        projection_compound_depth = None
                        if projection_return_quote is not None:
                            quote = projection_return_quote
                            projection_return_quote = None
                    index += 1
                else:
                    invalid_closer = True
                    index += 2
                continue
            if body[index:index + 2] in {b'$(', b'${', b'$[', b'>(', b'<('}:
                if (
                    body[index:index + 2] == b'$('
                    and projection_compound_depth is None
                    and projection_status_depends_on_substitution(
                        command_projection_buffer,
                        command_projection,
                    )
                ):
                    status_bearing_command_substitution_unknown = True
                closers = {b'(': b')', b'{': b'}', b'[': b']'}
                compounds.append(closers[body[index + 1:index + 2]])
                if projection_compound_depth is None:
                    # NUL cannot occur in validated input. Internally it marks
                    # an expansion that may disappear and assemble a command.
                    marker = expansion_marker if current == b'$' else b'x'
                    command_projection.extend(marker)
                    projection_compound_depth = len(compounds)
                index += 2
                continue
            if current == b'[' and compounds and compounds[-1] == b']':
                compounds.append(b']')
                index += 1
                continue
            if current == b']' and compounds and compounds[-1] == b']':
                compounds.pop()
                if (
                    projection_compound_depth is not None
                    and len(compounds) < projection_compound_depth
                ):
                    projection_compound_depth = None
                    if projection_return_quote is not None:
                        quote = projection_return_quote
                        projection_return_quote = None
                index += 1
                continue
            if current in {b'(', b'{'}:
                compounds.append(b')' if current == b'(' else b'}')
                same_line_function_header = bool(
                    current == b'{' and function_header.search(body[:index])
                )
                function_brace_token = bool(
                    current == b'{'
                    and (index + 1 == len(body) or body[index + 1:index + 2] in b' \t')
                )
                if (
                    current == b'{'
                    and (same_line_function_header or incoming_function_header)
                    and not function_brace_token
                ):
                    invalid_closer = True
                function_signature_parenthesis = bool(
                    current == b'('
                    and re.fullmatch(
                        rb'[ \t]*(?:function[ \t]+)?[A-Za-z_][A-Za-z0-9_]*[ \t]*',
                        body[:index],
                    )
                    and re.match(rb'\([ \t]*\)', body[index:])
                )
                if (
                    current == b'('
                    and projection_compound_depth is None
                    and not function_signature_parenthesis
                ):
                    # Parenthesized command groups execute in a subshell. Keep
                    # their command projection scoped so a literal success can
                    # be distinguished from a nonzero status that would abort
                    # Kuma's errexit consumer before the appended assignment.
                    command_projection.extend(b';')
                    subshell_start = len(command_projection_buffer) + len(command_projection)
                    projection_subshell_depths.append((len(compounds), subshell_start))
                    same_line_function_body = bool(function_header.search(body[:index]))
                    split_line_function_body = bool(
                        incoming_function_header and not body[:index].strip(b' \t')
                    )
                    if same_line_function_body or split_line_function_body:
                        projection_function_depths.append((len(compounds), subshell_start))
                        pending_function_header = False
                if (
                    current == b'{'
                    and projection_compound_depth is None
                    and function_brace_token
                    and (
                        same_line_function_header
                        or (
                            incoming_function_brace
                            and not body[:index].strip(b' \t')
                        )
                    )
                ):
                    # Sourcing a function definition does not execute its body.
                    # Record its projected range so return is classified in
                    # function scope. Exit/exec remain conservatively fail-closed.
                    command_projection.extend(b';')
                    projection_function_depths.append((
                        len(compounds),
                        len(command_projection_buffer) + len(command_projection),
                    ))
                    pending_function_header = False
                index += 1
                continue
            if current in {b')', b'}'}:
                closed_projection = False
                if compounds and compounds[-1] == current:
                    compounds.pop()
                    if (
                        projection_function_depths
                        and len(compounds) < projection_function_depths[-1][0]
                    ):
                        _, function_start = projection_function_depths.pop()
                        command_projection.extend(b';')
                        projection_function_spans.append((
                            function_start,
                            len(command_projection_buffer) + len(command_projection),
                        ))
                    if (
                        projection_subshell_depths
                        and len(compounds) < projection_subshell_depths[-1][0]
                    ):
                        _, subshell_start = projection_subshell_depths.pop()
                        following = body[index + 1:index + 2]
                        if following and following not in SHELL_WORD_BREAKS:
                            invalid_closer = True
                        enclosing_tail = body[index + 1:].lstrip(b' \t')
                        enclosing_status_unknown = bool(
                            enclosing_tail.startswith((b'|', b'&&'))
                            or re.match(
                                rb'(?:(?:[0-9]+|\{[A-Za-z_][A-Za-z0-9_]*\})?'
                                rb'(?:<<<|<<-|>>|<>|>\||<&|>&|>|<)|&(?:>>|>))',
                                enclosing_tail,
                            )
                        )
                        command_projection.extend(b';')
                        projection_subshell_spans.append((
                            subshell_start,
                            len(command_projection_buffer) + len(command_projection),
                            enclosing_status_unknown,
                        ))
                        closed_projection = True
                    if (
                        projection_compound_depth is not None
                        and len(compounds) < projection_compound_depth
                    ):
                        projection_compound_depth = None
                        closed_projection = True
                        if projection_return_quote is not None:
                            quote = projection_return_quote
                            projection_return_quote = None
                else:
                    invalid_closer = True
                structural_visible[index] = current[0]
                if projection_compound_depth is None and not closed_projection:
                    command_projection.extend(current)
                index += 1
                continue
            structural_visible[index] = current[0]
            if projection_compound_depth is None:
                command_projection.extend(current)
            index += 1

        visible_bytes = bytes(structural_visible)
        for definition_match in function_definition_name.finditer(body):
            function_group = (
                'function_name'
                if definition_match.group('function_name') is not None
                else 'plain_name'
            )
            function_name = definition_match.group(function_group)
            function_start = definition_match.start(function_group)
            function_end = definition_match.end(function_group)
            # The raw spelling retains ``()`` for recognizing definitions;
            # the structural view proves that the name itself was neither
            # quoted nor commented out by the shell scanner.
            if visible_bytes[function_start:function_end] == function_name:
                defined_function_names.add(function_name)
        function_block_closed_tail = None
        for match in command_word.finditer(visible_bytes):
            # Shell reserved words are structural only when parsed directly.
            # Behind builtin/command they are ordinary operands (including
            # command -v/-V queries), never block delimiters.
            if match.group('wrappers'):
                continue
            word = match.group('word')
            if blocks and word == blocks[-1]:
                blocks.pop()
                if (
                    projection_function_block_depth is not None
                    and len(blocks) < projection_function_block_depth
                ):
                    function_block_closed_tail = visible_bytes[match.end('word'):]
                    projection_function_block_depth = None
            elif word in {b'done', b'esac', b'fi'}:
                invalid_closer = True
            elif word in block_openers:
                blocks.append(block_openers[word])
                if (
                    incoming_function_block_word == word
                    and projection_function_block_depth is None
                ):
                    projection_function_block_depth = len(blocks)
                    projection_function_block_start = len(command_projection_buffer)

        if function_block_closed_tail is not None:
            if not function_block_tail_is_definition_only(function_block_closed_tail):
                invalid_closer = True
            projection_function_spans.append((
                projection_function_block_start,
                len(command_projection_buffer) + len(command_projection),
            ))
            projection_function_block_start = None

        structural = bytes(structural_visible).rstrip()
        operator_continues = bool(
            structural.endswith((b'&&', b'||', b'|'))
            or (not structural and incoming_continuation == 'operator')
        )
        command_projection_buffer.extend(command_projection)
        if (
            projection_compound_depth is not None
            or projection_function_depths
            or projection_function_block_depth is not None
            or projection_subshell_depths
        ):
            # The expansion marker already represents the entire potentially
            # empty compound across physical lines. Function projections also
            # remain buffered until their closing brace records the full span.
            if (
                quote is None
                and continuation_kind != 'escape'
                and not operator_continues
            ):
                # An unescaped physical newline is a shell command boundary.
                # Preserve it while buffering scopes so adjacent line-local
                # words cannot assemble into a synthetic control command.
                command_projection_buffer.extend(b';')
        elif quote is not None and continuation_kind != 'escape':
            command_projection_buffer.extend(b'x')
        elif continuation_kind == 'escape':
            # Backslash-newline is removed by Bash, so the surrounding shell
            # word continues byte-for-byte on the next physical line.
            pass
        else:
            projected_commands = bytes(command_projection_buffer)
            for match in projection_command_word.finditer(projected_commands):
                word = match.group('word')
                static_word = word.replace(expansion_marker, b'')
                inside_function = any(
                    start <= match.start('word') < end
                    for start, end in projection_function_spans
                )
                inside_subshell = any(
                    span[0] <= match.start('word') < span[1]
                    for span in projection_subshell_spans
                )
                tail = projected_commands[match.end():]
                wrappers = [
                    wrapper_word
                    for wrapper_word in re.split(rb'[ \t]+', match.group('wrappers').strip())
                    if wrapper_word
                ]
                command_query = False
                command_options = False
                command_options_ended = False
                skip_redirection_target = False
                for wrapper_word in wrappers:
                    if skip_redirection_target:
                        skip_redirection_target = False
                        continue
                    redirection_match = redirection_token.match(wrapper_word)
                    if redirection_match:
                        skip_redirection_target = not redirection_match.group('target')
                        continue
                    if wrapper_word == b'command':
                        command_options = True
                        command_options_ended = False
                        continue
                    if wrapper_word == b'builtin':
                        command_options = False
                        command_options_ended = False
                        continue
                    if command_options and wrapper_word == b'--':
                        command_options_ended = True
                        continue
                    if (
                        command_options
                        and not command_options_ended
                        and re.fullmatch(rb'-[pVv]+', wrapper_word)
                    ):
                        if b'v' in wrapper_word or b'V' in wrapper_word:
                            command_query = True
                statically_unexecuted = projected_command_is_statically_unexecuted(
                    projected_commands,
                    match,
                )
                if statically_unexecuted and not inside_subshell:
                    continue
                if static_word in {b'exec', b'exit', b'return'} and not command_query:
                    if inside_function:
                        if static_word != b'return':
                            early_control = True
                    else:
                        # Executed shell controls are outside the supported Env
                        # grammar regardless of subshell, pipeline or background
                        # placement. Proving their status would require executing
                        # Env-defined aliases, functions, traps or later waits.
                        early_control = True
                elif (
                    not command_query
                    and (
                        static_word in defined_function_names
                        or (defined_function_names and expansion_marker in word)
                    )
                    and not inside_function
                    # The command projection also sees the function name in
                    # the compact ``name()`` definition syntax. Only a later
                    # invocation can affect the sourcing shell's status.
                    and re.match(rb'[ \t]*\)', tail) is None
                ):
                    early_control = True
            command_projection_buffer.clear()
            projection_function_spans.clear()
            projection_subshell_spans.clear()
        if structural.endswith((b'&&', b'||', b'|')):
            continuation_kind = 'operator'
        elif not structural and incoming_continuation == 'operator':
            continuation_kind = 'operator'
        elif (
            not structural
            and incoming_continuation == 'escape'
            and line_index == len(lines) - 1
        ):
            continuation_kind = 'escape'
        heredocs.extend(declared_heredocs)
        declaration = body
        comment_at = declaration.find(b'#')
        if comment_at >= 0:
            declaration = declaration[:comment_at]
        if function_header.fullmatch(declaration):
            pending_function_header = True

    complete = (
        quote is None
        and not compounds
        and not blocks
        and continuation_kind is None
        and not heredocs
        and not invalid_closer
        and not pending_function_header
        and projection_function_block_depth is None
        and not projection_subshell_depths
        and not arithmetic_invalid
        and not arithmetic_status_unknown
        and not status_bearing_command_substitution_unknown
    )
    trailing_escape_only = (
        quote is None
        and not compounds
        and not blocks
        and continuation_kind == 'escape'
        and not heredocs
        and not invalid_closer
    )
    return contexts, complete, trailing_escape_only, early_control


def parse_env(data):
    if b'\x00' in data:
        fail('env_contract_invalid')
    try:
        data.decode('utf-8')
    except UnicodeDecodeError:
        fail('env_invalid_utf8')
    contexts, shell_complete, trailing_escape_only, early_control = shell_line_contexts(data)
    key = KEY.encode('ascii')
    matches = []
    offset = 0
    for line_index, body in enumerate(data.split(b'\n')):
        if key in body:
            if not contexts[line_index] or body not in {key + b'=0', key + b'=1'}:
                fail('definition_ambiguous')
            matches.append((chr(body[-1]), offset + len(key) + 1))
        offset += len(body) + 1
    if early_control or (not shell_complete and not trailing_escape_only):
        fail('env_shell_context_invalid')
    if len(matches) > 1:
        fail('duplicate_definition')
    return matches[0] if matches else (None, None)


def desired_env(original, value, value_offset):
    if value is None:
        tail = original[:-1] if original.endswith(b'\n') else original
        trailing_escapes = len(tail) - len(tail.rstrip(b'\\'))
        if trailing_escapes % 2 == 1:
            fail('append_context_invalid')
        separator = b'\n' if original and not original.endswith(b'\n') else b''
        return original + separator + (KEY + '=1\n').encode('ascii')
    if value == '1':
        return original
    if value != '0' or value_offset is None or original[value_offset:value_offset + 1] != b'0':
        fail('env_changed')
    return original[:value_offset] + b'1' + original[value_offset + 1:]


def rename_operation(parent, source, target, flag, unavailable_reason):
    libc = ctypes.CDLL(None, use_errno=True)
    parent_fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        function = getattr(libc, 'renameat2', None)
        if sys.platform.startswith('linux') and function is not None:
            result = function(parent_fd, source.encode(), parent_fd, target.encode(), flag)
        elif sys.platform == 'darwin' and hasattr(libc, 'renameatx_np'):
            darwin_flag = 0x00000004 if flag == RENAME_NOREPLACE else 0x00000002
            result = libc.renameatx_np(parent_fd, source.encode(), parent_fd, target.encode(), darwin_flag)
        else:
            fail(unavailable_reason)
        if result != 0:
            error = ctypes.get_errno()
            raise OSError(error, os.strerror(error))
    finally:
        os.close(parent_fd)


def rename_noreplace(parent, source, target):
    rename_operation(parent, source, target, RENAME_NOREPLACE, 'rename_noreplace_unavailable')


def rename_exchange(parent, source, target):
    rename_operation(parent, source, target, RENAME_EXCHANGE, 'rename_exchange_unavailable')


def fsync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_exclusive(path, data, mode, expected_uid, reason):
    fd = None
    try:
        fd = os.open(
            path,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
            mode,
        )
    except OSError:
        fail(reason)
    try:
        try:
            offset = 0
            while offset < len(data):
                offset += os.write(fd, data[offset:])
            os.fchmod(fd, mode)
            if os.geteuid() == 0:
                os.fchown(fd, expected_uid, trusted_gid(expected_uid))
            os.fsync(fd)
            result = os.fstat(fd)
        except BaseException as error:
            try:
                opened = os.fstat(fd)
                current = os.lstat(path)
                if identity(opened) != identity(current):
                    raise ContractError(reason, True, 'failed')
                os.unlink(path)
                fsync_directory(path.parent)
            except FileNotFoundError:
                pass
            except ContractError:
                raise
            except BaseException as cleanup_error:
                raise ContractError(reason, True, 'failed') from cleanup_error
            if isinstance(error, ContractError):
                raise
            raise ContractError(reason) from error
    finally:
        if fd is not None:
            try:
                os.close(fd)
            except OSError as error:
                raise ContractError(reason, True, 'failed') from error
    return result


def evidence_payload(original, desired, issue=CONFIRMATION):
    return (json.dumps({
        'desired_sha256': digest(desired),
        'env_path': ENV_PATH,
        'issue': issue,
        'original_sha256': digest(original),
        'schema': SCHEMA,
    }, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8')


def recovery_files(state, expected_uid):
    state_before = validate_directory(state, expected_uid, 0o700)
    try:
        entries = list(os.scandir(state))
    except OSError:
        fail('recovery_invalid')
    if len(entries) != 2:
        fail('recovery_invalid')
    values = []
    for entry in entries:
        if re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', entry.name) is None:
            fail('recovery_invalid')
        values.append((entry.name, *stable_read(
            state / entry.name, expected_uid, 0o600, MAX_EVIDENCE_BYTES,
            'recovery_contract_invalid',
        )))
    if identity(state_before) != identity(validate_directory(state, expected_uid, 0o700)):
        fail('recovery_changed')
    return values


def validate_recovery(state, current, desired, expected_uid, current_enabled):
    values = recovery_files(state, expected_uid)
    candidates = []
    for index, (_, data, _) in enumerate(values):
        try:
            parsed = json.loads(data.decode('utf-8'))
        except (UnicodeDecodeError, json.JSONDecodeError):
            continue
        if not isinstance(parsed, dict) or set(parsed) != {
            'desired_sha256', 'env_path', 'issue', 'original_sha256', 'schema'
        }:
            continue
        if (
            parsed['env_path'] != ENV_PATH
            or parsed['issue'] not in {'ROB-488', CONFIRMATION}
            or parsed['schema'] != SCHEMA
        ):
            continue
        candidates.append((index, parsed))
    if len(candidates) != 1:
        fail('recovery_invalid')
    evidence_index, evidence = candidates[0]
    original = values[1 - evidence_index][1]
    original_value, original_offset = parse_env(original)
    if original_value == '1':
        fail('recovery_invalid')
    reconstructed = desired_env(original, original_value, original_offset)
    if (
        evidence['original_sha256'] != digest(original)
        or evidence['desired_sha256'] != digest(reconstructed)
        or reconstructed != desired
        or (current_enabled and current != desired)
        or (not current_enabled and current != original)
    ):
        fail('recovery_mismatch')
    return 'intact'


def remove_private_tree(path, expected_uid, expected):
    validate_directory(path, expected_uid, 0o700)
    entries = {entry.name for entry in os.scandir(path)}
    if entries != set(expected):
        fail('private_cleanup_invalid')
    for leaf, (expected_data, expected_identity) in expected.items():
        data, current = stable_read(
            path / leaf, expected_uid, 0o600, MAX_EVIDENCE_BYTES,
            'private_cleanup_invalid',
        )
        if data != expected_data or identity(current) != identity(expected_identity):
            fail('private_cleanup_invalid')
        os.unlink(path / leaf)
    os.rmdir(path)


def test_hook(root_prefix, name):
    # Fault injection is structurally unreachable for fixed production paths.
    if root_prefix == Path('/'):
        return ''
    return os.environ.get(name, '')


def publish_recovery(state, original, desired, root_prefix, expected_uid):
    if state.exists() or state.is_symlink():
        validate_directory(state, expected_uid, 0o700)
        validate_recovery(state, original, desired, expected_uid, False)
        return False
    parent = state.parent
    temporary = parent / ('.fh-kuma-monitoring-v1.pending-' + secrets.token_hex(16))
    published = False
    private_expected = {}
    try:
        os.mkdir(temporary, 0o700)
        if os.geteuid() == 0:
            os.chown(temporary, expected_uid, trusted_gid(expected_uid))
        original_leaf = 'rob-488-env.before'
        evidence_leaf = 'rob-490-recovery.json'
        evidence_data = evidence_payload(original, desired)
        private_expected[original_leaf] = (original, write_exclusive(
            temporary / original_leaf, original, 0o600, expected_uid, 'recovery_publication_failed',
        ))
        private_expected[evidence_leaf] = (evidence_data, write_exclusive(
            temporary / evidence_leaf, evidence_data,
            0o600, expected_uid, 'recovery_publication_failed',
        ))
        fsync_directory(temporary)
        race = test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_RECOVERY_PUBLISH_RACE')
        if race:
            if race not in {'exact', 'foreign'}:
                fail('test_hook_invalid')
            os.mkdir(state, 0o700)
            if race == 'exact':
                write_exclusive(state / 'legacy.before', original, 0o600, expected_uid, 'test_hook_failed')
                write_exclusive(
                    state / 'legacy.json', evidence_payload(original, desired, 'ROB-488'),
                    0o600, expected_uid, 'test_hook_failed',
                )
            else:
                write_exclusive(state / 'foreign', b'foreign\n', 0o600, expected_uid, 'test_hook_failed')
                write_exclusive(state / 'foreign.json', b'{}\n', 0o600, expected_uid, 'test_hook_failed')
            fsync_directory(state)
        try:
            rename_noreplace(parent, temporary.name, state.name)
            published = True
            if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_RECOVERY_DURABILITY') == '1':
                fail('recovery_durability_unknown', True, 'failed')
            fsync_directory(parent)
        except OSError as error:
            if error.errno != errno.EEXIST:
                raise
        if not published:
            remove_private_tree(temporary, expected_uid, private_expected)
        validate_recovery(state, original, desired, expected_uid, False)
        return published
    except ContractError as error:
        if not published and temporary.exists():
            try:
                remove_private_tree(temporary, expected_uid, private_expected)
            except BaseException as cleanup_error:
                raise ContractError('private_cleanup_invalid', True, 'failed') from cleanup_error
        if published and not error.mutated:
            raise ContractError(error.reason, True, 'failed') from error
        raise
    except BaseException as error:
        if not published and temporary.exists():
            try:
                remove_private_tree(temporary, expected_uid, private_expected)
            except BaseException as cleanup_error:
                raise ContractError('private_cleanup_invalid', True, 'failed') from cleanup_error
        raise ContractError('recovery_publication_failed', published, 'failed') from error


def inject_foreign_env(env, data, expected_uid):
    temporary = env.parent / ('.fh-kuma-monitoring-env-v1.foreign-' + secrets.token_hex(16))
    try:
        write_exclusive(temporary, data, 0o600, expected_uid, 'test_hook_failed')
        os.replace(temporary, env)
        fsync_directory(env.parent)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def exact_exchange_object(path, expected_data, expected_identity, expected_uid, reason):
    data, current = stable_read(path, expected_uid, 0o600, MAX_ENV_BYTES, reason)
    if data != expected_data or exchange_stable_identity(current) != exchange_stable_identity(expected_identity):
        fail(reason)
    return current


def validate_writer_lock(path, fd, expected_uid):
    try:
        opened = os.fstat(fd)
        current = os.lstat(path)
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        fail('lock_invalid')
    if (
        identity(opened) != identity(current)
        or not stat.S_ISREG(opened.st_mode)
        or opened.st_uid != expected_uid
        or opened.st_gid != trusted_gid(expected_uid)
        or stat.S_IMODE(opened.st_mode) != 0o600
        or opened.st_nlink != 1
    ):
        fail('lock_invalid')


def rollback_exchange(env, pending, live_data, live_identity, displaced_data,
                      displaced_identity, expected_uid, root_prefix, lock, lock_fd,
                      failure_reason):
    if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_CONCURRENT_DURING_RESTORE') == '1':
        inject_foreign_env(env, b'FOREIGN_RESTORE_WRITER=1\n', expected_uid)
    try:
        exact_exchange_object(env, live_data, live_identity, expected_uid, 'rollback_live_changed')
        exact_exchange_object(
            pending, displaced_data, displaced_identity, expected_uid, 'rollback_displaced_changed',
        )
        validate_writer_lock(lock, lock_fd, expected_uid)
        rename_exchange(env.parent, pending.name, env.name)
        exact_exchange_object(env, displaced_data, displaced_identity, expected_uid, 'rollback_restore_invalid')
        exact_exchange_object(pending, live_data, live_identity, expected_uid, 'rollback_displaced_invalid')
        validate_writer_lock(lock, lock_fd, expected_uid)
        os.unlink(pending)
        fsync_directory(env.parent)
        return ContractError(failure_reason, True, 'succeeded')
    except BaseException:
        return ContractError('rollback_failed', True, 'failed')


def cleanup_pending(pending, desired, replacement_identity, expected_uid, lock, lock_fd,
                    root_prefix):
    if not pending.exists() and not pending.is_symlink():
        return
    data, current = stable_read(
        pending, expected_uid, 0o600, MAX_ENV_BYTES, 'pending_cleanup_invalid',
    )
    if replacement_identity is None or data != desired or identity(current) != identity(replacement_identity):
        fail('pending_cleanup_invalid', True, 'failed')
    if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_REPLACE_LOCK_BEFORE_PENDING_UNLINK') == '1':
        inject_foreign_env(lock, b'', expected_uid)
    validate_writer_lock(lock, lock_fd, expected_uid)
    # The pathname unlink is authority-safe only because every supported writer
    # is excluded by the canonical lock. Non-cooperative root mutation is
    # explicitly outside the supported writer contract.
    os.unlink(pending)
    fsync_directory(pending.parent)


def execute_transaction(context):
    root_prefix = context['root_prefix']
    expected_uid = context['expected_uid']
    env = context['env']
    original = context['original']
    desired = context['desired']
    env_identity = context['env_identity']
    lock = context['lock']
    lock_fd = context['lock_fd']
    validate_writer_lock(lock, lock_fd, expected_uid)
    recovery_mutated = publish_recovery(
        context['state'], original, desired, root_prefix, expected_uid,
    )
    pending = env.parent / ('.fh-kuma-monitoring-env-v1.pending-' + secrets.token_hex(16))
    exchanged = False
    exchange_begun = False
    replacement_identity = None
    try:
        replacement_identity = write_exclusive(
            pending, desired, 0o600, expected_uid, 'pending_publication_failed',
        )
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_CONCURRENT_BEFORE_EXCHANGE') == '1':
            inject_foreign_env(env, b'FOREIGN_PRE_EXCHANGE_WRITER=1\n', expected_uid)
        try:
            current = os.lstat(env)
        except OSError:
            fail('env_changed', recovery_mutated)
        if identity(current) != identity(env_identity):
            fail('env_changed', recovery_mutated)
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_EXCHANGE') == '1':
            raise OSError(errno.EOPNOTSUPP, 'test exchange unavailable')
        validate_writer_lock(lock, lock_fd, expected_uid)
        rename_exchange(env.parent, pending.name, env.name)
        exchanged = True
        exchange_begun = True
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_CONCURRENT_PENDING_AFTER_EXCHANGE') == '1':
            inject_foreign_env(pending, b'FOREIGN_PENDING_WRITER=1\n', expected_uid)
        exact_exchange_object(
            env, desired, replacement_identity, expected_uid, 'published_contract_invalid',
        )
        exact_exchange_object(
            pending, original, env_identity, expected_uid, 'displaced_contract_invalid',
        )
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_AFTER_EXCHANGE') == '1':
            fail('test_failure_after_exchange')
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_ENV_DURABILITY') == '1':
            fail('test_failure_env_durability')
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_UNEXPECTED_AFTER_EXCHANGE') == '1':
            raise OSError(errno.EIO, 'test unexpected post-exchange failure')
        fsync_directory(env.parent)
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_TAMPER_RECOVERY_BEFORE_UNLINK') == '1':
            evidence = context['state'] / 'rob-490-recovery.json'
            evidence_fd = os.open(evidence, os.O_WRONLY | os.O_TRUNC | os.O_CLOEXEC | os.O_NOFOLLOW)
            try:
                os.write(evidence_fd, b'{}\n')
                os.fsync(evidence_fd)
            finally:
                os.close(evidence_fd)
            fsync_directory(context['state'])
        validate_recovery(context['state'], original, desired, expected_uid, False)
        exact_exchange_object(pending, original, env_identity, expected_uid, 'displaced_changed')
        validate_writer_lock(lock, lock_fd, expected_uid)
        os.unlink(pending)
        exchanged = False
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_FINAL_DURABILITY') == '1':
            fail('final_durability_unknown', True, 'failed')
        fsync_directory(env.parent)
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_POSTFLIGHT') == '1':
            fail('postflight_unknown', True, 'failed')
        refreshed = inspect_context(root_prefix)
        if refreshed['value'] != '1' or refreshed['desired'] != desired:
            fail('postflight_failed', True, 'failed')
        validate_recovery(context['state'], refreshed['original'], desired, expected_uid, True)
        return True
    except ContractError as error:
        if exchanged and replacement_identity is not None:
            raise rollback_exchange(
                env, pending, desired, replacement_identity, original, env_identity,
                expected_uid, root_prefix, lock, lock_fd, error.reason,
            ) from error
        if not exchanged:
            try:
                cleanup_pending(
                    pending, desired, replacement_identity, expected_uid, lock, lock_fd,
                    root_prefix,
                )
            except FileNotFoundError:
                pass
            except ContractError as cleanup_error:
                raise ContractError(
                    cleanup_error.reason,
                    recovery_mutated or replacement_identity is not None,
                    'failed',
                ) from cleanup_error
        if (recovery_mutated or exchange_begun) and not error.mutated:
            rollback = 'failed' if exchange_begun else error.rollback
            raise ContractError(error.reason, True, rollback) from error
        raise
    except BaseException as error:
        if exchanged and replacement_identity is not None:
            raise rollback_exchange(
                env, pending, desired, replacement_identity, original, env_identity,
                expected_uid, root_prefix, lock, lock_fd, 'execution_failed',
            ) from error
        try:
            cleanup_pending(
                pending, desired, replacement_identity, expected_uid, lock, lock_fd,
                root_prefix,
            )
        except FileNotFoundError:
            pass
        except ContractError as cleanup_error:
            raise ContractError(
                cleanup_error.reason,
                recovery_mutated or replacement_identity is not None,
                'failed',
            ) from cleanup_error
        raise ContractError('execution_failed', recovery_mutated or exchange_begun, 'failed') from error


def inspect_context(root_prefix):
    production = root_prefix == Path('/')
    expected_uid = 0 if production else os.geteuid()
    if production and os.geteuid() != 0:
        fail('root_required')
    if production and not sys.platform.startswith('linux'):
        fail('production_platform_invalid')
    validate_directory(root_prefix, expected_uid)
    env = mapped(root_prefix, ENV_PATH)
    state = mapped(root_prefix, STATE_ROOT)
    lock = mapped(root_prefix, LOCK_PATH)
    validate_ancestors(env.parent, root_prefix, expected_uid)
    validate_ancestors(state.parent, root_prefix, expected_uid)
    validate_ancestors(lock.parent, root_prefix, expected_uid)
    original, env_identity = stable_read(
        env, expected_uid, 0o600, MAX_ENV_BYTES, 'env_contract_invalid',
    )
    value, value_offset = parse_env(original)
    desired = desired_env(original, value, value_offset)
    if len(desired) > MAX_ENV_BYTES:
        fail('desired_env_too_large')
    if value == '1':
        if not state.exists() or state.is_symlink():
            fail('recovery_missing')
        validate_recovery(state, original, original, expected_uid, True)
        recovery_state = 'intact'
    elif state.exists() or state.is_symlink():
        validate_directory(state, expected_uid, 0o700)
        validate_recovery(state, original, desired, expected_uid, False)
        recovery_state = 'intact'
    else:
        recovery_state = 'absent'
    return {
        'desired': desired,
        'env': env,
        'env_identity': env_identity,
        'expected_uid': expected_uid,
        'lock': lock,
        'original': original,
        'recovery_state': recovery_state,
        'root_prefix': root_prefix,
        'state': state,
        'value': value,
    }


def open_lock(path, expected_uid):
    created = False
    try:
        fd = os.open(
            path,
            os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
            0o600,
        )
        created = True
    except FileExistsError:
        try:
            fd = os.open(path, os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW)
        except OSError:
            fail('lock_invalid')
    except OSError:
        fail('lock_invalid')
    opened = os.fstat(fd)
    try:
        current = os.lstat(path)
    except OSError:
        os.close(fd)
        fail('lock_invalid', created, 'failed' if created else 'not_required')
    if (
        identity(opened) != identity(current)
        or not stat.S_ISREG(opened.st_mode)
        or opened.st_uid != expected_uid
        or opened.st_gid != trusted_gid(expected_uid)
        or stat.S_IMODE(opened.st_mode) != 0o600
        or opened.st_nlink != 1
    ):
        os.close(fd)
        fail('lock_invalid', created, 'failed' if created else 'not_required')
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        os.close(fd)
        fail('lock_busy', created, 'failed' if created else 'not_required')
    try:
        validate_writer_lock(path, fd, expected_uid)
    except ContractError as error:
        os.close(fd)
        if created and not error.mutated:
            raise ContractError(error.reason, True, 'failed') from error
        raise
    return fd, created


def parse_arguments():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root-prefix', default='/')
    parser.add_argument('--execute', action='store_true')
    parser.add_argument('--confirm-live-write', default='')
    args = parser.parse_args()
    if args.execute:
        if args.confirm_live_write != CONFIRMATION:
            fail('confirmation_invalid')
    elif args.confirm_live_write:
        fail('confirmation_without_execute')
    requested = Path(args.root_prefix)
    if not requested.is_absolute():
        fail('root_prefix_invalid')
    root_prefix = requested.resolve(strict=True)
    if root_prefix == Path('/') and requested != root_prefix:
        fail('root_prefix_invalid')
    args.root_prefix = root_prefix
    return args


def main():
    args = parse_arguments()
    context = inspect_context(args.root_prefix)
    if not args.execute:
        monitoring = 'enabled' if context['value'] == '1' else 'would_enable'
        emit('pass', True, False, monitoring, context['recovery_state'])
        return
    lock_fd, lock_created = open_lock(context['lock'], context['expected_uid'])
    try:
        try:
            context = inspect_context(args.root_prefix)
            context['lock_fd'] = lock_fd
            validate_writer_lock(context['lock'], lock_fd, context['expected_uid'])
            if context['value'] == '1':
                emit('pass', True, lock_created, 'enabled', 'intact')
                return
            mutated = execute_transaction(context)
        except ContractError as error:
            if lock_created and not error.mutated:
                raise ContractError(error.reason, True, 'failed') from error
            raise
    finally:
        os.close(lock_fd)
    emit('pass', True, mutated or lock_created, 'enabled', 'intact')


try:
    main()
except ContractError as error:
    emit('fail', False, error.mutated, rollback=error.rollback, reason=error.reason)
    raise SystemExit(70)
except (OSError, ValueError, TypeError):
    emit('fail', False, False, reason='internal_error')
    raise SystemExit(70)
