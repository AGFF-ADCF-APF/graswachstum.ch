<?php

/**
 * @package    Grav\Plugin\SeoMagic
 *
 * @copyright  Copyright (C) 2014 - 2019 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Plugin\SeoMagicPlugin;
use Grav\Plugin\SEOMagic\SEOData;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class SEOMagicLinkCheckerCommand
 *
 * @package Grav\Console\Cli
 */
class SEOMagicLinkCheckerCommand extends ConsoleCommand
{
    protected SymfonyStyle $io;
    protected bool $showAll = false;
    protected int $totalLinks = 0;
    protected int $brokenLinks = 0;
    protected int $pagesProcessed = 0;
    protected int $pagesWithBroken = 0;

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setName('link-checker')
            ->addArgument(
                'url',
                InputArgument::OPTIONAL,
                'Optional URL of sitemap'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Show all links, not just broken ones'
            )
            ->setDescription('Link Checker for SEO Magic')
            ->setHelp('The <info>link-checker</info> command will test pages for invalid links');
    }

    /**
     * @return int
     */
    protected function serve(): int
    {
        $grav = Grav::instance();
        $this->io = new SymfonyStyle($this->input, $this->output);

        // Initialize Plugins
        $this->initializePlugins();

        $this->io->title('SEO-Magic Link Checker');
        $this->io->newLine();

        $url = $this->input->getArgument('url');
        $this->showAll = $this->input->getOption('all');

        // Find the SeoMagicPlugin instance
        $plugins = $grav['plugins'] ?? null;
        $pluginInstance = null;
        if (is_iterable($plugins)) {
            foreach ($plugins as $instance) {
                if ($instance instanceof SeoMagicPlugin) {
                    $pluginInstance = $instance;
                    break;
                }
            }
        }

        if (!$pluginInstance instanceof SeoMagicPlugin) {
            $this->io->error('SEO-Magic plugin is not loaded.');
            return 1;
        }

        $sitemapUrl = $url ? Utils::url($url, true) : null;
        $options = [
            'show_score' => false,
            'links_only_mode' => true,
            'update_status' => false,
        ];
        if (!empty($sitemapUrl)) {
            $options['sitemap_url'] = $sitemapUrl;
        }

        $start = microtime(true);

        try {
            $result = $pluginInstance->runScan('full', function ($processed, $total, $route, $processedUrl, $code, $linkStatuses) {
                $this->displayPageLinks($route, $processedUrl, $code, $linkStatuses);
            }, $options);
        } catch (\Throwable $e) {
            $this->io->error($e->getMessage());
            return 1;
        }

        $end = number_format(microtime(true) - $start, 1);

        // Summary
        $this->io->newLine();
        $this->io->writeln('<info>═══════════════════════════════════════</info>');
        $this->io->writeln('<info>Summary</info>');
        $this->io->writeln('<info>═══════════════════════════════════════</info>');
        $this->io->writeln("Pages processed: <white>{$this->pagesProcessed}</white>");
        $this->io->writeln("Total links checked: <white>{$this->totalLinks}</white>");
        if ($this->brokenLinks > 0) {
            $this->io->writeln("Broken links found: <red>{$this->brokenLinks}</red>");
            $this->io->writeln("Pages with broken links: <red>{$this->pagesWithBroken}</red>");
        } else {
            $this->io->writeln("Broken links found: <green>0</green>");
        }
        $this->io->writeln("Completed in: <white>{$end}s</white>");
        $this->io->newLine();

        if (!empty($result['status'])) {
            $this->io->success($result['message'] ?? 'Link checking completed');
            return 0;
        }

        $this->io->error($result['message'] ?? 'Link checking failed');
        return 1;
    }

    /**
     * Display links for a single page
     */
    protected function displayPageLinks(string $route, string $url, int $code, $linkStatuses): void
    {
        $this->pagesProcessed++;

        // In links_only_mode, the callback receives link statuses as the score parameter
        if (!is_array($linkStatuses) || empty($linkStatuses)) {
            // No links on this page or page fetch failed
            if ($code !== 200) {
                $this->io->writeln("<red>$code</red>: $url");
                $this->io->writeln("  <red>Failed to fetch page</red>");
                $this->io->newLine();
            }
            return;
        }

        $lines = [];
        $pageTotal = 0;
        $pageBad = 0;

        foreach ($linkStatuses as $link => $status) {
            if (empty($link)) {
                continue;
            }

            $statusCode = $status['status'] ?? 0;
            $pageTotal++;
            $this->totalLinks++;

            // Determine color based on status code
            if ($statusCode >= 200 && $statusCode < 300) {
                $color = 'green';
            } elseif ($statusCode >= 300 && $statusCode < 400) {
                $color = 'yellow';
            } else {
                $pageBad++;
                $this->brokenLinks++;
                $color = 'red';
            }

            // Only show bad links unless --all flag is set
            if ($statusCode >= 400 || $this->showAll) {
                $statusOutput = '';
                if (isset($status['status_msg'])) {
                    $statusOutput = '[' . $status['status_msg'] . '] ';
                } elseif (isset($status['count']) && is_int($status['count']) && $status['count'] > 1) {
                    $statusOutput = '[attempt ' . $status['count'] . '] ';
                }

                $text = isset($status['text']) ? trim(preg_replace('/\s\s+/', ' ', str_replace("\n", " ", $status['text']))) : '';
                $message = isset($status['message']) ? " - <white>{$status['message']}</white>" : '';
                $lines[] = "  <$color>$statusCode</$color> $statusOutput<cyan>$link</cyan> $text$message";
            }
        }

        // Only output if there are broken links or --all flag is set
        if ($pageBad > 0 || ($this->showAll && $pageTotal > 0)) {
            $this->io->writeln("<yellow>$url</yellow>");
            $this->io->writeln("<white>$pageTotal links found</white>");
            if ($pageBad > 0) {
                $this->pagesWithBroken++;
                $this->io->writeln("<red>$pageBad broken links</red>");
            }
            foreach ($lines as $line) {
                $this->io->writeln($line);
            }
            $this->io->newLine();
        } elseif ($pageBad === 0 && $pageTotal > 0) {
            // Just show a brief status for pages with no broken links
            $this->io->writeln("<green>✓</green> $url <white>($pageTotal links)</white>");
        }
    }
}
