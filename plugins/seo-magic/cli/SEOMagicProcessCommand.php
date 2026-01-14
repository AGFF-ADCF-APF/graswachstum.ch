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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class WarmCacheCommand
 *
 * @package Grav\Console\Cli
 */
class SEOMagicProcessCommand extends ConsoleCommand
{

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setName('process')
            ->addArgument(
                'url',
                InputArgument::OPTIONAL,
                'Optional URL of sitemap'
            )
            ->setDescription('Process SEOMagic data from CLI')
            ->setHelp('The <info>process</info> command will process all the pages referenced in the sitemap and generate SEO data')
        ;
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        $grav = Grav::instance();
        $io = new SymfonyStyle($this->input, $this->output);

        // Initialize Plugins
        $this->initializePlugins();

        $io->title('Processing Pages for SEO Data');
        $io->newLine();

        $url = $this->input->getArgument('url');
        $show_score = true;

        $callback = function ($code, $url, $score = null) use ($io) {
            $color = $code === 200 ? 'green' : 'red';
            $score_output = is_numeric($score) ? " <yellow>[" . (int)$score . "%]</yellow>" : "";
            $io->writeln("<$color>$code</$color>$score_output: $url");
        };
        $start = microtime(true);
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
            $io->error('SEO-Magic plugin is not loaded.');
            return 1;
        }

        $sitemapUrl = $url ? Utils::url($url, true) : null;
        $options = [
            'show_score' => $show_score,
            'update_status' => false,
        ];
        if (!empty($sitemapUrl)) {
            $options['sitemap_url'] = $sitemapUrl;
        }

        try {
            $result = $pluginInstance->runScan('full', function ($processed, $total, $route, $processedUrl, $code, $score) use ($callback) {
                $callback($code, $processedUrl, $score);
            }, $options);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 1;
        }

        $end = number_format(microtime(true) - $start, 1);

        $io->writeln('');
        $io->writeln("Completed SEO-Magic processing in {$end}s");

        if (!empty($result['status'])) {
            $io->success($result['message'] ?? 'Scan completed');
            return 0;
        }

        $io->error($result['message'] ?? 'Scan failed');
        return 1;
    }
}
