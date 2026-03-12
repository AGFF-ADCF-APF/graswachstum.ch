<?php
namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CleanupCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('cleanup')
            ->setDescription('Clean up old revisions')
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Delete revisions older than this many days (overrides configuration)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be deleted without actually deleting'
            )
            ->setHelp('The <info>cleanup</info> command removes old revisions based on the configured age limit');
    }

    protected function serve()
    {
        $this->output->writeln('');
        $this->output->writeln('<magenta>Revisions Pro - Cleanup Tool</magenta>');
        $this->output->writeln('');

        // Get the plugin configuration
        $grav = Grav::instance();
        $config = $grav['config'];
        $pluginConfig = $config->get('plugins.revisions-pro');
        
        if (!$pluginConfig || !$pluginConfig['enabled']) {
            $this->output->writeln('<red>Error: Revisions Pro plugin is not enabled</red>');
            return 1;
        }

        // Check if auto_cleanup is enabled
        if (!$pluginConfig['auto_cleanup']) {
            $this->output->writeln('<yellow>Warning: Automatic cleanup is disabled in plugin configuration</yellow>');
            $this->output->writeln('<yellow>This manual cleanup will still proceed.</yellow>');
            $this->output->writeln('');
        }

        // Get days parameter
        $days = $this->input->getOption('days');
        if ($days === null) {
            $days = $pluginConfig['cleanup_older_than'] ?? 90;
        } else {
            $days = (int) $days;
        }

        $dryRun = $this->input->getOption('dry-run');
        
        $this->output->writeln('Cleaning up revisions older than <info>' . $days . '</info> days...');
        if ($dryRun) {
            $this->output->writeln('<comment>DRY RUN MODE - No files will be deleted</comment>');
        }
        $this->output->writeln('');

        // Initialize revision manager
        require_once __DIR__ . '/../classes/RevisionManager.php';
        $revisionManager = new \Grav\Plugin\RevisionsPro\RevisionManager($grav, $config);

        if ($dryRun) {
            // For dry run, we need to implement a method that shows what would be deleted
            $this->output->writeln('<yellow>Dry run mode is not yet implemented</yellow>');
            $this->output->writeln('<info>Run without --dry-run to perform actual cleanup</info>');
        } else {
            // Perform the cleanup
            $deletedCount = $revisionManager->cleanupOldRevisions($days);
            
            if ($deletedCount > 0) {
                $this->output->writeln('<green>Successfully deleted ' . $deletedCount . ' old revision(s)</green>');
            } else {
                $this->output->writeln('<yellow>No revisions found older than ' . $days . ' days</yellow>');
            }
        }

        $this->output->writeln('');
        $this->output->writeln('<magenta>Cleanup complete!</magenta>');

        return 0;
    }
}