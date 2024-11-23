<?php

/**
 * @package    Grav\Plugin\SeoMagic
 *
 * @copyright  Copyright (C) 2014 - 2019 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\SEOMagic\SEOGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class WarmCacheCommand
 *
 * @package Grav\Console\Cli
 */
class SEOMagicLinkCheckerCommand extends ConsoleCommand
{

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
                InputOption::VALUE_NONE
            )
            ->setDescription('Link Checker for SEO Magic')
            ->setHelp('The <info>link-checker</info> command will test pages for invalid links')
        ;
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        $io = new SymfonyStyle($this->input, $this->output);

        $io->title('SEO-Magic Link Checker');
        $io->newLine();

        $url = $this->input->getArgument('url');
        $input = $this->input;


        $callback = function ($code, $url, $statuses = []) use ($io, $input) {
            $show_all = $input->getOption('all');

            $lines = [];
            $total = $bad = 0;

            foreach ($statuses as $link => $status) {
                $status_code = $status['status'];
                if (empty($link)) {
                    continue;
                }
                $total ++;
                if ($status_code >= 200 && $status_code < 300) {
                    $color = 'green';
                } elseif ($status_code >= 300 && $status_code < 400) {
                    $color = 'yellow';
                } else {
                    $bad++;
                    $color = 'red';
                }
                if ($status_code >= 400 || $show_all) {
                    $count =  $status['count'];

                    $status_output = '';
                    if (isset($status['status_msg'])) {
                        $status_output = '['.$status['status_msg'] . '] ';
                    } elseif (is_int($count) && $count > 1) {
                        $status_output = '[attempt ' . $count . '] ';
                    }

                    $text = trim(preg_replace('/\s\s+/', ' ', str_replace("\n", " ", ($status['text']) ?? '')));
                    $lines[] = " -> <$color>$status_code</$color> $status_output<cyan>$link</cyan> " . $text . (isset($status['message']) ? " - <white>{$status['message']}</white>" : '');
                }
            }
            $io->writeln("<yellow>$url</yellow>");
            $io->writeln("<white>$total links found on this page</white>");
            if ($bad > 0) {
                $io->writeln("<red>$bad broken links found</red>");
            }
            foreach ($lines as $line) {
                $io->writeln($line);
            }
            $io->writeln('');

        };
        $start = microtime(true);

        [$status, $message] = SEOGenerator::processSEOData($url, $callback, false, true);

        $end =  number_format(microtime(true) - $start,1);

        $io->writeln('');
        $io->writeln("Completed Link Checking in {$end}s");

        if ($status === 'success') {
            $io->success($message);
        } else {
            $io->error($message);
        }

    }
}
