<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use function in_array;
use function strtolower;

/**
 * Words reserved by MySQL, MariaDB, PostgreSQL or SQLite (taken from the keyword lists of
 * doctrine/dbal 4.4). The outbox queries do not quote the table name, so it must not be one of them.
 *
 * @internal
 */
final class ReservedTableNames
{
    private const WORDS = [
        'abort', 'accessible', 'action', 'add', 'admin', 'after', 'all', 'alter', 'analyse', 'analyze', 'and', 'any',
        'array', 'as', 'asc', 'asensitive', 'asymmetric', 'attach', 'authorization', 'auto', 'autoincrement',
        'before', 'begin', 'bernoulli', 'between', 'bigint', 'binary', 'blob', 'both', 'by', 'call', 'cascade',
        'case', 'cast', 'change', 'char', 'character', 'check', 'collate', 'collation', 'column', 'commit',
        'concurrently', 'condition', 'conflict', 'constraint', 'continue', 'convert', 'create', 'cross', 'cube',
        'cume_dist', 'current_catalog', 'current_date', 'current_role', 'current_schema', 'current_time',
        'current_timestamp', 'current_user', 'cursor', 'database', 'databases', 'day_hour', 'day_microsecond',
        'day_minute', 'day_second', 'dec', 'decimal', 'declare', 'default', 'deferrable', 'deferred', 'delayed',
        'delete', 'dense_rank', 'desc', 'describe', 'detach', 'deterministic', 'distinct', 'distinctrow', 'div',
        'do', 'double', 'drop', 'dual', 'each', 'else', 'elseif', 'empty', 'enclosed', 'end', 'escape', 'escaped',
        'except', 'exclusive', 'exists', 'exit', 'explain', 'fail', 'false', 'fetch', 'first_value', 'float',
        'float4', 'float8', 'for', 'force', 'foreign', 'freeze', 'from', 'full', 'fulltext', 'function', 'general',
        'generated', 'get', 'glob', 'grant', 'group', 'grouping', 'groups', 'gtids', 'having', 'high_priority',
        'hour_microsecond', 'hour_minute', 'hour_second', 'if', 'ignore', 'ignore_server_ids', 'ilike', 'immediate',
        'in', 'index', 'indexed', 'infile', 'initially', 'inner', 'inout', 'insensitive', 'insert', 'instead', 'int',
        'int1', 'int2', 'int3', 'int4', 'int8', 'integer', 'intersect', 'interval', 'into', 'io_after_gtids',
        'io_before_gtids', 'is', 'isnull', 'iterate', 'join', 'json_table', 'key', 'keys', 'kill', 'lag',
        'last_value', 'lateral', 'lead', 'leading', 'leave', 'left', 'like', 'limit', 'linear', 'lines', 'load',
        'localtime', 'localtimestamp', 'lock', 'log', 'long', 'longblob', 'longtext', 'loop', 'low_priority',
        'manual', 'master_bind', 'master_heartbeat_period', 'master_ssl_verify_server_cert', 'match', 'maxvalue',
        'mediumblob', 'mediumint', 'mediumtext', 'member', 'middleint', 'minute_microsecond', 'minute_second', 'mod',
        'modifies', 'natural', 'no', 'no_write_to_binlog', 'not', 'notnull', 'nth_value', 'ntile', 'null', 'numeric',
        'of', 'offset', 'on', 'only', 'optimize', 'optimizer_costs', 'option', 'optionally', 'or', 'order', 'out',
        'outer', 'outfile', 'over', 'overlaps', 'parallel', 'parse_tree', 'partition', 'percent_rank', 'persist',
        'persist_only', 'placing', 'plan', 'pragma', 'precision', 'primary', 'procedure', 'purge', 'qualify',
        'query', 'raise', 'range', 'rank', 'read', 'read_write', 'reads', 'real', 'recursive', 'references',
        'regexp', 'reindex', 'release', 'rename', 'repeat', 'replace', 'require', 'resignal', 'restrict', 'return',
        'returning', 'revoke', 'right', 'rlike', 'rollback', 'row', 'row_number', 'rows', 's3', 'savepoint',
        'schema', 'schemas', 'second_microsecond', 'select', 'sensitive', 'separator', 'session_user', 'set', 'show',
        'signal', 'similar', 'slow', 'smallint', 'some', 'spatial', 'specific', 'sql', 'sql_big_result',
        'sql_calc_found_rows', 'sql_small_result', 'sqlexception', 'sqlstate', 'sqlwarning', 'ssl', 'starting',
        'stored', 'straight_join', 'symmetric', 'system', 'table', 'tablesample', 'temp', 'temporary', 'terminated',
        'then', 'tinyblob', 'tinyint', 'tinytext', 'to', 'to_date', 'trailing', 'transaction', 'trigger', 'true',
        'undo', 'union', 'unique', 'unlock', 'unsigned', 'update', 'usage', 'use', 'user', 'using', 'utc_date',
        'utc_time', 'utc_timestamp', 'vacuum', 'values', 'varbinary', 'varchar', 'varcharacter', 'variadic',
        'varying', 'vector', 'verbose', 'view', 'virtual', 'when', 'where', 'while', 'window', 'with', 'write',
        'xor', 'year_month', 'zerofill',
    ];

    public static function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::WORDS, true);
    }
}
