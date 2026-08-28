<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use Sunnysideup\EcommerceMCP\Model\McpRequestLog;

/**
 * Deletes MCP request logs past their retention window.
 *
 * Run from cron:
 *   0 3 * * * /path/to/site/vendor/bin/sake dev/tasks/prune-mcp-request-logs
 *
 * Or in the browser (admin only): /dev/tasks/prune-mcp-request-logs
 * Pass ?days=N to override the configured window for a single run.
 */
class PruneMcpRequestLogsTask extends BuildTask
{
    private static string $segment = 'prune-mcp-request-logs';

    protected $title = 'Prune MCP request logs';

    protected $description =
        'Deletes MCP request log entries older than the retention window '
        . '(McpRequestLog.delete_after_days, default 100). '
        . 'Pass ?days=N to override for a single run.';

    public function run($request)
    {
        $override = $request->getVar('days');
        $days = ($override !== null && $override !== '')
            ? (int) $override
            : (int) McpRequestLog::config()->get('delete_after_days');

        if ($days <= 0) {
            DB::alteration_message('Pruning is disabled (delete_after_days <= 0). Nothing to do.');

            return;
        }

        $deleted = McpRequestLog::prune($days);

        DB::alteration_message(sprintf(
            'Deleted %d MCP request log(s) older than %d day(s).',
            $deleted,
            $days
        ));
    }



}
