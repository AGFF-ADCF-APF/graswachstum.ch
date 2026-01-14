<?php

/**
 * @package    Grav\Plugin\SeoMagic
 *
 * @copyright  Copyright (C) 2014 - 2019 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Plugin\Console;

use Grav\Common\Filesystem\Folder;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class WarmCacheCommand
 *
 * @package Grav\Console\Cli
 */
class SEOMagicPurgeCommand extends ConsoleCommand
{

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setName('purge')
            ->setDescription('Purge all SEO Magic data')
            ->setHelp('The <info>purge</info> command will purge all the SEO Magic data stored in user-data://seo-magic folder')
        ;
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        $io = new SymfonyStyle($this->input, $this->output);

        $io->title('Purge SEO Data');
        $io->newLine();

        $data_path = 'user-data://seo-magic';

        Folder::delete($data_path);
        Folder::create($data_path);

        $io->success("Deleted all SEO Magic data in user-data://seo-magic folder");

        return 0;
    }
}
