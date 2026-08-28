<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Model;

use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;

/**
 * An append-only audit trail of MCP tool calls.
 *
 * Records the three things worth keeping: who asked, what they asked, and how
 * much came back. Rows are written programmatically via {@see self::track()};
 * the CMS surface is read-only by design — an audit log you can edit isn't one.
 */
class McpRequestLog extends DataObject implements PermissionProvider
{
    private static string $table_name = 'McpRequestLog';

    private static string $singular_name = 'MCP Request Log';

    private static string $plural_name = 'MCP Request Logs';

    private static array $db = [
        // 2. the query
        'Query'       => 'Text',
        // 3. number of results provided
        'ResultCount' => 'Int',
        // 1. who — for unauthenticated agents we only have these two
        'ClientName'  => 'Varchar(255)',
        'IPAddress'   => 'Varchar(45)', // wide enough for IPv6
        // context: cheap to store, valuable when reading the log back
        'ToolName'    => 'Varchar(100)',
    ];

    private static array $indexes = [
        'Created'  => true,
        'ToolName' => true,
    ];

    private static string $default_sort = 'Created DESC';

    /**
     * Retention window in days. Rows older than this are removed by
     * {@see self::prune()}. Set to 0 (or less) to disable pruning entirely.
     */
    private static int $delete_after_days = 100;

    /**
     * Probability (0..1) that any given write() also triggers a prune, for
     * sites without cron. 0 = off (recommended: run the BuildTask on a
     * schedule instead). 0.01 means roughly one in a hundred writes cleans up.
     */
    private static float $self_prune_probability = 0.0;

    private static array $summary_fields = [
        'Created.Nice' => 'When',
        'WhoSummary'   => 'Who',
        'ToolName'     => 'Tool',
        'Query'        => 'Query',
        'ResultCount'  => 'Results',
    ];

    private static array $searchable_fields = [
        'Query',
        'ToolName',
        'ClientName',
    ];

    private static array $casting = [
        'WhoSummary' => 'Varchar',
    ];

    /**
     * Record one MCP request.
     * itself, so callers only pass what they know.
     *
     * @param string      $query       The query or tool arguments to record.
     * @param int         $resultCount Number of results returned to the agent.
     * @param string      $toolName    The tool that was called, e.g. 'search_products'.
     */
    public static function track(
        string $query,
        int $resultCount,
        string $toolName = '',
    ): self {
        $log = self::create();
        $log->Query       = $query;
        $log->ResultCount = $resultCount;
        $log->ToolName    = $toolName;

        $log->ClientName = 'Anonymous';

        if (Controller::has_curr()) {
            $request = Controller::curr()->getRequest();
            $log->IPAddress  = (string) $request->getIP();
            $log->ClientName = (string) ($request->getHeader('User-Agent') ?: 'Anonymous');
        }

        $log->write();

        return $log;
    }

    public function providePermissions(): array
    {
        return [
            'VIEW_MCP_REQUEST_LOG' => [
                'name'     => 'View MCP request logs',
                'category' => 'MCP',
            ],
            'DELETE_MCP_REQUEST_LOG' => [
                'name'     => 'Delete MCP request logs',
                'category' => 'MCP',
            ],
        ];
    }

    public function canView($member = null): bool
    {
        return Permission::checkMember($member, 'VIEW_MCP_REQUEST_LOG')
            || Permission::checkMember($member, 'ADMIN');
    }

    /**
     * Read-only in the CMS. self::track() writes rows directly and is not
     * gated by this — canCreate governs the UI, not programmatic write().
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return Permission::checkMember($member, 'DELETE_MCP_REQUEST_LOG')
            || Permission::checkMember($member, 'ADMIN');
    }


    /**
     * Delete every log older than the retention window. Returns the number
     * removed. A retention of 0 or less disables pruning and returns 0.
     *
     * Uses one DELETE rather than per-row ORM deletes: this model carries no
     * versioning and no relations to cascade, so the fast path is safe here.
     *
     * @param int|null $days Override the configured window for this call.
     */
    public static function prune(?int $days = null): int
    {
        $days = $days ?? (int) static::config()->get('delete_after_days');
        if ($days <= 0) {
            return 0;
        }

        $threshold = date(
            'Y-m-d H:i:s',
            DBDatetime::now()->getTimestamp() - ($days * 86400)
        );

        $stale = static::get()->filter('Created:LessThan', $threshold);
        $count = (int) $stale->count();

        if ($count > 0) {
            DB::prepared_query(
                sprintf('DELETE FROM "%s" WHERE "Created" < ?', static::singleton()->baseTable()),
                [$threshold]
            );
        }

        return $count;
    }
}
