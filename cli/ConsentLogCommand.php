<?php

/**
 * @package    Grav\Plugin\Consent
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Consent\ConsentLog;
use Symfony\Component\Console\Input\InputOption;

/**
 * `bin/plugin consent log` — read, export and prune the consent log.
 *
 * The same three things the admin page offers, for people who reach for a
 * terminal. Handy when somebody asks for consent records by email and the
 * fastest answer is a CSV redirected to a file.
 */
class ConsentLogCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('log')
            ->setDescription('Read, export or prune the consent decision log')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Window for the summary, in days', '30')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many records to list', '20')
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Write every record as CSV to stdout')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Delete records past the retention window')
            ->setHelp(
                "The <info>log</info> command reads the consent decision log.\n\n"
                . "  bin/plugin consent log                 summary plus the most recent decisions\n"
                . "  bin/plugin consent log --csv > out.csv every record, as CSV\n"
                . "  bin/plugin consent log --prune         drop records past the retention window\n"
            );
    }

    protected function serve(): int
    {
        $io = $this->getIO();
        $config = (array)Grav::instance()['config']->get('plugins.consent.log', []);
        $log = new ConsentLog($config);

        if (!$log->enabled()) {
            $io->warning('Recording is switched off (plugins.consent.log.backend: none), so there is nothing to read.');

            return 0;
        }

        if ($this->input->getOption('prune')) {
            $removed = $log->prune();
            $io->success(sprintf('Pruned %d log file%s.', $removed, $removed === 1 ? '' : 's'));

            return 0;
        }

        if ($this->input->getOption('csv')) {
            $this->writeCsv($log);

            return 0;
        }

        $days = max(1, (int)$this->input->getOption('days'));
        $stats = $log->stats($days);

        $io->title('Consent log');
        $io->text(sprintf('<info>%d</info> decision%s in the last %d days', $stats['total'], $stats['total'] === 1 ? '' : 's', $days));

        if ($stats['total'] > 0) {
            $rows = [];
            foreach ($stats['category_rates'] as $id => $rate) {
                $rows[] = [$id, $stats['category_counts'][$id] ?? 0, $rate . '%'];
            }
            $io->table(['Category', 'Allowed', 'Rate'], $rows);

            $methods = [];
            foreach ($stats['methods'] as $method => $count) {
                $methods[] = [$method, $count];
            }
            $io->table(['Choice', 'Count'], $methods);
        }

        $limit = max(1, (int)$this->input->getOption('limit'));
        $result = $log->query([], 1, $limit);

        if ($result['records'] === []) {
            $io->note('No records yet. They appear once visitors start answering the banner.');

            return 0;
        }

        $io->section(sprintf('Most recent %d of %d', count($result['records']), $result['total']));
        $rows = [];
        foreach ($result['records'] as $record) {
            $rows[] = [
                date('Y-m-d H:i', (int)($record['created'] ?? 0)),
                (string)($record['method'] ?? ''),
                implode(', ', (array)($record['categories'] ?? [])),
                (string)($record['url'] ?? ''),
            ];
        }
        $io->table(['When', 'Choice', 'Allowed', 'Page'], $rows);

        return 0;
    }

    private function writeCsv(ConsentLog $log): void
    {
        $handle = fopen('php://stdout', 'wb');
        fputcsv($handle, ['id', 'created', 'iso8601', 'version', 'method', 'categories', 'services', 'page', 'lang', 'gpc', 'ip_hash', 'ua_hash']);

        $result = $log->query([], 1, PHP_INT_MAX);
        foreach ($result['records'] as $record) {
            fputcsv($handle, [
                (string)($record['id'] ?? ''),
                (string)($record['created'] ?? ''),
                date('c', (int)($record['created'] ?? 0)),
                (string)($record['version'] ?? ''),
                (string)($record['method'] ?? ''),
                implode(' ', (array)($record['categories'] ?? [])),
                implode(' ', (array)($record['services'] ?? [])),
                (string)($record['url'] ?? ''),
                (string)($record['lang'] ?? ''),
                !empty($record['gpc']) ? 'yes' : 'no',
                (string)($record['ip_hash'] ?? ''),
                (string)($record['ua_hash'] ?? ''),
            ]);
        }

        fclose($handle);
    }
}
