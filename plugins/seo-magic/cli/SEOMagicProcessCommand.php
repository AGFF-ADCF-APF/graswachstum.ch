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
use Grav\Plugin\SEOMagic\SEOGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
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
        include __DIR__ . '/../vendor/autoload.php';

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
            $score_output = $score ? " <yellow>[{$score}%]</yellow>" : "";
            $io->writeln("<$color>$code</$color>$score_output: $url");
        };
        $start = microtime(true);

        [$status, $message] = SEOGenerator::processSEOData($url, $callback, $show_score);

        $end =  number_format(microtime(true) - $start,1);

        $io->writeln('');
        $io->writeln("Completed SEO-Magic processing in {$end}s");

        if ($status === 'success') {
            $io->success($message);
        } else {
            $io->error($message);
        }
    }
}
